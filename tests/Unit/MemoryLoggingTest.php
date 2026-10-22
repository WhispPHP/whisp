<?php

declare(strict_types=1);

use Whisp\Server;

test('memory logging checks total elapsed seconds', function () {
    // Avoid starting a server or writing host keys; only the interval calculation is tested.
    $reflection = new ReflectionClass(Server::class);
    $server = $reflection->newInstanceWithoutConstructor();
    $server->memoryLogInterval = 180;

    $utc = new DateTimeZone('UTC');
    $reflection->getProperty('lastMemoryLog')->setValue(
        $server,
        new DateTime('2026-01-01 12:00:00', $utc),
    );

    $isMemoryLogDue = $reflection->getMethod('isMemoryLogDue');

    // Below the configured interval: no log yet.
    expect($isMemoryLogDue->invoke($server, new DateTimeImmutable('2026-01-01 12:02:59', $utc)))->toBeFalse();

    // At the exact threshold and beyond: a memory log is due.
    expect($isMemoryLogDue->invoke($server, new DateTimeImmutable('2026-01-01 12:03:00', $utc)))->toBeTrue();
    expect($isMemoryLogDue->invoke($server, new DateTimeImmutable('2026-01-01 12:04:30', $utc)))->toBeTrue();
});

test('memory logging respects shorter custom intervals', function () {
    $reflection = new ReflectionClass(Server::class);
    $server = $reflection->newInstanceWithoutConstructor();
    $server->memoryLogInterval = 30;

    $utc = new DateTimeZone('UTC');
    $reflection->getProperty('lastMemoryLog')->setValue(
        $server,
        new DateTime('2026-01-01 12:00:00', $utc),
    );

    $isMemoryLogDue = $reflection->getMethod('isMemoryLogDue');

    expect($isMemoryLogDue->invoke($server, new DateTimeImmutable('2026-01-01 12:00:29', $utc)))->toBeFalse();
    expect($isMemoryLogDue->invoke($server, new DateTimeImmutable('2026-01-01 12:00:30', $utc)))->toBeTrue();
});
