<?php

namespace Whisp\Tests\Unit;

use Whisp\Connection;
use Whisp\Loggers\ConsoleLogger;

test('connection disconnects after inactivity timeout', function () {
    // Create a socket pair for testing
    $pair = [];
    if (socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair) === false) {
        throw new \RuntimeException('Failed to create socket pair: '.socket_strerror(socket_last_error()));
    }
    [$clientSocket, $serverSocket] = $pair;

    // Set non-blocking mode on both sockets
    socket_set_nonblock($clientSocket);
    socket_set_nonblock($serverSocket);

    // Create the connection with our server socket
    $connection = new Connection($serverSocket);
    $connection->connectionId(1);
    $connection->logger(new ConsoleLogger);
    $connection->disconnectInactivitySeconds = 2; // Set a short timeout for testing

    // Mock the initial connection setup - set lastActivity to 3 seconds ago
    $reflection = new \ReflectionClass($connection);
    $lastActivityProp = $reflection->getProperty('lastActivity');
    $lastActivityProp->setAccessible(true);
    $lastActivityProp->setValue($connection, new \DateTimeImmutable('-3 seconds'));

    // Start handling the connection in a separate process
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new \RuntimeException('Failed to fork process');
    }

    if ($pid === 0) { // Child process
        socket_close($clientSocket); // Close client socket in child
        $connection->handle(); // This will run until disconnection
        exit(0);
    }

    // Parent process
    socket_close($serverSocket); // Close server socket in parent

    // Wait a bit to ensure the connection has time to process
    usleep(100000); // 0.1 seconds

    // Try to read from the socket - should get disconnection message
    $buffer = '';
    $read = socket_read($clientSocket, 8192);
    if ($read !== false) {
        $buffer .= $read;
    }

    // Clean up
    socket_close($clientSocket);
    pcntl_wait($status); // Wait for child process

    // Verify we got a disconnect message
    expect($buffer)->toContain('inactive for too long'); // The connection should send a disconnect message
});
