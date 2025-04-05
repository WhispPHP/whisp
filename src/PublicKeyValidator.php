<?php

declare(strict_types=1);

namespace Whisp;

use Psr\Log\LoggerInterface;
use Whisp\Concerns\WritesLogs;
use Whisp\Loggers\NullLogger;

class PublicKeyValidator
{
    use WritesLogs;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->setLogger($logger ?? new NullLogger);
    }

    /**
     * Parse a signature blob into its components
     *
     * Format (RFC 4253):
     * string    signature format identifier
     * string    signature blob
     *
     * @param  string  $signatureBlob  The raw signature blob
     * @return array{0: string, 1: string}|false Array containing [signatureAlgorithm, signatureData]
     */
    public function parseSignatureBlob(string $signatureBlob): array|false
    {
        try {
            $offset = 0;
            $blobLength = strlen($signatureBlob);

            // Extract algorithm
            $algorithmLength = unpack('N', substr($signatureBlob, $offset, 4))[1];
            $offset += 4;

            if ($algorithmLength <= 0 || $algorithmLength > $blobLength - 4) {
                $this->error("Invalid algorithm length: {$algorithmLength}");

                return false;
            }

            $algorithm = substr($signatureBlob, $offset, $algorithmLength);
            $offset += $algorithmLength;

            // Extract signature
            if ($offset + 4 > $blobLength) {
                $this->error('Signature blob too short for signature length');

                return false;
            }

            $signatureLength = unpack('N', substr($signatureBlob, $offset, 4))[1];
            $offset += 4;

            if ($signatureLength <= 0 || $offset + $signatureLength > $blobLength) {
                $this->error("Invalid signature length: {$signatureLength}");

                return false;
            }

            $signature = substr($signatureBlob, $offset, $signatureLength);
            $offset += $signatureLength;

            // Check for remaining data
            $remaining = $blobLength - $offset;
            if ($remaining > 0) {
                $this->warning("Extra data in signature blob: {$remaining} bytes remaining");
            }

            return [$algorithm, $signature];
        } catch (\Exception $e) {
            $this->error('Failed to parse signature blob: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Construct the signed data buffer according to RFC 4252/8332
     * The buffer consists of:
     * string    session identifier
     * byte      SSH_MSG_USERAUTH_REQUEST
     * string    user name
     * string    service name
     * string    "publickey"
     * boolean   TRUE
     * string    signature algorithm name (e.g. rsa-sha2-256, rsa-sha2-512, ssh-ed25519)
     * string    public key blob
     */
    public function constructSignedData(
        string $sessionId,
        string $username,
        string $service,
        string $signatureAlgorithm,
        string $keyBlob
    ): string {
        try {
            // Extract the key type first
            $offset = 0;
            [$keyType] = $this->extractString($keyBlob, $offset);

            // Start with session identifier
            $signedData = pack('Na*', strlen($sessionId), $sessionId);

            // Add message type (SSH_MSG_USERAUTH_REQUEST = 50)
            $signedData .= chr(50);

            // Add username
            $signedData .= pack('Na*', strlen($username), $username);

            // Add service name (typically "ssh-connection")
            $signedData .= pack('Na*', strlen($service), $service);

            // Add authentication method ("publickey")
            $signedData .= pack('Na*', strlen('publickey'), 'publickey');

            // Add boolean TRUE
            $signedData .= chr(1);

            // Add signature algorithm name
            $signedData .= pack('Na*', strlen($signatureAlgorithm), $signatureAlgorithm);

            // Add the key blob - for Ed25519 we use it as is, for RSA we need to reconstruct it
            if ($keyType === 'ssh-rsa') {
                // Extract RSA components and reconstruct the key blob
                $components = $this->extractRsaComponents($keyBlob);
                if ($components === false) {
                    throw new \RuntimeException('Failed to extract RSA components from key blob');
                }

                // Reconstruct the key blob in the correct format
                $reconstructedKeyBlob = '';
                $reconstructedKeyBlob .= pack('Na*', strlen('ssh-rsa'), 'ssh-rsa');
                $reconstructedKeyBlob .= pack('Na*', strlen($components['exponent']), $components['exponent']);
                $reconstructedKeyBlob .= pack('Na*', strlen($components['modulus']), $components['modulus']);

                // Add the reconstructed key blob
                $signedData .= pack('Na*', strlen($reconstructedKeyBlob), $reconstructedKeyBlob);
            } else {
                // For Ed25519 and other key types, use the original blob as is
                $signedData .= pack('Na*', strlen($keyBlob), $keyBlob);
            }

            return $signedData;
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to construct signed data: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Extract the key algorithm from a public key blob
     *
     * @param  string  $keyBlob  Raw binary string containing the public key blob
     * @return string The key algorithm (e.g. 'ssh-rsa', 'ssh-ed25519')
     *
     * @throws \LengthException If the blob is malformed
     */
    protected function extractKeyAlgorithm(string $keyBlob): string
    {
        $offset = 0;
        [$algorithm, $offset] = $this->extractString($keyBlob, $offset);

        return $algorithm;
    }

    /**
     * Validate an SSH public key signature
     *
     * @param  string  $keyAlgorithm  The claimed SSH key algorithm (e.g. 'ssh-rsa')
     * @param  string  $keyBlob  Raw binary string containing the public key blob
     * @param  string  $signatureBlob  Raw binary string containing the signature blob
     * @param  string  $sessionId  The SSH session ID
     * @param  string  $username  The SSH username
     * @param  string  $service  The SSH service name (typically 'ssh-connection')
     * @return bool True if the signature is valid
     */
    public function validateSignature(
        string $keyAlgorithm,
        string $keyBlob,
        string $signatureBlob,
        string $sessionId,
        string $username,
        string $service
    ): bool {
        try {
            // Extract the actual key algorithm from the blob
            $actualKeyAlgorithm = $this->extractKeyAlgorithm($keyBlob);

            // Validate the key blob structure and components
            if (! $this->validateKeyBlob($keyBlob)) {
                $this->error('Key blob validation failed');

                return false;
            }

            // Parse the signature blob
            [$signatureAlgorithm, $signatureData] = $this->parseSignatureBlob($signatureBlob);

            // Check compatibility between key algorithm and signature algorithm
            if (! $this->isSignatureAlgorithmCompatible($actualKeyAlgorithm, $signatureAlgorithm)) {
                $this->warning("Incompatible key and signature algorithms: $actualKeyAlgorithm / $signatureAlgorithm");

                return false;
            }

            // Construct the signed data using the signature algorithm
            $signedData = $this->constructSignedData($sessionId, $username, $service, $signatureAlgorithm, $keyBlob);

            return match ($actualKeyAlgorithm) {
                'ssh-rsa' => $this->verifyRsaSignature($keyBlob, $signatureData, $signedData, $signatureAlgorithm),
                'ssh-ed25519' => $this->verifyEd25519Signature($keyBlob, $signatureData, $signedData, $signatureAlgorithm),
                default => false
            };
        } catch (\Exception $e) {
            $this->error('Signature validation failed: '.$e->getMessage());
            $this->error('Stack trace: '.$e->getTraceAsString());

            return false;
        }
    }

    /**
     * Check if a signature algorithm is generally compatible with a public key algorithm type.
     */
    private function isSignatureAlgorithmCompatible(string $keyAlgorithm, string $sigAlgorithm): bool
    {
        $result = match ($keyAlgorithm) {
            'ssh-rsa' => in_array($sigAlgorithm, ['ssh-rsa', 'rsa-sha2-256', 'rsa-sha2-512']),
            'rsa-sha2-256', 'rsa-sha2-512' => false, // These are signature algorithms, not key types
            'ssh-ed25519' => $sigAlgorithm === 'ssh-ed25519',
            // TODO: Add ECDSA types
            default => false,
        };

        if (! $result) {
            $this->warning("Incompatible combination: key algorithm '{$keyAlgorithm}' with signature algorithm '{$sigAlgorithm}'");

            if (in_array($keyAlgorithm, ['rsa-sha2-256', 'rsa-sha2-512'])) {
                $this->warning("'{$keyAlgorithm}' is a signature algorithm, not a key type. Expected key type for RSA is 'ssh-rsa'.");
            }
        }

        return $result;
    }

    /**
     * Verify an Ed25519 signature
     * The $sigAlgorithm parameter is included for consistency but not used by Ed25519 verification itself.
     */
    private function verifyEd25519Signature(string $publicKeyBlob, string $signatureData, string $signedData, string $sigAlgorithm): bool
    {
        try {
            // Extract the raw public key from the blob
            $publicKey = $this->extractEd25519PublicKey($publicKeyBlob);
            if ($publicKey === false) {
                $this->error('Failed to extract Ed25519 public key from blob');

                return false;
            }

            // Verify signature length
            if (strlen($signatureData) !== SODIUM_CRYPTO_SIGN_BYTES) {
                $this->error('Invalid Ed25519 signature length: got '.strlen($signatureData).
                                   ' bytes, expected '.SODIUM_CRYPTO_SIGN_BYTES);

                return false;
            }

            return sodium_crypto_sign_verify_detached($signatureData, $signedData, $publicKey);
        } catch (\Exception $e) {
            $this->error('Ed25519 signature verification exception: '.$e->getMessage());
            $this->error('Stack trace: '.$e->getTraceAsString());

            return false;
        }
    }

    /**
     * Verify an RSA signature using OpenSSL
     *
     * This method verifies RSA signatures using RSASSA-PKCS1-v1_5 padding,
     * which is the standard for SSH 'rsa-sha2-*' signatures (RFC 8332).
     * OpenSSL's openssl_verify function uses this padding by default.
     *
     * @param  string  $publicKeyData  Raw SSH public key blob
     * @param  string  $signatureData  Raw signature data
     * @param  string  $signedData  The data that was signed
     * @param  string  $sigAlgorithm  The signature algorithm (e.g. 'rsa-sha2-256')
     * @return bool True if signature is valid
     */
    public function verifyRsaSignature(string $publicKeyData, string $signatureData, string $signedData, string $sigAlgorithm): bool
    {
        try {
            // Extract the RSA components from the SSH key blob
            $components = $this->extractRsaComponents($publicKeyData);
            if ($components === false) {
                $this->error('Failed to extract RSA components from key blob');

                return false;
            }

            // Convert the key components to PEM format for OpenSSL
            $publicKeyPem = $this->createPemFromComponents($components['modulus'], $components['exponent']);
            if ($publicKeyPem === false) {
                $this->error('Failed to create PEM from RSA components');

                return false;
            }

            // Map SSH signature algorithm to OpenSSL algorithm
            $opensslAlgorithm = match ($sigAlgorithm) {
                'rsa-sha2-256' => OPENSSL_ALGO_SHA256,
                'rsa-sha2-512' => OPENSSL_ALGO_SHA512,
                'ssh-rsa' => OPENSSL_ALGO_SHA1, // Legacy
                default => null
            };

            if ($opensslAlgorithm === null) {
                $this->error("Unsupported signature algorithm: {$sigAlgorithm}");

                return false;
            }

            // Load the public key
            $publicKey = openssl_pkey_get_public($publicKeyPem);
            if ($publicKey === false) {
                $this->error('Failed to load PEM key. OpenSSL Error: '.openssl_error_string());

                return false;
            }

            // Get key details for verification
            $keyDetails = openssl_pkey_get_details($publicKey);
            if ($keyDetails === false) {
                $this->error('Failed to get key details. OpenSSL Error: '.openssl_error_string());

                return false;
            }

            // Verify the signature using RSASSA-PKCS1-v1_5 padding (default for openssl_verify)
            $result = openssl_verify($signedData, $signatureData, $publicKey, $opensslAlgorithm);

            if ($result === 1) {
                return true;
            } elseif ($result === 0) {
                $this->warning('RSA signature verification failed - invalid signature');

                return false;
            } else {
                $this->error('RSA signature verification error. OpenSSL Error: '.openssl_error_string());

                return false;
            }
        } catch (\Exception $e) {
            $this->error('RSA signature verification exception: '.$e->getMessage());
            $this->error('Stack trace: '.$e->getTraceAsString());

            return false;
        }
    }

    /**
     * Extract a string from a binary blob
     */
    protected function extractString(string $data, int &$offset): array
    {
        if ($offset + 4 > strlen($data)) {
            throw new \LengthException("Not enough data to read length at offset {$offset}");
        }
        $length = unpack('N', substr($data, $offset, 4))[1];
        $offset += 4;
        if ($offset + $length > strlen($data)) {
            throw new \LengthException("Not enough data to read string of length {$length} at offset {$offset}");
        }
        $string = substr($data, $offset, $length);
        $offset += $length;

        return [$string, $offset];
    }

    /**
     * Pack a string with length prefix
     */
    private function packString(string $str): string
    {
        return pack('N', strlen($str)).$str;
    }

    /**
     * Pack a boolean value
     */
    private function packBool(bool $bool): string
    {
        return $bool ? chr(1) : chr(0);
    }

    /**
     * Extract RSA components from an SSH key blob
     *
     * @param  string  $keyBlob  Raw SSH key blob
     * @return array{type: string, exponent: string, modulus: string}|false
     */
    protected function extractRsaComponents(string $keyBlob): array|false
    {
        try {
            $offset = 0;
            // Extract key type
            [$keyType, $offset] = $this->extractString($keyBlob, $offset);
            if ($keyType !== 'ssh-rsa') {
                $this->error("Invalid key type: {$keyType}, expected ssh-rsa");

                return false;
            }

            // Extract exponent
            [$exponent, $offset] = $this->extractString($keyBlob, $offset);

            // Extract modulus
            [$modulus, $offset] = $this->extractString($keyBlob, $offset);

            return [
                'type' => $keyType,
                'exponent' => $exponent,
                'modulus' => $modulus,
            ];
        } catch (\Exception $e) {
            $this->error('Failed to extract RSA components: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Convert RSA components to PEM format
     *
     * @param  string  $modulus  Raw binary modulus
     * @param  string  $exponent  Raw binary exponent
     * @return string|false PEM formatted public key
     */
    protected function createPemFromComponents(string $modulus, string $exponent): string|false
    {
        try {
            // Convert binary strings to BigIntegers (base 256 for raw binary)
            $n = new \phpseclib3\Math\BigInteger($modulus, 256);
            $e = new \phpseclib3\Math\BigInteger($exponent, 256);

            // Load components into a key object
            $key = \phpseclib3\Crypt\PublicKeyLoader::load([
                'n' => $n,
                'e' => $e,
            ]);

            // Export as PEM (PKCS8 format)
            return $key->toString('PKCS8');
        } catch (\Exception $e) {
            $this->error('Failed to create PEM from components: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Convert RSA components to SSH public key format
     *
     * @param  string  $modulus  Raw binary modulus
     * @param  string  $exponent  Raw binary exponent
     * @return string SSH public key format (base64 encoded)
     */
    protected function createSshKeyFromComponents(string $modulus, string $exponent): string
    {
        // Build the key blob in SSH format
        $keyBlob = $this->packString('ssh-rsa');
        $keyBlob .= $this->packString($exponent);
        $keyBlob .= $this->packString($modulus);

        // Format as SSH public key
        return 'ssh-rsa '.base64_encode($keyBlob);
    }

    /**
     * Validate a key blob by attempting to reconstruct it into standard formats
     */
    public function validateKeyBlob(string $keyBlob): bool
    {
        try {
            // Extract the key type
            $offset = 0;
            [$keyType, $offset] = $this->extractString($keyBlob, $offset);

            if ($keyType === 'ssh-rsa') {
                $components = $this->extractRsaComponents($keyBlob);
                if ($components === false) {
                    return false;
                }

                // Try to create PEM format
                $pem = $this->createPemFromComponents($components['modulus'], $components['exponent']);
                if ($pem === false) {
                    $this->error('Failed to create PEM from key components');

                    return false;
                }

                $this->createSshKeyFromComponents($components['modulus'], $components['exponent']);

                return true;
            } elseif ($keyType === 'ssh-ed25519') {
                // For Ed25519, we just need to extract and validate the 32-byte public key
                [$publicKey, $offset] = $this->extractString($keyBlob, $offset);

                if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                    $this->error('Invalid Ed25519 public key length: '.strlen($publicKey).
                                       ' bytes, expected '.SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES.' bytes');

                    return false;
                }

                // Check if there's any trailing data
                if ($offset < strlen($keyBlob)) {
                    $this->warning('Extra data in Ed25519 key blob: '.
                                         (strlen($keyBlob) - $offset).' bytes remaining');
                }

                return true;
            }

            $this->error("Unsupported key type: {$keyType}");

            return false;
        } catch (\Exception $e) {
            $this->error('Key blob validation failed: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Extract Ed25519 public key from an SSH key blob
     *
     * @param  string  $keyBlob  Raw SSH key blob
     * @return string|false The raw 32-byte Ed25519 public key or false on failure
     */
    protected function extractEd25519PublicKey(string $keyBlob): string|false
    {
        try {
            $offset = 0;
            // Extract key type
            [$keyType, $offset] = $this->extractString($keyBlob, $offset);
            if ($keyType !== 'ssh-ed25519') {
                $this->error("Invalid key type: {$keyType}, expected ssh-ed25519");

                return false;
            }

            // Extract public key
            [$publicKey, $offset] = $this->extractString($keyBlob, $offset);
            if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $this->error('Invalid Ed25519 public key length: got '.strlen($publicKey).
                                   ' bytes, expected '.SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);

                return false;
            }

            return $publicKey;
        } catch (\Exception $e) {
            $this->error('Failed to extract Ed25519 public key: '.$e->getMessage());

            return false;
        }
    }

    public function getSshKeyFromBlob(string $keyBlob): string
    {
        $offset = 0;
        [$keyType, $offset] = $this->extractString($keyBlob, $offset);
        if ($keyType === 'ssh-rsa') {
            $components = $this->extractRsaComponents($keyBlob);
            if ($components !== false) {
                return $this->createSshKeyFromComponents($components['modulus'], $components['exponent']);
            }
        } elseif ($keyType === 'ssh-ed25519') {
            // For Ed25519, we need to preserve the full OpenSSH format
            // Pack the key blob in the standard OpenSSH format
            $packedBlob = $this->packString($keyType);  // Add key type
            $packedBlob .= substr($keyBlob, $offset);   // Add the rest of the key data

            return $keyType.' '.base64_encode($packedBlob);
        }

        // Fallback for unknown key types
        return $keyType.' '.base64_encode($keyBlob);
    }
}
