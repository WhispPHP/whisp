<?php

declare(strict_types=1);

use Whisp\Enums\MessageType;
use Whisp\Packet;

test('extracts string and integers correctly', function () {
    // Create a packet with: string("hello") + uint32(123) + uint32(456)
    $message = pack('N', 5).'howdy'.pack('N', 123).pack('N', 456);
    $packet = new Packet(chr(MessageType::CHANNEL_OPEN->value).$message);

    [$str, $int1, $int2] = $packet->extractFormat('%s%u%u');

    expect($str)->toBe('howdy')
        ->and($int1)->toBe(123)
        ->and($int2)->toBe(456);
});

test('extracts boolean values correctly', function () {
    // Create a packet with: string("test") + bool(true) + bool(false)
    $message = pack('N', 5).'howdy'.chr(1).chr(0);
    $packet = new Packet(chr(MessageType::CHANNEL_OPEN->value).$message);

    [$str, $bool1, $bool2] = $packet->extractFormat('%s%b%b');

    expect($str)->toBe('howdy')
        ->and($bool1)->toBeTrue()
        ->and($bool2)->toBeFalse();
});

test('handles empty string correctly', function () {
    // Create a packet with: string("") + uint32(123)
    $message = pack('N', 0).pack('N', 123);
    $packet = new Packet(chr(MessageType::CHANNEL_OPEN->value).$message);

    [$str, $int] = $packet->extractFormat('%s%u');

    expect($str)->toBe('')
        ->and($int)->toBe(123);
});

test('throws exception for unknown format specifier', function () {
    $message = pack('N', 5).'howdy';
    $packet = new Packet(chr(MessageType::CHANNEL_OPEN->value).$message);

    expect(fn () => $packet->extractFormat('%x')) // 'x' is not a valid specifier
        ->toThrow(\InvalidArgumentException::class, 'Unknown format specifier: x');
});

test('handles multiple strings correctly', function () {
    // Create a packet with: string("hello") + string("world") + uint32(123)
    $message = pack('N', 5).'howdy'.pack('N', 5).'world'.pack('N', 123);
    $packet = new Packet(chr(MessageType::CHANNEL_OPEN->value).$message);

    [$str1, $str2, $int] = $packet->extractFormat('%s%s%u');

    expect($str1)->toBe('howdy')
        ->and($str2)->toBe('world')
        ->and($int)->toBe(123);
});

test('handles long strings correctly', function () {
    $longString = str_repeat('a', 1000);
    // Create a packet with: string(1000 chars) + uint32(123)
    $message = pack('N', 1000).$longString.pack('N', 123);
    $packet = new Packet(chr(MessageType::CHANNEL_OPEN->value).$message);

    [$str, $int] = $packet->extractFormat('%s%u');

    expect($str)->toBe($longString)
        ->and($int)->toBe(123);
});

test('handles all formats together at once', function () {
    $message = pack('N', 5).'howdy'.pack('N', 420).chr(1);
    $packet = new Packet(chr(MessageType::CHANNEL_OPEN->value).$message);

    [$str, $int1, $bool] = $packet->extractFormat('%s%u%b');

    expect($str)->toBe('howdy')
        ->and($int1)->toBe(420)
        ->and($bool)->toBeTrue();
});
