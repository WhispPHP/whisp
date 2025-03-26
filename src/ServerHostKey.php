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

    public function __construct()
    {
        // TODO: URGENT: Make this a more permanent place, maybe configurable
        // When people composer require, this gets stored in vendor/ which will get wiped out super easily, especially with envoyer style deployments
        $privateKeyPath = realpath(__DIR__.'/../').'/ssh_host_key';
        $publicKeyPath = realpath(__DIR__.'/../').'/ssh_host_key.pub';

        if (file_exists($privateKeyPath) && file_exists($publicKeyPath)) {
            $this->privateKey = file_get_contents($privateKeyPath);
            $this->publicKey = file_get_contents($publicKeyPath);
        } else {
            // Generate new key pair
            $keyPair = sodium_crypto_sign_keypair();
            $this->privateKey = sodium_crypto_sign_secretkey($keyPair);
            $this->publicKey = sodium_crypto_sign_publickey($keyPair);

            // Save key pair
            file_put_contents($privateKeyPath, $this->privateKey);
            file_put_contents($publicKeyPath, $this->publicKey);
            chmod($privateKeyPath, 0600);
            chmod($publicKeyPath, 0644);
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
