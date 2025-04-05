<?php

declare(strict_types=1);

namespace Whisp;

use Psr\Log\LoggerInterface;
use Whisp\Enums\MessageType;
use Whisp\Loggers\NullLogger;

class Kex
{
    public string $serverKexInit;

    public string $sharedSecret;

    public ?string $sessionId = null;

    public string $exchangeHash;

    private ?LoggerInterface $logger;

    /**
     * We are explicitly _only_ supporting Curve25519 / aes256-gcm@openssh.com for now
     * I'll figure out other options later
     */
    public function __construct(
        public Packet $packet,
        public KexNegotiator $kexNegotiator,
        public ServerHostKey $serverHostKey,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger;
    }

    /**
     * Diffie Hellman key exchange response
     */
    public function response(): string
    {
        // Extract client's public key (32 bytes after 4-byte length)
        $clientPublicKeyLength = unpack('N', substr($this->packet->message, 0, 4))[1];
        $clientPublicKey = substr($this->packet->message, 4, $clientPublicKeyLength);

        // Generate our Curve25519 keypair for key exchange
        $curveKeyPair = sodium_crypto_box_keypair();
        $curve25519Private = sodium_crypto_box_secretkey($curveKeyPair);
        $curve25519Public = sodium_crypto_box_publickey($curveKeyPair);

        // Use the persistent host key instead of generating a new one
        $ed25519Private = $this->serverHostKey->getPrivateKey();
        $ed25519Public = $this->serverHostKey->getPublicKey();

        // Compute shared secret
        $this->sharedSecret = sodium_crypto_scalarmult($curve25519Private, $clientPublicKey);

        // Format the host key blob
        $hostKeyBlob = $this->packString('ssh-ed25519').$this->packString($ed25519Public);

        // Create exchange hash
        $exchangeHash = hash('sha256', implode('', [
            $this->packString($this->kexNegotiator->clientVersion),
            $this->packString($this->kexNegotiator->serverVersion),
            $this->packString($this->kexNegotiator->clientKexInit),      // Client's KEXINIT
            $this->packString($this->kexNegotiator->serverKexInit),        // Our KEXINIT
            $this->packString($hostKeyBlob),             // Host key blob
            $this->packString($clientPublicKey),         // Client's ephemeral public key
            $this->packString($curve25519Public),        // Our ephemeral public key
            $this->packMpint($this->sharedSecret),             // Shared secret
        ]), true);

        // Store session ID if this is first key exchange
        if (is_null($this->sessionId)) {
            $this->sessionId = $exchangeHash;
        }

        $signature = sodium_crypto_sign_detached($exchangeHash, $ed25519Private);
        $signatureBlob = $this->packString('ssh-ed25519').$this->packString($signature);

        $this->logger->debug('Key exchange details:'.print_r([
            'host_key_blob_len' => strlen($hostKeyBlob),
            'host_key_public' => bin2hex($ed25519Public),
            'signature_len' => strlen($signature),
            'exchange_hash' => bin2hex($exchangeHash),
        ], true));

        // Construct KEX_ECDH_REPLY
        $kexReplyPayload =
            MessageType::chr(MessageType::KEXDH_REPLY).
            $this->packString($hostKeyBlob).       // Host key blob (includes identifier)
            $this->packString($curve25519Public).  // Server's ephemeral public key
            $this->packString($signatureBlob);      // Signature blob (includes identifier)

        $this->exchangeHash = $exchangeHash;

        return $kexReplyPayload;
    }

    private function packString(string $str): string
    {
        return pack('N', strlen($str)).$str;
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
}
