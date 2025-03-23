<?php

declare(strict_types=1);

namespace Whisp;

use Whisp\Enums\MessageType;

class KexNegotiator
{
    public ?string $clientKexInit = null;

    public ?string $serverKexInit = null;

    private array $kexAlgorithms = [
        'curve25519-sha256',  // Most modern and recommended
        // 'ecdh-sha2-nistp256',  // Widely supported backup
    ];

    private array $serverHostKeyAlgorithms = [
        'ssh-ed25519',        // Modern, secure, and efficient
        // 'rsa-sha2-256',        // Widely supported backup
    ];

    private array $encryptionAlgorithms = [
        // 'chacha20-poly1305@openssh.com',  // Modern and very fast
        'aes256-gcm@openssh.com',          // Strong and widely supported
    ];

    private array $macAlgorithms = [
        // 'hmac-sha2-256-etm@openssh.com',  // Modern ETM mode
        'hmac-sha2-256',                   // Widely supported backup
    ];

    private array $compressionAlgorithms = [
        'none',
    ];

    public function __construct(
        public Packet $packet,
        public string $clientVersion,
        public string $serverVersion,
    ) {}

    public function response(): string
    {
        $this->clientKexInit = chr($this->packet->type->value).$this->packet->message;

        // Build our algorithms lists
        $kexAlgorithms = implode(',', $this->kexAlgorithms);
        $serverHostKeyAlgorithms = implode(',', $this->serverHostKeyAlgorithms);
        $encryptionAlgorithmsCS = implode(',', $this->encryptionAlgorithms);
        $encryptionAlgorithmsSC = implode(',', $this->encryptionAlgorithms);
        $macAlgorithmsCS = implode(',', $this->macAlgorithms);
        $macAlgorithmsSC = implode(',', $this->macAlgorithms);
        $compressionAlgorithmsCS = implode(',', $this->compressionAlgorithms);
        $compressionAlgorithmsSC = implode(',', $this->compressionAlgorithms);
        $languagesCS = '';
        $languagesSC = '';

        // Construct KEXINIT payload
        $kexinitPayload =
            chr(MessageType::KEXINIT->value).
            random_bytes(16). // Cookie
            $this->packString($kexAlgorithms).
            $this->packString($serverHostKeyAlgorithms).
            $this->packString($encryptionAlgorithmsCS).
            $this->packString($encryptionAlgorithmsSC).
            $this->packString($macAlgorithmsCS).
            $this->packString($macAlgorithmsSC).
            $this->packString($compressionAlgorithmsCS).
            $this->packString($compressionAlgorithmsSC).
            $this->packString($languagesCS).
            $this->packString($languagesSC).
            "\0". // first_kex_packet_follows
            pack('N', 0); // reserved

        $this->serverKexInit = $kexinitPayload;

        return $kexinitPayload;
    }

    private function packString(string $str): string
    {
        return pack('N', strlen($str)).$str;
    }
}
