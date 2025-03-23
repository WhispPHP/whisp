<?php

declare(strict_types=1);

namespace Whisp;

use phpseclib3\Crypt\AES;
use Psr\Log\LoggerInterface;
use Socket;
use Whisp\Enums\MessageType;

class PacketHandler
{
    // This will parse the raw data into packets
    // We can then pass the encryption data/IVs/whatever to this class for the entire connection
    // Then we can exctract it from the Connection so that can focus on the application layer

    // Whether encryption is currently active for this connection
    public bool $encryptionActive = false;

    // Initialization Vector (salt) for Client->Server encryption
    public string $encryptIV_CStoS;

    // AES-256 encryption key for Client->Server messages
    public string $encryptKey_CStoS;

    // Initialization Vector (salt) for Server->Client encryption
    public string $encryptIV_StoC;

    // AES-256 encryption key for Server->Client messages
    public string $encryptKey_StoC;

    // Sequence number for Server->Client packets
    public int $packetSeq_StoC = 0;

    // Sequence number for Client->Server packets
    public int $packetSeq_CStoS = 0;

    public Kex $kex;

    private ?AES $encryptor = null;

    private ?AES $decryptor = null;

    /** @var resource */
    private $stream;

    public bool $rekeyInProgress = false;

    private ?array $pendingKeys = null;

    public function __construct(
        public Socket $socket,
        public LoggerInterface $logger,
    ) {
        $this->stream = socket_export_stream($socket);
        stream_set_blocking($this->stream, false);

        if (! sodium_crypto_aead_aes256gcm_is_available()) {
            throw new \RuntimeException('AES-256-GCM not available');
        }
    }

    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    public function setKex(Kex $kex): self
    {
        $this->kex = $kex;

        return $this;
    }

    public function deriveKeys()
    {
        // Pack shared secret as MPInt (confirm no extra leading zeros)
        $K = $this->packMpint($this->kex->sharedSecret);
        $H = $this->kex->exchangeHash;

        // Modified KDF to support extended hashing if needed
        $kdf = function (string $letter, int $needed_length) use ($K, $H): string {
            $output = '';
            $prev_block = '';

            while (strlen($output) < $needed_length) {
                $input = $K.$H.($prev_block ? $prev_block : $letter).$this->kex->sessionId;
                $hash = hash('sha256', $input, true);
                $output .= $hash;
                $prev_block = $hash;
            }

            return substr($output, 0, $needed_length);
        };

        // For aes256-gcm@openssh.com:
        // - We need 32-byte keys for AES-256
        // - We need 12-byte IVs for GCM
        $this->encryptIV_CStoS = $kdf('A', 12);    // Only take 12 bytes for GCM IV
        $this->encryptKey_CStoS = $kdf('C', 32);   // 32 bytes for AES-256 key

        $this->encryptIV_StoC = $kdf('B', 12);     // Only take 12 bytes for GCM IV
        $this->encryptKey_StoC = $kdf('D', 32);    // 32 bytes for AES-256 key

        // Debug key derivation
        $this->logger->debug(sprintf(
            "Key Derivation:\nIV C->S: %s\nKey C->S: %s\nIV S->C: %s\nKey S->C: %s",
            bin2hex($this->encryptIV_CStoS),
            bin2hex($this->encryptKey_CStoS),
            bin2hex($this->encryptIV_StoC),
            bin2hex($this->encryptKey_StoC)
        ));

        // Initialize AES instances with GCM mode
        $this->encryptor = new AES('gcm');
        $this->encryptor->setKey($this->encryptKey_StoC);

        $this->decryptor = new AES('gcm');
        $this->decryptor->setKey($this->encryptKey_CStoS);

        // Debug AES configuration
        $this->logger->debug(sprintf(
            "AES Configuration:\nMode: %s\nKey size: %d bits\nBlock size: %d bytes",
            $this->decryptor->getMode(),
            strlen($this->encryptKey_CStoS) * 8,
            $this->decryptor->getBlockLength() / 8
        ));
    }

    private function getNonce(string $baseIV, int $sequenceNumber): string
    {
        // Start with the complete IV (12 bytes)
        $nonce = $baseIV;

        // Treat last 4 bytes as counter, increment by sequence number
        $counter = unpack('N', substr($baseIV, 8, 4))[1];
        $counter = ($counter + $sequenceNumber) & 0xFFFFFFFF;

        // Replace last 4 bytes with incremented counter
        $nonce[8] = chr(($counter >> 24) & 0xFF);
        $nonce[9] = chr(($counter >> 16) & 0xFF);
        $nonce[10] = chr(($counter >> 8) & 0xFF);
        $nonce[11] = chr($counter & 0xFF);

        return $nonce;
    }

    public function constructPacket(string $payload): string
    {
        // Only check for rekeying if encryption is already active
        if ($this->encryptionActive && ! $this->rekeyInProgress) {
            // $this->initiateRekey();
        }

        if ($this->encryptionActive) {
            $result = $this->constructEncryptedPacket($payload);

            return $result;
        }

        $packetLen = strlen($payload);
        // Calculate padding to make total length a multiple of 8
        // Total length = packetLen + paddingLen + 5 (4 for length + 1 for padding length)
        $paddingLen = 8 - (($packetLen + 5) % 8);
        if ($paddingLen < 4) {
            $paddingLen += 8;
        }

        return pack('N', $packetLen + $paddingLen + 1).
            chr($paddingLen).
            $payload.
            random_bytes($paddingLen);
    }

    public function constructEncryptedPacket(string $payload): string|false
    {
        // The block size for AES is 16 bytes
        $blockSize = 16;

        // Calculate minimum padding needed
        $paddingLength = $blockSize - ((1 + strlen($payload)) % $blockSize);
        if ($paddingLength < 4) {
            $paddingLength += $blockSize;
        }

        // Verify our calculation - total length should be multiple of blockSize
        $packetLength = 1 + strlen($payload) + $paddingLength;
        if ($packetLength % $blockSize !== 0) {
            $this->logger->error("Invalid padding calculation: {$packetLength} is not a multiple of {$blockSize}");

            return false;
        }

        // Pack the length and create packet
        $lengthBytes = pack('N', $packetLength);
        $padding = random_bytes($paddingLength);
        $packet = chr($paddingLength).$payload.$padding;

        // Set the nonce for this packet
        $nonce = $this->getNonce($this->encryptIV_StoC, $this->packetSeq_StoC);
        $this->encryptor->setNonce($nonce);

        // Set AAD before encryption
        $this->encryptor->setAAD($lengthBytes);

        // Encrypt
        $ciphertext = $this->encryptor->encrypt($packet);
        $tag = $this->encryptor->getTag();

        $this->packetSeq_StoC++;

        // Send packet: length + ciphertext + tag
        return $lengthBytes.$ciphertext.$tag;
    }

    public function fromData(string $data): array
    {
        // If encryption is active, parse an encrypted packet.
        if ($this->encryptionActive) {
            $packet = $this->handleEncryptedPacket($data);
            if ($packet === false) {
                // means decryption or parse error
                // return [null, 0] to indicate failure
                return [null, 0];
            }

            // If handleEncryptedPacket succeeded, we know how many bytes we used:
            //   4 bytes for length + $packetLength bytes of ciphertext + 16 bytes for GCM tag.
            if (strlen($data) < 4) {
                return [null, 0];
            }
            $packetLength = unpack('N', substr($data, 0, 4))[1];
            $bytesUsed = 4 + $packetLength + 16;

            return [$packet, $bytesUsed];
        }

        // If encryption isn't active, parse an unencrypted SSH packet.
        // Unencrypted logic typically has: 4 bytes for length + that many payload/padding bytes.
        if (strlen($data) < 4) {
            // Not enough data to even read the packet length
            return [null, 0];
        }

        $packetLength = unpack('N', substr($data, 0, 4))[1];
        if (strlen($data) < 4 + $packetLength) {
            // incomplete
            return [null, 0];
        }

        $packet = Packet::fromData($data);

        $bytesUsed = 4 + $packetLength;

        return [$packet, $bytesUsed];
    }

    public function extractString(string $data, int &$offset): array
    {
        $length = unpack('N', substr($data, $offset, 4))[1];
        $string = substr($data, $offset + 4, $length);
        $offset += 4 + $length;

        return [$string, $offset];
    }

    public function packString(string $str): string
    {
        return pack('N', strlen($str)).$str;
    }

    public function packInteger(int $uint): string
    {
        return pack('N', $uint);
    }

    public function packBool(bool $bool): string
    {
        return $bool ? chr(1) : chr(0);
    }

    public function packNull(mixed $value): string
    {
        return '';
    }

    public function packValue(MessageType $type, mixed $value): string
    {
        return $this->packValues($type, [$value]);
    }

    public function packValues(MessageType $type, array $values = []): string
    {
        $packed = MessageType::chr($type);

        foreach ($values as $value) {
            $type = strtolower(gettype($value));
            $packMethod = 'pack'.ucfirst($type);
            if (method_exists($this, $packMethod)) {
                $packed .= $this->$packMethod($value);
            } else {
                $this->logger->error("No pack method for type: {$type}");
            }
        }

        return $packed;
    }

    private function handleEncryptedPacket(string $data): Packet|false
    {
        // Get the length of the packet from the first 4 bytes
        $lengthBytes = substr($data, 0, 4);
        if (strlen($lengthBytes) !== 4) {
            $this->logger->error('Failed to read length: got '.(strlen($lengthBytes) ?: 0).' bytes');

            return false;
        }

        $packetLength = unpack('N', $lengthBytes)[1];

        $cipherAndTag = substr($data, 4, $packetLength + 16);
        if (strlen($cipherAndTag) !== $packetLength + 16) {
            $this->logger->error(sprintf(
                'Failed to read complete ciphertext+tag: expected %d bytes, got %d',
                $packetLength + 16,
                strlen($cipherAndTag)
            ));

            return false;
        }

        $ciphertext = substr($cipherAndTag, 0, $packetLength);
        $tag = substr($cipherAndTag, $packetLength);

        // Set the nonce for this packet
        $nonce = $this->getNonce($this->encryptIV_CStoS, $this->packetSeq_CStoS);
        $this->decryptor->setNonce($nonce);

        // Set AAD before decryption
        $this->decryptor->setAAD($lengthBytes);
        $this->decryptor->setTag($tag);

        // Decrypt
        try {
            $plaintext = $this->decryptor->decrypt($ciphertext);
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                'Decryption failed for packet seq %d:\nKey: %s\nNonce: %s\nError: %s',
                $this->packetSeq_CStoS,
                bin2hex($this->encryptKey_CStoS),
                bin2hex($nonce),
                $e->getMessage()
            ));

            return false;
        }

        if ($plaintext === false) {
            $this->logger->error(sprintf(
                'Decryption failed for packet seq %d:\nKey: %s\nNonce: %s',
                $this->packetSeq_CStoS,
                bin2hex($this->encryptKey_CStoS),
                bin2hex($nonce)
            ));

            return false;
        }

        $this->packetSeq_CStoS++;

        $paddingLength = ord($plaintext[0]);

        return new Packet(substr($plaintext, 1, strlen($plaintext) - $paddingLength - 1));
    }

    private function packMpint(string $bignum): string
    {
        // Remove ALL leading zeros first
        $bignum = ltrim($bignum, "\0");

        // Add single zero byte only if MSB is set
        if (strlen($bignum) > 0 && (ord($bignum[0]) & 0x80)) {
            $bignum = "\0".$bignum;
        }

        // Pack length and value
        return pack('N', strlen($bignum)).$bignum;
    }

    private function initiateRekey(): void
    {
        $this->rekeyInProgress = true;
        $this->logger->debug('Initiating rekey after '.$this->packetSeq_StoC.' packets');

        // Generate new keys
        $this->deriveKeys();

        // Store the new keys until we receive NEWKEYS
        $this->pendingKeys = [
            'encrypt_iv_cstos' => $this->encryptIV_CStoS,
            'encrypt_key_cstos' => $this->encryptKey_CStoS,
            'encrypt_iv_stoc' => $this->encryptIV_StoC,
            'encrypt_key_stoc' => $this->encryptKey_StoC,
        ];

        // Send KEXINIT to start rekeying
        // $kexInit = $this->kex->generateKexInit();
        // $this->constructPacket(MessageType::chr(MessageType::KEXINIT).$kexInit);
    }

    public function switchToNewKeys(): void
    {
        if (! $this->rekeyInProgress || ! $this->pendingKeys) {
            return;
        }

        // Switch to the new keys we generated earlier
        $this->encryptIV_CStoS = $this->pendingKeys['encrypt_iv_cstos'];
        $this->encryptKey_CStoS = $this->pendingKeys['encrypt_key_cstos'];
        $this->encryptIV_StoC = $this->pendingKeys['encrypt_iv_stoc'];
        $this->encryptKey_StoC = $this->pendingKeys['encrypt_key_stoc'];

        // Reinitialize AES instances with new keys
        $this->encryptor = new AES('gcm');
        $this->encryptor->setKey($this->encryptKey_StoC);

        $this->decryptor = new AES('gcm');
        $this->decryptor->setKey($this->encryptKey_CStoS);

        // Reset state
        $this->pendingKeys = null;
        $this->rekeyInProgress = false;
        $this->packetSeq_StoC = 0;
        $this->packetSeq_CStoS = 0;

        $this->logger->debug('Switched to new keys after rekeying');
    }
}
