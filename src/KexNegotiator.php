<?php

declare(strict_types=1);

namespace Whisp;

use Whisp\Crypto\MlKem768OpenSsl;
use Whisp\Enums\MessageType;

class KexNegotiator
{
    public ?string $clientKexInit = null;

    public ?string $serverKexInit = null;

    public string $selectedKexAlgorithm = Kex::KEX_CURVE25519_SHA256;

    private array $kexAlgorithms;

    private array $serverHostKeyAlgorithms = [
        'ssh-ed25519',        // Modern, secure, and efficient
        // 'rsa-sha2-256',       // Only support this one RSA algorithm for now
        // 'rsa-sha2-512',    // Will add later once we're sure rsa-sha2-256 works
        // 'ssh-rsa',         // Legacy, not secure, removed
    ];

    public array $acceptedUserKeyAlgorithms = [
        'ssh-ed25519',
        'rsa-sha2-256',
        'rsa-sha2-512',
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
    ) {
        $this->kexAlgorithms = $this->availableKexAlgorithms();
    }

    public function response(): string
    {
        $this->clientKexInit = chr($this->packet->type->value).$this->packet->message;
        $this->selectedKexAlgorithm = $this->negotiateKexAlgorithm();

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

    private function availableKexAlgorithms(): array
    {
        $algorithms = [];

        if (MlKem768OpenSsl::isAvailable()) {
            $algorithms[] = Kex::KEX_MLKEM768X25519_SHA256;
        }

        $algorithms[] = Kex::KEX_CURVE25519_SHA256;

        return $algorithms;
    }

    private function negotiateKexAlgorithm(): string
    {
        foreach ($this->clientKexAlgorithms() as $algorithm) {
            if (in_array($algorithm, $this->kexAlgorithms, true)) {
                return $algorithm;
            }
        }

        return Kex::KEX_CURVE25519_SHA256;
    }

    private function clientKexAlgorithms(): array
    {
        $offset = 16; // SSH_MSG_KEXINIT cookie
        $nameList = $this->readString($this->packet->message, $offset);

        return $nameList === '' ? [] : explode(',', $nameList);
    }

    private function readString(string $payload, int &$offset): string
    {
        if (strlen($payload) < $offset + 4) {
            return '';
        }

        $length = unpack('N', substr($payload, $offset, 4))[1];
        $offset += 4;

        if (strlen($payload) < $offset + $length) {
            return '';
        }

        $value = substr($payload, $offset, $length);
        $offset += $length;

        return $value;
    }
}
