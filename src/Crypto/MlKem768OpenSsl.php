<?php

declare(strict_types=1);

namespace Whisp\Crypto;

use FFI;
use RuntimeException;
use Throwable;

final class MlKem768OpenSsl
{
    public const PUBLIC_KEY_BYTES = 1184;

    public const CIPHERTEXT_BYTES = 1088;

    public const SHARED_SECRET_BYTES = 32;

    private const ALGORITHM = 'ML-KEM-768';

    private static ?bool $available = null;

    private FFI $ffi;

    public static function isAvailable(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }

        try {
            self::$available = (new self)->supportsAlgorithm();
        } catch (Throwable) {
            self::$available = false;
        }

        return self::$available;
    }

    public static function create(): self
    {
        $backend = new self;
        if (! $backend->supportsAlgorithm()) {
            throw new RuntimeException('OpenSSL does not provide ML-KEM-768.');
        }

        return $backend;
    }

    private function __construct()
    {
        if (! extension_loaded('ffi')) {
            throw new RuntimeException('FFI extension not loaded.');
        }

        $this->ffi = FFI::cdef('
            typedef struct evp_pkey_st EVP_PKEY;
            typedef struct evp_pkey_ctx_st EVP_PKEY_CTX;
            typedef struct ossl_lib_ctx_st OSSL_LIB_CTX;

            EVP_PKEY_CTX *EVP_PKEY_CTX_new_from_name(OSSL_LIB_CTX *libctx, const char *name, const char *propquery);
            EVP_PKEY *EVP_PKEY_new_raw_public_key_ex(OSSL_LIB_CTX *libctx, const char *keytype, const char *propq, const unsigned char *key, size_t keylen);
            EVP_PKEY_CTX *EVP_PKEY_CTX_new_from_pkey(OSSL_LIB_CTX *libctx, EVP_PKEY *pkey, const char *propquery);

            int EVP_PKEY_encapsulate_init(EVP_PKEY_CTX *ctx, const void *params);
            int EVP_PKEY_encapsulate(EVP_PKEY_CTX *ctx, unsigned char *wrappedkey, size_t *wrappedkeylen, unsigned char *genkey, size_t *genkeylen);

            void EVP_PKEY_CTX_free(EVP_PKEY_CTX *ctx);
            void EVP_PKEY_free(EVP_PKEY *pkey);

            unsigned long ERR_get_error(void);
            char *ERR_error_string(unsigned long e, char *buf);
        ', $this->libcrypto());
    }

    /**
     * @return array{ciphertext: string, sharedSecret: string}
     */
    public function encapsulate(string $publicKey): array
    {
        if (strlen($publicKey) !== self::PUBLIC_KEY_BYTES) {
            throw new RuntimeException('Invalid ML-KEM-768 public key length.');
        }

        $publicKeyBuffer = $this->ffi->new('unsigned char['.self::PUBLIC_KEY_BYTES.']');
        FFI::memcpy($publicKeyBuffer, $publicKey, self::PUBLIC_KEY_BYTES);

        $publicKeyHandle = $this->ffi->EVP_PKEY_new_raw_public_key_ex(
            null,
            self::ALGORITHM,
            null,
            $publicKeyBuffer,
            self::PUBLIC_KEY_BYTES,
        );

        if ($publicKeyHandle === null) {
            throw new RuntimeException('Could not import ML-KEM-768 public key: '.$this->lastError());
        }

        $context = null;
        try {
            $context = $this->ffi->EVP_PKEY_CTX_new_from_pkey(null, $publicKeyHandle, null);
            if ($context === null) {
                throw new RuntimeException('Could not create ML-KEM-768 context: '.$this->lastError());
            }

            if ($this->ffi->EVP_PKEY_encapsulate_init($context, null) !== 1) {
                throw new RuntimeException('Could not initialize ML-KEM-768 encapsulation: '.$this->lastError());
            }

            $ciphertextLength = $this->ffi->new('size_t[1]');
            $sharedSecretLength = $this->ffi->new('size_t[1]');
            if ($this->ffi->EVP_PKEY_encapsulate($context, null, $ciphertextLength, null, $sharedSecretLength) !== 1) {
                throw new RuntimeException('Could not size ML-KEM-768 encapsulation output: '.$this->lastError());
            }

            if ($ciphertextLength[0] !== self::CIPHERTEXT_BYTES || $sharedSecretLength[0] !== self::SHARED_SECRET_BYTES) {
                throw new RuntimeException('Unexpected ML-KEM-768 encapsulation output length.');
            }

            $ciphertext = $this->ffi->new('unsigned char['.self::CIPHERTEXT_BYTES.']');
            $sharedSecret = $this->ffi->new('unsigned char['.self::SHARED_SECRET_BYTES.']');

            if ($this->ffi->EVP_PKEY_encapsulate($context, $ciphertext, $ciphertextLength, $sharedSecret, $sharedSecretLength) !== 1) {
                throw new RuntimeException('Could not encapsulate ML-KEM-768 shared secret: '.$this->lastError());
            }

            return [
                'ciphertext' => FFI::string($ciphertext, self::CIPHERTEXT_BYTES),
                'sharedSecret' => FFI::string($sharedSecret, self::SHARED_SECRET_BYTES),
            ];
        } finally {
            if ($context !== null) {
                $this->ffi->EVP_PKEY_CTX_free($context);
            }

            $this->ffi->EVP_PKEY_free($publicKeyHandle);
        }
    }

    private function supportsAlgorithm(): bool
    {
        $context = $this->ffi->EVP_PKEY_CTX_new_from_name(null, self::ALGORITHM, null);
        if ($context === null) {
            return false;
        }

        $this->ffi->EVP_PKEY_CTX_free($context);

        return true;
    }

    private function lastError(): string
    {
        $error = $this->ffi->ERR_get_error();
        if ($error === 0) {
            return 'no OpenSSL error available';
        }

        return FFI::string($this->ffi->ERR_error_string($error, null));
    }

    private function libcrypto(): string
    {
        $configured = getenv('WHISP_LIBCRYPTO');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $candidates = PHP_OS === 'Darwin'
            ? [
                '/opt/homebrew/opt/openssl@3/lib/libcrypto.dylib',
                '/usr/local/opt/openssl@3/lib/libcrypto.dylib',
                'libcrypto.dylib',
            ]
            : [
                'libcrypto.so.3',
                'libcrypto.so',
            ];

        foreach ($candidates as $candidate) {
            try {
                FFI::cdef('unsigned long OpenSSL_version_num(void);', $candidate);

                return $candidate;
            } catch (Throwable) {
                continue;
            }
        }

        throw new RuntimeException('Could not load libcrypto.');
    }
}
