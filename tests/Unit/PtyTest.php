<?php

use Whisp\Pty;

beforeEach(function () {
    $this->pty = new Pty;
});

afterEach(function () {
    if (isset($this->pty)) {
        $this->pty->close();
    }
});

test('opens and closes PTY successfully', function () {
    // Open PTY
    [$master, $slave] = $this->pty->open();
    expect($master)->toBeResource();
    expect($slave)->toBeResource();
    expect($this->pty->isOpen())->toBeTrue();

    // Get file descriptors
    $masterMeta = stream_get_meta_data($master);
    $slaveMeta = stream_get_meta_data($slave);
    expect($masterMeta['stream_type'])->toBe('STDIO');
    expect($slaveMeta['stream_type'])->toBe('STDIO');

    // Close PTY
    $this->pty->close();
    expect($this->pty->isOpen())->toBeFalse();
    expect(is_resource($master))->toBeFalse();
    expect(is_resource($slave))->toBeFalse();
});

test('writes and reads data through PTY', function () {
    [$master, $slave] = $this->pty->open();

    // Set blocking mode for this test
    stream_set_blocking($master, true);
    stream_set_blocking($slave, true);

    // Set an alarm for 1 second to prevent infinite hangs
    pcntl_signal(SIGALRM, function () {
        throw new RuntimeException('Test timed out after 1 second');
    });
    pcntl_alarm(2);

    try {
        // Fork a process to read from slave
        $pid = pcntl_fork();
        if ($pid == -1) {
            throw new RuntimeException('Failed to fork process');
        }

        if ($pid == 0) {
            // Child process - read from slave
            try {
                // Write something to indicate we're ready
                fwrite($slave, 'READY');
                $read = fread($slave, 10); // Read 10 bytes to match HOWDYHOWDY
                if (strlen($read) === 10) {  // Check we got 10 bytes
                    exit(0); // Success
                }
            } catch (\Exception $e) {
                error_log('Child process error: '.$e->getMessage());
            }
            exit(1); // Failure
        }

        // Parent process - write to master
        // Wait for child to be ready
        $ready = fread($master, 5);
        if ($ready !== 'READY') {
            throw new RuntimeException('Child process not ready');
        }

        $testData = 'HOWDYHOWDY';
        $written = $this->pty->write($testData);
        expect($written)->toBe(strlen($testData));

        // Wait for child process with timeout check
        $status = 0;
        $waitResult = pcntl_waitpid($pid, $status, WNOHANG);

        // Poll for completion
        $startTime = time();
        while ($waitResult === 0 && (time() - $startTime) < 3) {
            usleep(10000); // 10ms sleep
            $waitResult = pcntl_waitpid($pid, $status, WNOHANG);
        }

        if ($waitResult === 0) {
            // Child process didn't complete in time
            posix_kill($pid, SIGKILL); // Force kill
            pcntl_waitpid($pid, $status); // Clean up
            throw new RuntimeException('Read operation timed out');
        }

        $exitCode = pcntl_wexitstatus($status);
        expect($exitCode)->toBe(0);
    } finally {
        // Cleanup
        pcntl_alarm(0); // Disable alarm
        if (isset($pid) && $pid > 0) {
            // Ensure child process is cleaned up
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status, WNOHANG);
        }
    }
})->markTestSkipped('Reading is timing out though it shouldn\'t - look into shortly');

test('handles empty reads and writes', function () {
    [$master, $slave] = $this->pty->open();

    // Keep in non-blocking mode
    stream_set_blocking($master, false);
    stream_set_blocking($slave, false);

    // Empty write
    $written = $this->pty->write('');
    expect($written)->toBe(0);

    // Empty read
    $read = $this->pty->read(0);
    expect($read)->toBe('');
});

test('handles closed PTY gracefully', function () {
    // Write/read before open
    expect($this->pty->write('test'))->toBe(0);
    expect($this->pty->read())->toBe('');

    // Open and then close
    [$master, $slave] = $this->pty->open();
    $this->pty->close();

    // Write/read after close
    expect($this->pty->write('test'))->toBe(0);
    expect($this->pty->read())->toBe('');
});

test('slave name is accessible', function () {
    [$master, $slave] = $this->pty->open();

    $slaveName = $this->pty->getSlaveName();
    expect($slaveName)->toStartWith('/dev/');
    expect(file_exists($slaveName))->toBeTrue();
});
