<?php

declare(strict_types=1);

namespace Whisp;

use Psr\Log\LoggerInterface;
use Socket;
use Whisp\Concerns\WritesLogs;
use Whisp\Enums\DisconnectReason;
use Whisp\Enums\MessageType;
use Whisp\Enums\TerminalMode;
use Whisp\Values\WinSize;

class Connection
{
    use WritesLogs;

    private Socket $socket;

    private KexNegotiator $kexNegotiator;

    private ServerHostKey $serverHostKey;

    private PacketHandler $packetHandler;

    // Needed for key exchange negotiation, and for actual encryption/decryption
    private ?string $clientVersion = null;

    private string $serverVersion = 'SSH-2.0-Whisp_0.1.0';

    private string $inputBuffer = '';

    /**
     * @var Channel[]
     */
    private array $activeChannels = [];

    private bool $authenticationComplete = false;

    private array $apps = [];

    private int $connectionId;

    private int $maxPacketSize = 1024 * 1024; // This gets updated when the client opens a channel

    private int $disconnectInactivitySeconds = 60; // Disconnect after 60 seconds of no data (from client or PTY)

    public \DateTimeImmutable $connectedAt;

    public \DateTimeImmutable $lastActivity;

    private string $requestedApp = 'default';

    private bool $running = true;

    private string $clientIp = '';

    private ?string $username = null;

    /** @var resource */
    private $stream;

    private ?string $sessionId = null;

    private PublicKeyValidator $publicKeyValidator;

    private ?string $authenticatedPublicKey = null;

    private ?string $lastAuthMethod = null;

    /**
     * @param  resource  $stream  The stream resource
     */
    public function __construct(Socket $socket)
    {
        $this->connectedAt = new \DateTimeImmutable;
        $this->lastActivity = new \DateTimeImmutable;
        $this->socket = $socket;
        $this->publicKeyValidator = new PublicKeyValidator(new \Whisp\Loggers\NullLogger);
        $this->packetHandler(new PacketHandler($socket));
        $this->logger(new \Whisp\Loggers\NullLogger);
        $this->createStream($socket);
    }

    public function logger(LoggerInterface $logger): self|LoggerInterface
    {
        if (is_null($logger)) {
            return $this->logger;
        }

        $this->logger = $logger;
        $this->publicKeyValidator->setLogger($logger);
        $this->packetHandler->setLogger($logger);

        return $this;
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $messagePrepend = strtoupper(sprintf('[%s #%d]', str_replace('Whisp\\', '', $this::class), $this->connectionId));
        $this->logger->log($level, $messagePrepend.' '.$message, $context);
    }

    public function packetHandler(?PacketHandler $packetHandler = null): self|PacketHandler
    {
        if (is_null($packetHandler)) {
            return $this->packetHandler;
        }

        $this->packetHandler = $packetHandler;

        return $this;
    }

    public function connectionId(?int $id = null): self|int
    {
        if (is_null($id)) {
            return $this->connectionId;
        }

        $this->connectionId = $id;

        return $this;
    }

    public function clientIp(?string $ip = null): self|string
    {
        if (is_null($ip)) {
            return $this->clientIp;
        }

        $this->clientIp = $ip;

        return $this;
    }

    public function apps(?array $apps = null): self|array
    {
        if (is_null($apps)) {
            return $this->apps;
        }

        $this->apps = $apps;

        return $this;
    }

    public function serverHostKey(?ServerHostKey $serverHostKey = null): self|ServerHostKey
    {
        if (is_null($serverHostKey)) {
            return $this->serverHostKey;
        }

        $this->serverHostKey = $serverHostKey;

        return $this;
    }

    public function handle(): void
    {
        $this->info(sprintf('Handling connection #%d with %d apps: %s', $this->connectionId, count($this->apps), array_key_exists('default', $this->apps) ? 'default' : 'no default'));
        $selectTimeoutInMs = 30;

        // Main event loop for this connection
        while ($this->running) {
            if (! is_resource($this->stream)) {
                $this->debug('Stream is not a resource, breaking');
                $this->running = false;
                break;
            }

            $read = $this->setupStreamSelection();

            if (empty($read)) {
                $this->debug('No valid streams to read from, all done.');
                $this->running = false;
                break;
            }

            $continue = $this->performStreamSelection($read, $selectTimeoutInMs);
            if ($continue === false) {
                $this->debug('Stream selection failed, breaking');
                $this->running = false;
                break;
            }

            // Try again
            if ($continue === null) {
                $this->debug('Stream selection failed, trying again');

                continue;
            }

            $this->handleStreamData($read);
            $this->cleanupClosedChannels();

            $inactiveSeconds = $this->lastActivity->diff(new \DateTimeImmutable)->s;
            if ($inactiveSeconds > $this->disconnectInactivitySeconds) {
                $this->info("Connection inactive for {$inactiveSeconds} seconds, disconnecting");
                $this->disconnect('Connection inactive for too long');
            }
        }

        // Clean up
        $this->cleanup();
    }

    /**
     * Set up the streams we want to read from
     *
     * @return array Array of streams to read from
     */
    private function setupStreamSelection(): array
    {
        $read = [];
        $write = $except = [];

        // Read from the SSH client and all active channels
        if (is_resource($this->stream)) {
            $read[] = $this->stream;
        }

        foreach ($this->activeChannels as $channel) {
            if ($pty = $channel->getPty()) {
                $master = $pty->getMaster();
                if (is_resource($master)) {
                    $read[] = $master;
                }
            }
        }

        return $read;
    }

    /**
     * Perform stream selection with timeout
     *
     * @param  array  $read  Array of streams to read from
     * @param  int  $selectTimeoutInMs  Timeout in milliseconds
     * @return bool|null Whether to continue the main loop, or null if we should try again
     */
    private function performStreamSelection(array &$read, int $selectTimeoutInMs): ?bool
    {
        $write = $except = [];
        $result = @stream_select($read, $write, $except, 0, $selectTimeoutInMs * 1000);
        $lastError = ($result === false) ? pcntl_get_last_error() : null;

        if ($result === false) {
            // Check if it's just an interrupted system call
            $this->debug('stream_select result: '.$result.', last error: '.var_export($lastError, true));
            if ($lastError === PCNTL_EINTR) {
                $this->debug('stream_select interrupted EINTR, retrying...');

                return null; // Try again
            }

            if ($lastError === PCNTL_ECHILD) {
                $this->debug('stream_select failed, ECHILD, should happen once on command exiting, and that\'s fine: '.error_get_last()['message']);

                return null; // Try again
            }

            $this->debug('stream_select failed: '.error_get_last()['message']);
            $this->running = false;

            return false;
        }

        if ($result === 0) {
            return true;
        }

        return true;
    }

    /**
     * Handle data from the selected streams
     *
     * @param  array  $read  Array of streams with data to read
     */
    private function handleStreamData(array $read): void
    {
        foreach ($read as $stream) {
            if ($stream === $this->stream) {
                $this->handleSshClientData($stream);
            } else {
                $this->handlePtyData($stream);
            }
        }
    }

    /**
     * Handle data from the SSH client stream
     *
     * @param  resource  $stream  The SSH client stream
     */
    private function handleSshClientData($stream): void
    {
        $data = @fread($stream, 8192);
        $meta = stream_get_meta_data($stream);

        if ($data === false) {
            $this->error('Error reading from SSH client stream: '.error_get_last()['message'] ?? '');
            $this->info("Connection #{$this->connectionId} closed by peer");
            $this->running = false;
        } elseif ($data === '') {
            if ($meta['timed_out']) {
                $this->debug('Stream timed out, disconnecting');
                $this->disconnect('Stream timed out');
            }
            $this->debug('Data is empty');
            $this->disconnect('Connection closed');
        } else {
            $this->handleData($data);
            $this->lastActivity = new \DateTimeImmutable;
        }
    }

    /**
     * Handle data from a PTY master stream
     *
     * @param  resource  $stream  The PTY master stream
     */
    private function handlePtyData($stream): void
    {
        foreach ($this->activeChannels as $channel) {
            if ($pty = $channel->getPty()) {
                if ($stream === $pty->getMaster()) {
                    $bytesWritten = $channel->forwardFromPty();
                    if ($bytesWritten > 0) { // We got data, so we're active
                        $this->lastActivity = new \DateTimeImmutable;
                    }
                    break;
                }
            }
        }
    }

    /**
     * Clean up any closed channels
     */
    private function cleanupClosedChannels(): void
    {
        foreach ($this->activeChannels as $channelId => $channel) {
            if ($channel->isClosed()) {
                unset($this->activeChannels[$channelId]);
            }
        }
    }

    private function createStream(Socket $socket): void
    {
        $this->stream = socket_export_stream($socket);
        stream_set_blocking($this->stream, false);
        socket_getpeername($socket, $address);
        $this->clientIp($address);

        stream_set_timeout($this->stream, 2);
    }

    private function cleanup(): void
    {
        // Close all active channels
        array_map(fn (Channel $channel) => $channel->close(), $this->activeChannels);
        $this->activeChannels = [];

        // Close the stream
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        // Close the socket
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    private function writePacked(MessageType $type, mixed $value = null): int|false
    {
        $packet = $this->packetHandler->packValues($type, is_array($value) ? $value : [$value]);

        return $this->write($packet);
    }

    /**
     * @return int|false - number of bytes written or false if failed
     */
    private function write(?string $data): int|false
    {
        if (is_null($data)) {
            return false;
        }

        if (! is_resource($this->stream)) {
            $this->debug('Cannot write to closed stream');

            return false;
        }

        $packet = $this->packetHandler->constructPacket($data);
        if ($packet === false) {
            $this->error('Failed to construct packet');

            return false;
        }

        $result = @fwrite($this->stream, $packet);

        if ($result === false) {
            $error = error_get_last();
            $this->debug('Write failed: '.($error ? $error['message'] : 'unknown error'));

            return false;
        }

        return $result;
    }

    private function handleData(string $data)
    {
        // The only time we get data that isn't an SSH packet is when the client sends its version string
        if (! $this->clientVersion) {
            $this->clientVersion = trim($data);
            fwrite($this->stream, $this->serverVersion."\r\n");
            $this->info("Client version set: {$this->clientVersion}, responded with {$this->serverVersion}");

            return;
        }

        // Append incoming data into a buffer
        $this->inputBuffer .= $data;

        $badPacketCount = 0;
        // Process one complete packet at a time
        while (strlen($this->inputBuffer) >= 4) {
            try {
                // First just read the length
                $packetLength = unpack('N', substr($this->inputBuffer, 0, 4))[1];
                $this->debug("Packet length: {$packetLength}, buffer length: ".strlen($this->inputBuffer));
                $totalNeeded = 4 + $packetLength + ($this->packetHandler->encryptionActive ? 16 : 0); // length + payload + MAC tag if encrypted

                // We probably got bad data that when decrypted is a ridiculous packet size
                if ($totalNeeded > $this->maxPacketSize) {
                    $this->error("Protocol error: packet too large ({$totalNeeded} > {$this->maxPacketSize}), bad packet received");
                    $this->disconnect('Protocol error: packet too large, bad packet received');
                    break;
                }

                // If we don't have the complete packet yet, wait for more data
                if (strlen($this->inputBuffer) < $totalNeeded) {
                    $this->debug("Waiting for complete packet: need {$totalNeeded} bytes, have ".strlen($this->inputBuffer));
                    break;
                }

                // Try to parse the complete packet
                $this->debug('Attempting to parse packet, rekey in progress: '.
                    ($this->packetHandler->rekeyInProgress ? 'yes' : 'no'));

                [$packet, $bytesUsed] = $this->packetHandler->fromData($this->inputBuffer);

                if ($packet === null) {
                    if ($bytesUsed === 0) {
                        $this->debug('Packet parsing returned null with 0 bytes used, likely incomplete packet');
                        break;
                    }

                    // We were sent bad data. We are just going to disconnect, the client is probably bad/misbehaving
                    $this->error("Failed to parse packet, bad packet received. Bytes used: {$bytesUsed}");
                    $badPacketCount++;
                    if ($badPacketCount > 3) {
                        $this->disconnect('Protocol error: too many bad packets');
                    }

                    continue;
                }

                // Remove the processed packet from the buffer
                $this->inputBuffer = substr($this->inputBuffer, $bytesUsed);

                // Handle the packet before processing any more data
                $this->handlePacket($packet);
            } catch (\Exception $e) {
                $this->error('Error processing packet: '.$e->getMessage());
                // On error, skip one byte and try to resync
                $this->inputBuffer = substr($this->inputBuffer, 1);
            }

            // Safety check for buffer size
            if (strlen($this->inputBuffer) > 1048576) { // 1MB limit
                $this->error('Input buffer too large, disconnecting');
                $this->disconnect('Protocol error: buffer overflow');

                return;
            }
        }
    }

    private function handlePacket(Packet $packet)
    {
        $this->debug("Handling packet: {$packet->type->name}, packet length: ".strlen($packet->message));

        // Special debug for NEWKEYS which is crucial for rekeying
        if ($packet->type === MessageType::NEWKEYS) {
            $this->debug('Received NEWKEYS packet during '.
                ($this->packetHandler->rekeyInProgress ? 'rekey process' : 'initial key exchange'));
        }

        match ($packet->type) {
            MessageType::DISCONNECT => $this->handleDisconnect($packet),
            MessageType::KEXINIT => $this->handleKexInit($packet),
            MessageType::KEXDH_INIT => $this->handleKexDHInit($packet),
            MessageType::NEWKEYS => $this->handleNewKeys($packet),
            MessageType::SERVICE_REQUEST => $this->handleServiceRequest($packet),
            MessageType::USERAUTH_REQUEST => $this->handleUserAuthRequest($packet), // 'Can we login please?'
            MessageType::CHANNEL_OPEN => $this->handleChannelOpen($packet), // 'I want to open a channel'
            MessageType::CHANNEL_REQUEST => $this->handleChannelRequest($packet), // 'Lets use this channel for [shell, exec, subsystem, x11, forward, auth-agent, etc..]'
            MessageType::CHANNEL_DATA => $this->handleChannelData($packet), // 'I'm sending you some data' (key press in our case usually)
            MessageType::CHANNEL_EOF => $this->handleChannelEof($packet),
            MessageType::CHANNEL_CLOSE => $this->handleChannelClose($packet),
            MessageType::IGNORE => $this->handleIgnore($packet),
            MessageType::DEBUG => $this->handleDebug($packet),
            MessageType::UNIMPLEMENTED => $this->handleUnimplemented($packet),
            default => $this->info('Unsupported packet type: '.$packet->type->name),
        };

        return $packet;
    }

    private function handleKexInit(Packet $packet)
    {
        // If we've already done an initial key exchange, this is a rekey request
        if ($this->packetHandler->hasCompletedInitialKeyExchange) {
            $this->debug('Received rekey request from client');
            $this->packetHandler->rekeyInProgress = true;
        }

        $this->kexNegotiator = new KexNegotiator($packet, $this->clientVersion, $this->serverVersion);
        $response = $this->kexNegotiator->response();
        $this->write($response);
    }

    /**
     * Diffie Hellman key exchange
     * Lots going on here which enables encryption to work, but crosses through a lot of areas
     */
    private function handleKexDHInit(Packet $packet): void
    {
        if (! $this->kexNegotiator) {
            throw new \Exception('KexNegotiator not initialized');
        }

        if (! isset($this->serverHostKey)) {
            throw new \Exception('Host key not set');
        }

        // Create the Kex object
        $kex = new Kex($packet, $this->kexNegotiator, $this->serverHostKey, $this->logger);

        // CRITICAL: Manage the session ID at the Connection level
        if ($this->sessionId === null) {
            // For the very first key exchange, get the session ID from the Kex object
            // after calling response() which computes it
            $kexResponse = $kex->response();
            $this->sessionId = $kex->sessionId;
            $this->debug('Initial SSH session ID established');
        } else {
            // For rekeys, set the session ID on the Kex object before generating the response
            $kex->sessionId = $this->sessionId;
            $this->debug('Using existing session ID for rekey');
            $kexResponse = $kex->response();
        }

        // Send the response
        $this->write($kexResponse);

        // If we're rekeying, pass the Kex for key derivation but don't set it yet
        if ($this->packetHandler->rekeyInProgress) {
            $this->packetHandler->deriveKeys($kex);
        } else {
            // For initial key exchange, set the Kex immediately
            $this->packetHandler->setKex($kex);
        }
    }

    /**
     * Switch to encrypted mode
     * We need to derive keys from the shared secret :exploding_head:
     * Then tell the packet handler to encrypt/decrypt all packets going forward
     */
    public function handleNewKeys(Packet $packet): void
    {
        if ($this->packetHandler->hasCompletedInitialKeyExchange) {
            // Rekey scenario - send NEWKEYS response
            $this->debug('Rekey in progress - received NEWKEYS from client, sending our NEWKEYS response');
            $this->write(chr(MessageType::NEWKEYS->value));

            // Switch to new keys (only after both sides have sent NEWKEYS)
            $this->debug('Switching to new keys after rekey');
            $this->packetHandler->switchToNewKeys();
            $this->debug('Completed rekey process');
        } else {
            // Initial key exchange
            $this->debug('Initial key exchange - sending NEWKEYS response');
            $this->write(chr(MessageType::NEWKEYS->value));

            $this->debug('Deriving initial encryption keys');
            $this->packetHandler->deriveKeys();

            // Enable encryption and mark initial key exchange as complete
            $this->packetHandler->encryptionActive = true;
            $this->packetHandler->hasCompletedInitialKeyExchange = true;

            $this->debug('Initial encryption established');
        }
    }

    /**
     * Client requests a service - we need to respond with a SERVICE_ACCEPT or SERVICE_DENIED
     * Can be ssh-userauth (RFC 4252) - requested first
     * or ssh-connection (RFC 4254)
     */
    public function handleServiceRequest(Packet $packet)
    {
        [$serviceName] = $packet->extractFormat('%s');
        $this->info("Service request: {$serviceName}");

        // Accept the user auth service
        if ($serviceName === 'ssh-userauth') {
            // Before accepting, send EXT_INFO if supported (RFC 8308)
            $this->sendExtInfo();

            $this->writePacked(MessageType::SERVICE_ACCEPT, 'ssh-userauth');
        }

        // Ignore other services for now (not 100% convinced they're needed)
        $this->authenticationComplete = true;
    }

    /**
     * Send SSH_MSG_EXT_INFO according to RFC 8308
     * This explicitly tells the client which signature algorithms we support for user auth.
     */
    private function sendExtInfo(): void
    {
        $supportedSigAlgs = [
            'ssh-ed25519',
            'rsa-sha2-256',  // RSA + SHA-256 signature algorithm
            'rsa-sha2-512',  // RSA + SHA-512 signature algorithm
            'ssh-rsa',       // For compatibility with older clients that don't recognize rsa-sha2-*
        ];

        $payload = pack('N', 1); // Number of extensions
        $payload .= $this->packetHandler->packString('server-sig-algs');
        $payload .= $this->packetHandler->packString(implode(',', $supportedSigAlgs));

        // Send the raw payload with the correct message type byte prepended
        $this->write(MessageType::chr(MessageType::EXT_INFO).$payload);
        $this->info('Sent EXT_INFO with server-sig-algs: '.implode(',', $supportedSigAlgs));
    }

    /**
     * Should support multiple authentication methods as defined in RFC 4252 (The SSH Authentication Protocol).
     *
     * Common authentication methods include:
     * publickey - Using SSH keys
     * password - Plain password auth
     * keyboard-interactive - Challenge-response
     * hostbased - Host-based authentication
     * none - Used to query available methods or for no-auth scenarios
     */
    public function handleUserAuthRequest(Packet $packet)
    {
        [$username, $service, $method] = $packet->extractFormat('%s%s%s');
        try {
            $this->resolveApp($username);
            $this->requestedApp = $username;
        } catch (\InvalidArgumentException $e) {
            $this->username = $username;
        }

        $this->info("Auth request: user=$username, service=$service, method=$method");

        // Handle the 'none' authentication method
        if ($method === 'none') {
            if (is_null($this->lastAuthMethod)) {
                $this->info('Initial auth request - client querying available methods');
                $this->lastAuthMethod = $method;
                // List all supported methods
                $this->writePacked(MessageType::USERAUTH_FAILURE, [
                    implode(',', ['publickey', 'keyboard-interactive', 'password', 'none']),
                    false,
                ]);

                return;
            }

            $this->info("Client explicitly chose 'none' auth method after trying: {$this->lastAuthMethod}");
            $this->writePacked(MessageType::USERAUTH_SUCCESS);
            $this->authenticationComplete = true;

            return;
        }
        $this->lastAuthMethod = $method;

        // Handle keyboard-interactive auth - accept automatically
        if ($method === 'keyboard-interactive') {
            $this->info("Accepting keyboard-interactive auth for user: {$username}");
            $this->writePacked(MessageType::USERAUTH_SUCCESS);
            $this->authenticationComplete = true;

            return;
        }

        // Handle password auth - accept any password
        if ($method === 'password') {
            // Skip reading the password since we're accepting anything
            $this->info("Accepting password auth for user: {$username}");
            $this->writePacked(MessageType::USERAUTH_SUCCESS);
            $this->authenticationComplete = true;

            return;
        }

        // Authing with public key, we want to add this to our environment variable for apps to use
        // But only once we've verified the signature
        if ($method === 'publickey') {
            $this->info('Received public key auth request');

            // Explicitly parse the publickey request fields according to RFC 4252
            [$hasSignature] = $packet->extractFormat('%b');
            [$keyAlgorithmName] = $packet->extractFormat('%s'); // e.g. rsa-sha2-256
            [$publicKeyBlob] = $packet->extractFormat('%s'); // e.g. ssh-rsa exponent modulus | ssh-ed25519 key (32 bytes) | ecdsa-sha2-nistp256 curve_name key

            $this->info('Parsed pk auth request: hasSig='.($hasSignature ? '1' : '0').", keyAlgo='{$keyAlgorithmName}'");

            if ($hasSignature) {
                $this->info('Request has signature, extracting signature blob');
                [$signatureBlob] = $packet->extractFormat('%s');

                // Validate the signature
                $isValid = $this->publicKeyValidator->validateSignature(
                    keyAlgorithm: $keyAlgorithmName, // Pass the actual key algorithm type
                    keyBlob: $publicKeyBlob,    // Pass the key data blob
                    signatureBlob: $signatureBlob,    // Pass the full signature blob (validator will parse)
                    sessionId: $this->sessionId,
                    username: $username,
                    service: $service
                );
                $isValidString = $isValid ? 'true' : 'false';
                $this->info("Signature validation result: {$isValidString}");

                // They offered a public key, and they successfully validated they have the private key
                if ($isValid) {
                    $this->info("Public key signature validated successfully for user: {$username}");
                    $this->authenticatedPublicKey = $this->publicKeyValidator->getSshKeyFromBlob($publicKeyBlob); // Store the validated key blob
                    $this->writePacked(MessageType::USERAUTH_SUCCESS);
                    $this->authenticationComplete = true;

                    return;
                }

                $this->warning("Public key signature validation failed for user: {$username}");
                $this->writePacked(MessageType::USERAUTH_FAILURE, [['publickey', 'none'], false]);

                return;
            } else {
                // Client is checking if the key is acceptable (no signature provided yet)
                $this->info("Public key provided without signature, sending PK_OK for user: {$username}");
                $this->writePacked(MessageType::USERAUTH_PK_OK, [$keyAlgorithmName, $publicKeyBlob]);

                return;
            }
        }

        // If we reached here, let them through without key authentication
        $this->info('Allowing access without key authentication');
        $this->writePacked(MessageType::USERAUTH_SUCCESS);
        $this->authenticationComplete = true;
    }

    public function handleChannelOpen(Packet $packet)
    {
        // Format: string (channel type) + 3 uint32s (sender channel, window size, max packet)
        [$channelType, $senderChannel, $initialWindowSize, $maxPacketSize] = $packet->extractFormat('%s%u%u%u');
        $this->maxPacketSize = $maxPacketSize;

        $this->info("Channel open request: type=$channelType, sender=$senderChannel, window=$initialWindowSize, max_packet=$maxPacketSize");

        // We'll use the same channel number for simplicity
        $recipientChannel = $senderChannel;

        // Create new channel
        $channel = new Channel($recipientChannel, $senderChannel, $initialWindowSize, $maxPacketSize, $channelType);
        $channel->setConnection($this);
        $channel->setLogger($this->logger);
        $this->activeChannels[$recipientChannel] = $channel;

        // Send channel open confirmation
        return $this->writePacked(MessageType::CHANNEL_OPEN_CONFIRMATION, [$recipientChannel, $senderChannel, $initialWindowSize, $maxPacketSize]);
    }

    public function handleChannelRequest(Packet $packet)
    {
        [$recipientChannel, $requestType, $wantReply] = $packet->extractFormat('%u%s%b');
        $this->info("Channel request: channel=$recipientChannel, type=$requestType, want_reply=$wantReply");

        $channelSuccessReply = $this->packetHandler->packValue(MessageType::CHANNEL_SUCCESS, $recipientChannel);
        $channelFailureReply = $this->packetHandler->packValue(MessageType::CHANNEL_FAILURE, $recipientChannel);

        if (! isset($this->activeChannels[$recipientChannel])) {
            $this->error("Channel {$recipientChannel} not found");
            if ($wantReply) {
                $this->write($channelFailureReply);
            }

            return;
        }

        $channel = $this->activeChannels[$recipientChannel];

        // Handle different request types
        switch ($requestType) {
            case 'pty-req':
                $success = $this->handlePtyRequest($channel, $packet);
                if ($wantReply) {
                    $this->write($success ? $channelSuccessReply : $channelFailureReply);
                }
                break;

            case 'exec':
                // Exec is used to run a command on the server, usually you'd do `ssh server 'tail -f log.log'` say, but of course we only support our registered apps here
                // We also support 2 ways of setting the app: username & exec
                // Because some terminals (Warp) try to run a giant script here to setup cool things, we need to have 'username' routing take priority over 'exec', so if we already have a non-default requestedApp, we'll use that, _otherwise_ we'll try to use this

                if ($this->requestedApp !== 'default') {
                    // We already have a great non-default app so we'll use that
                    $app = $this->requestedApp;
                    $this->info("Received exec request, ignoring and using username app: {$app}");
                } else {
                    [$this->requestedApp] = $packet->extractFormat('%s');
                    $this->info("Received exec request with app: {$this->requestedApp}");
                }

                // Start the command interactively
                $started = $this->startInteractiveCommand($channel, $this->requestedApp);
                if ($wantReply) {
                    $this->write($started ? $channelSuccessReply : $channelFailureReply);
                }
                break;

            case 'shell':
                // For shell requests, start the current requestedApp (which defaults to 'default' but can be overriden by the username)
                $started = $this->startInteractiveCommand($channel, $this->requestedApp);
                if ($wantReply) {
                    $this->write($started ? $channelSuccessReply : $channelFailureReply);
                }
                break;

            case 'window-change':
                $this->handleWindowChange($channel, $packet);
                // No reply needed for window-change
                break;

            case 'env':
                // Client setting an environment variable
                [$name, $value] = $packet->extractFormat('%s%s');
                $this->info("Received env request: name=$name, value=$value");

                $channel->setEnvironmentVariable($name, $value);

                if ($wantReply) {
                    $this->write($channelSuccessReply);
                }
                break;

            case 'signal':
                // Client sent a signal (like Ctrl+C)
                [$signalName] = $packet->extractFormat('%s');
                $this->info("Received signal: {$signalName}");
                break;

            default:
                // Unknown request type
                $this->info("Unhandled channel request type: {$requestType}");
                if ($wantReply) {
                    $this->write($channelFailureReply);
                }
                break;
        }
    }

    /**
     * Handle a pty-req request from the client
     * This sets up the terminal parameters for the PTY
     */
    private function handlePtyRequest(Channel $channel, Packet $packet): bool
    {
        $this->debug('Handling PTY request');

        // Extract terminal parameters
        [$term, $widthChars, $heightRows, $widthPixels, $heightPixels] = $packet->extractFormat('%s%u%u%u%u');

        $this->debug(sprintf(
            'PTY request parameters: term=%s, cols=%d, rows=%d, width_px=%d, height_px=%d',
            $term,
            $widthChars,
            $heightRows,
            $widthPixels,
            $heightPixels
        ));

        // Parse terminal modes
        $modes = [];

        // First get the length of the modes string
        $modesLength = unpack('N', substr($packet->message, $packet->offset, 4))[1];
        $packet->offset += 4;

        if ($modesLength > 0) {
            $this->debug("Found terminal modes string of length: {$modesLength}");

            // Read the modes string
            $modesString = substr($packet->message, $packet->offset, $modesLength);
            $packet->offset += $modesLength;

            // Parse the modes
            $offset = 0;
            while ($offset < strlen($modesString)) {
                // Read opcode (1 byte)
                $opcode = ord($modesString[$offset]);
                $offset++;

                // TTY_OP_END signals end of modes
                if ($opcode === 0) {
                    break;
                }

                // Read value (uint32)
                $value = unpack('N', substr($modesString, $offset, 4))[1];
                $offset += 4;

                $modes[$opcode] = $value;

                // Try to find the enum case for this opcode
                $modeName = 'UNKNOWN';
                foreach (TerminalMode::cases() as $case) {
                    if ($case->value === $opcode) {
                        $modeName = $case->name;
                        break;
                    }
                }

                $this->debug(sprintf('Terminal mode: %s (0x%02X) = %d', $modeName, $opcode, $value));
            }
        }

        try {
            // Store terminal info in the channel
            $channel->setTerminalInfo(
                $term,
                $widthChars,
                $heightRows,
                $widthPixels,
                $heightPixels,
                $modes
            );

            // Create a PTY for this channel if it doesn't exist
            if (! $channel->getPty()) {
                if (! $channel->createPty()) {
                    $this->error('Failed to create PTY');

                    return false;
                }
            }

            return true;
        } catch (\Exception $e) {
            $this->error('Failed to handle PTY request: '.$e->getMessage());
            $this->error('Stack trace: '.$e->getTraceAsString());

            return false;
        }
    }

    /**
     * Handle a window-change request from the client
     * This updates the terminal size in the PTY
     */
    private function handleWindowChange(Channel $channel, Packet $packet): void
    {
        [$widthChars, $heightRows, $widthPixels, $heightPixels] = $packet->extractFormat('%u%u%u%u');

        $this->debug(sprintf(
            'Window change request: cols=%d, rows=%d, width_px=%d, height_px=%d',
            $widthChars,
            $heightRows,
            $widthPixels,
            $heightPixels
        ));

        // Update terminal size
        if ($pty = $channel->getPty()) {
            try {
                $pty->setWindowSize(new WinSize($heightRows, $widthChars, $widthPixels, $heightPixels));
                $this->debug('Window size updated successfully');
            } catch (\Exception $e) {
                $this->error('Failed to update window size: '.$e->getMessage());
            }
        } else {
            $this->warning('Window change request received but no PTY available');
        }
    }

    /**
     * This is a big one. For basic SSH sessions for a TUI everything before here is just setting up the connection.
     * Now we get the actual keypresses. This is where interactivity can happen.
     *
     * Get data/keypresses from the SSH client, forward to the channel's PTY
     */
    public function handleChannelData(Packet $packet)
    {
        [$recipientChannel, $data] = $packet->extractFormat('%u%s');

        if (! isset($this->activeChannels[$recipientChannel])) {
            $this->error("Channel {$recipientChannel} not found");

            return;
        }

        $channel = $this->activeChannels[$recipientChannel];
        $terminalInfo = $channel->getTerminalInfo();

        // Only convert CR to NL if ICRNL mode is enabled
        if ($terminalInfo && isset($terminalInfo->modes[TerminalMode::ICRNL->value])) {
            // Convert CR (0x0D) to NL (0x0A) if it's a single CR
            // This preserves CRLF sequences but converts lone CR to NL
            $data = preg_replace('/\r(?!\n)/', "\n", $data);
        }

        // Forward the data to the command's stdin via the PTY
        $channel->writeToPty($data);
    }

    /**
     * The recipient of this message MUST send back an SSH_MSG_CHANNEL_EOF
     * message unless it has already sent this message for the channel.
     * The channel remains open after this message, and more data may still
     * be sent in the other direction.
     */
    public function handleChannelEof(Packet $packet)
    {
        [$channelId] = $packet->extractFormat('%u');

        if (! isset($this->activeChannels[$channelId])) {
            return;
        }

        $channel = $this->activeChannels[$channelId];
        $channel->markInputClosed();

        // Send EOF back to the client
        $this->writePacked(MessageType::CHANNEL_EOF, $channelId);
    }

    public function handleChannelClose(Packet $packet)
    {
        [$channelId] = $packet->extractFormat('%u');

        if (! isset($this->activeChannels[$channelId])) {
            return;
        }

        $channel = $this->activeChannels[$channelId];

        $channel->close();
        unset($this->activeChannels[$channelId]);

        // Send close back to the client
        $this->writePacked(MessageType::CHANNEL_CLOSE, $channelId);
    }

    private function handleDisconnect(Packet $packet)
    {
        [$reasonCode, $description] = $packet->extractFormat('%u%s');
        $this->running = false;
        $this->disconnect("Client requested disconnect: {$description} (code: {$reasonCode})");
    }

    private function handleIgnore(Packet $packet): void
    {
        // Just ignore this packet, as per RFC
        $this->debug('Received IGNORE message');
    }

    private function handleDebug(Packet $packet): void
    {
        [$alwaysDisplay, $message] = $packet->extractFormat('%b%s');
        $this->debug("Received DEBUG message: $message");
    }

    private function handleUnimplemented(Packet $packet): void
    {
        [$seqNum] = $packet->extractFormat('%u');
        $this->debug("Received UNIMPLEMENTED for sequence number: $seqNum");
    }

    /**
     * Disconnect the SSH connection with a reason
     */
    public function disconnect(string $reason): void
    {
        $this->writePacked(
            MessageType::DISCONNECT,
            [DisconnectReason::DISCONNECT_BY_APPLICATION->value, $reason, 'en']
        );

        $this->info("Initiated disconnect: {$reason}");

        // Close the underlying connection
        $this->stream = null;
        $this->running = false;
    }

    /**
     * Tell the client 'EOF' (end of file), then close the channel
     */
    private function closeChannel(Channel $channel)
    {
        $this->debug('Closing channel #'.$channel->recipientChannel);
        $this->writePacked(MessageType::CHANNEL_CLOSE, $channel->recipientChannel);

        $channel->close();
        unset($this->activeChannels[$channel->recipientChannel]);
    }

    public function writeChannelData(Channel $channel, string $data): int
    {
        $maxChunkSize = $channel->maxPacketSize - 1024; // Leave room for packet overhead

        // Split data into chunks if it exceeds max packet size
        $offset = 0;
        $totalLength = strlen($data);

        while ($offset < $totalLength) {
            $chunk = substr($data, $offset, $maxChunkSize);
            $chunkLength = strlen($chunk);

            $this->writePacked(MessageType::CHANNEL_DATA, [$channel->recipientChannel, $chunk]);

            $offset += $chunkLength;
        }

        return $totalLength;
    }

    /**
     * @return array{string, array<string, string>}
     */
    private function resolveApp(string $app): array
    {
        $resolved = null;
        $params = [];
        foreach ($this->apps as $baseApp => $command) {
            if ($baseApp === $app) {
                return [$baseApp, []];
            }

            // Convert route pattern to regex
            // cursor-party-{room} -> cursor-party-(?<room>[^/]+)
            $pattern = preg_replace('/\{([^}]+)\}/', '(?<$1>[^/]+)', $baseApp);
            $pattern = '/^'.str_replace('/', '\/', $pattern).'$/';

            if (preg_match($pattern, $app, $matches)) {
                // We found a match! Now we can extract the parameters
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $resolved = $baseApp; // Use the base app name for command lookup
                break;
            }
        }

        if ($resolved === null) {
            if (array_key_exists('default', $this->apps)) {
                $resolved = 'default'; // Fallback to the default app if no match is found
            } else {
                throw new \InvalidArgumentException("Unknown app: {$app} and no default app is registered");
            }
        }

        return [$resolved, $params];
    }

    /**
     * Start an interactive command.
     * It's critical that the 'app' is verified here. It must be a valid app.
     * Do _not_ trust the $app here. It comes from the client.
     *
     * @param  Channel  $channel  The channel to start the command on
     * @param  string  $app  The app to start
     * @return bool Whether the command was started successfully
     */
    private function startInteractiveCommand(Channel $channel, string $app): bool
    {
        if (! $channel->getPty()) {
            $this->error('Cannot start command without PTY - interactive terminal required');
            $this->disconnect('Interactive terminal required. Please use: ssh -t');

            return false;
        }
        $params = [];

        try {
            [$app, $params] = $this->resolveApp($app);
        } catch (\InvalidArgumentException $e) {
            // Unsupported app, what's going on like?
            // Warp passes a 200 line script - so 'resolveApp' will fallback to 'default' if available, so we can just error here, as the resolveApp method will fallback to the default app, if it exists
            $this->warning(sprintf('Unknown app: %s, falling back to default app', substr($app, 0, 100)));
            $this->writeChannelData($channel, "\n\033[1;33m⚠️  Warning\033[0m: Unknown app: '{$app}'\n");
            usleep(100000);

            $this->writePacked(MessageType::CHANNEL_FAILURE, $channel->recipientChannel);

            // Close the channel gracefully
            $this->closeChannel($channel);

            return false;
        }

        // Set the client IP as an environment variable
        $channel->setEnvironmentVariable('WHISP_CLIENT_IP', $this->clientIp);
        $channel->setEnvironmentVariable('WHISP_TTY', $channel->getPty()?->getSlaveName() ?? '');
        $channel->setEnvironmentVariable('WHISP_APP', $app);
        $channel->setEnvironmentVariable('WHISP_USERNAME', $this->username ?? '');
        $channel->setEnvironmentVariable('WHISP_CONNECTION_ID', (string) $this->connectionId);

        // Set the validated public key if available
        if ($this->authenticatedPublicKey !== null) {
            $channel->setEnvironmentVariable('WHISP_USER_PUBLIC_KEY', $this->authenticatedPublicKey);
        }

        // Set any extracted parameters as environment variables
        foreach ($params as $paramName => $paramValue) {
            $channel->setEnvironmentVariable('WHISP_PARAM_'.strtoupper($paramName), (string) $paramValue);
        }

        $this->info(sprintf(
            'Set environment variables for connection #%d: WHISP_CLIENT_IP=%s, WHISP_APP=%s, WHISP_TTY=%s, WHISP_USERNAME=%s, params=%s',
            $this->connectionId,
            $this->clientIp,
            $app,
            $channel->getPty()?->getSlaveName() ?? '',
            $this->username ?? '',
            json_encode($params)
        ));

        // Get the base command and add any parameters as arguments
        $command = $this->apps[$app];
        if (! empty($params)) {
            $command .= ' '.implode(' ', array_map('escapeshellarg', $params)); // So we won't pass the param name which isn't ideal, but they'll be in order, so that works for now
        }

        $success = $channel->startCommand($command);
        $this->info("Started interactive command: {$command}");

        if (! $success) {
            $this->error("Failed to start command: {$command}");

            return false;
        }

        return true;
    }

    /**
     * Send exit status for a channel
     *
     * @param  Channel  $channel  The channel to send status for
     * @param  int  $exitCode  The exit code to send
     * @return bool Whether sending the status succeeded
     */
    public function sendExitStatus(Channel $channel, int $exitCode)
    {
        $this->writePacked(
            MessageType::CHANNEL_REQUEST,
            [$channel->recipientChannel, 'exit-status', false, $exitCode]
        );

        $this->closeChannel($channel);
    }
}
