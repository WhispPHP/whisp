<?php

use Whisp\Channel;
use Whisp\Pty;

beforeEach(function () {
    $this->channel = new Channel(1, 2, 1024, 32768, 'session');
});

describe('Channel PTY Management', function () {
    it('creates a valid PTY', function () {
        $result = $this->channel->createPty();
        expect($result)->toBeTrue();

        $pty = $this->channel->getPty();
        expect($pty)->toBeInstanceOf(Pty::class);
        expect($pty->isOpen())->toBeTrue();
        expect($pty->getMaster())->toBeResource();
        expect($pty->getSlave())->toBeResource();
    });

    it('maintains PTY validity after creation', function () {
        $this->channel->createPty();
        $pty = $this->channel->getPty();

        // Verify initial state
        expect($pty->isOpen())->toBeTrue();
        expect($pty->getMaster())->toBeResource();
        expect($pty->getSlave())->toBeResource();

        // Write some data
        $result = $pty->write("test\n");
        expect($result)->toBe(5);

        // Verify PTY is still valid
        expect($pty->isOpen())->toBeTrue();
        expect($pty->getMaster())->toBeResource();
        expect($pty->getSlave())->toBeResource();
    });

    it('closes PTY during cleanup', function () {
        $this->channel->createPty();
        $pty = $this->channel->getPty();

        // Verify PTY is open
        expect($pty->isOpen())->toBeTrue();

        // Close channel
        $this->channel->close();

        // Verify PTY is closed
        expect($pty->isOpen())->toBeFalse();
        expect($pty->getMaster())->toBeNull();
        expect($pty->getSlave())->toBeNull();
    });

    it('maintains PTY validity across fork', function () {
        // Create PTY
        $this->channel->createPty();
        $pty = $this->channel->getPty();

        // Verify pre-fork state
        expect($pty->isOpen())->toBeTrue();
        expect($pty->getMaster())->toBeResource();
        expect($pty->getSlave())->toBeResource();

        // Simulate fork by creating a new process
        $pid = pcntl_fork();

        if ($pid == -1) {
            fail('Fork failed');
        } elseif ($pid > 0) {
            // Parent process
            // Wait for child to finish
            pcntl_waitpid($pid, $status);

            // Verify PTY is still valid in parent
            expect($pty->isOpen())->toBeTrue();
            expect($pty->getMaster())->toBeResource();
            expect($pty->getSlave())->toBeResource();
        } else {
            // Child process
            // Verify PTY is valid in child
            expect($pty->isOpen())->toBeTrue();
            expect($pty->getMaster())->toBeResource();
            expect($pty->getSlave())->toBeResource();

            // Write some data
            $data = "test from child\n";
            $result = $pty->write($data);
            expect($result)->toBe(strlen($data));

            exit(0);
        }
    });
});
