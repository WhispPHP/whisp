<?php

declare(strict_types=1);

use Whisp\Crypto\MlKem768OpenSsl;
use Whisp\Enums\MessageType;
use Whisp\Kex;
use Whisp\KexNegotiator;
use Whisp\Packet;

function kexinit_packet(array $kexAlgorithms): Packet
{
    $nameList = fn (array $names): string => pack('N', strlen(implode(',', $names))).implode(',', $names);

    $payload = chr(MessageType::KEXINIT->value)
        .str_repeat("\0", 16)
        .$nameList($kexAlgorithms)
        .$nameList(['ssh-ed25519'])
        .$nameList(['aes256-gcm@openssh.com'])
        .$nameList(['aes256-gcm@openssh.com'])
        .$nameList(['hmac-sha2-256'])
        .$nameList(['hmac-sha2-256'])
        .$nameList(['none'])
        .$nameList(['none'])
        .$nameList([])
        .$nameList([])
        ."\0"
        .pack('N', 0);

    return new Packet($payload);
}

test('selects mlkem768x25519 when OpenSSL supports ML-KEM-768', function () {
    if (! MlKem768OpenSsl::isAvailable()) {
        $this->markTestSkipped('OpenSSL does not provide ML-KEM-768.');
    }

    $negotiator = new KexNegotiator(
        kexinit_packet([Kex::KEX_MLKEM768X25519_SHA256, Kex::KEX_CURVE25519_SHA256]),
        'SSH-2.0-test-client',
        'SSH-2.0-test-server',
    );

    $response = $negotiator->response();

    expect($negotiator->selectedKexAlgorithm)->toBe(Kex::KEX_MLKEM768X25519_SHA256)
        ->and($response)->toContain(Kex::KEX_MLKEM768X25519_SHA256);
});

test('keeps curve25519 fallback available', function () {
    $negotiator = new KexNegotiator(
        kexinit_packet([Kex::KEX_CURVE25519_SHA256]),
        'SSH-2.0-test-client',
        'SSH-2.0-test-server',
    );

    $response = $negotiator->response();

    expect($negotiator->selectedKexAlgorithm)->toBe(Kex::KEX_CURVE25519_SHA256)
        ->and($response)->toContain(Kex::KEX_CURVE25519_SHA256);
});
