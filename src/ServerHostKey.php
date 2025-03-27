<?php

declare(strict_types=1);

namespace Whisp;

/**
 * Manages the server's host key. If this key changes, you'll see SSH connection errors due to 'known_hosts'
 */
class ServerHostKey
{
    private string $privateKey;

    private string $publicKey;

    public function __construct(private ?string $name = 'whisp', private ?string $baseDir = null)
    {
        // We add the name so we can have multiple servers on the same machine
        $baseDir = $this->baseDir ?? getenv('HOME') . '/.whisp-' . $this->name . '/';
        if (!is_dir($baseDir)) {
            $created = mkdir($baseDir, 0700, true);
            if (!$created) {
                throw new \RuntimeException('Failed to find or create baseDir: ' . $baseDir);
            }
        }

        if (empty($baseDir)) {
            throw new \RuntimeException('No baseDir set to store server\'s SSH host keypair');
        }

        $privateKeyPath = $baseDir . '/ssh_host_key';
        $publicKeyPath = $baseDir . '/ssh_host_key.pub';

        if (file_exists($privateKeyPath) && file_exists($publicKeyPath)) {
            $this->privateKey = file_get_contents($privateKeyPath);
            $this->publicKey = file_get_contents($publicKeyPath);
        } else {
            // Generate new key pair
            $keyPair = sodium_crypto_sign_keypair();
            $this->privateKey = sodium_crypto_sign_secretkey($keyPair);
            $this->publicKey = sodium_crypto_sign_publickey($keyPair);

            // Save key pair
            $wrotePrivateKey = file_put_contents($privateKeyPath, $this->privateKey);
            $wrotePublicKey = file_put_contents($publicKeyPath, $this->publicKey);
            chmod($privateKeyPath, 0600);
            chmod($publicKeyPath, 0644);

            if ($wrotePrivateKey === false || $wrotePublicKey === false) {
                throw new \RuntimeException('Failed to write server\'s SSH host keypair in ' . $baseDir);
            }
        }
    }

    public function getPrivateKey(): string
    {
        return $this->privateKey;
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }
}
