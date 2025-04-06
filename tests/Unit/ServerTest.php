<?php

declare(strict_types=1);

use Whisp\Server;

beforeEach(function () {
    // Use a random available port for testing
    $this->port = rand(49152, 65535);
    $this->host = '127.0.0.1';
    // $this->server = new Server($this->port, $this->host);

    // Create a temporary server script - TODO: move to 'beforeAll'
    $this->appScript = sys_get_temp_dir().'/whisp_test_app_'.uniqid().'.php';
    file_put_contents($this->appScript, "<?php echo 'Howdy!';");

    // TODO: Refactor so we can test with a basic Server class instance
    $this->serverScript = sys_get_temp_dir().'/whisp_test_server_'.uniqid().'.php';
    $whispAutoloadPath = realpath(__DIR__.'/../../vendor/autoload.php');
    file_put_contents($this->serverScript, <<<PHP
<?php
require '{$whispAutoloadPath}';
\$server = new Whisp\Server((int) \$argv[1], \$argv[2]);
\$server->run('{$this->appScript}');
PHP
    );
});

afterEach(function () {
    if (isset($this->server)) {
        $this->server->stop();
    }

    if (file_exists($this->serverScript)) {
        unlink($this->serverScript);
    }

    if (file_exists($this->appScript)) {
        unlink($this->appScript);
    }

    // Clean up any open pipes
    if (isset($this->pipes)) {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }

    // Clean up process if it's still running
    if (isset($this->process) && is_resource($this->process)) {
        proc_terminate($this->process);
    }
});

test('creates and configures TCP socket correctly', function () {
    // Start server in background using the script
    ['process' => $this->process, 'pid' => $pid, 'pipes' => $this->pipes] = start_server_and_wait_for_listening($this->serverScript, $this->host, $this->port);

    // Try to connect to the socket
    $socket = @fsockopen($this->host, $this->port, $errno, $errstr, 1);
    expect($pid)->toBeRunning();
    expect($socket)->toBeResource();
    expect($errno)->toBe(0);
    fclose($socket);
});

test('handles SIGINT interrupt gracefully', function () {
    ['process' => $this->process, 'pid' => $pid] = start_server_and_wait_for_listening($this->serverScript, $this->host, $this->port);
    expect($pid)->toBeRunning();
    posix_kill($pid, SIGINT);
    proc_terminate($this->process, SIGINT);
    expect($pid)->toNotBeRunning(2500);
})->markTestSkipped('Not working on GitHub CI atm, but works wonderfully locally and on test servers');

test('handles SIGTERM interrupt gracefully', function () {
    ['process' => $this->process, 'pid' => $pid] = start_server_and_wait_for_listening($this->serverScript, $this->host, $this->port);
    expect($pid)->toBeRunning();
    posix_kill($pid, SIGTERM);
    proc_terminate($this->process, SIGTERM);
    expect($pid)->toNotBeRunning(2500);
});

test('accepts and handles new connections', function () {
    // Arrange
    ['process' => $this->process, 'pid' => $this->pid, 'pipes' => $this->pipes] = start_server_and_wait_for_listening($this->serverScript, $this->host, $this->port);

    // Act
    $clientSocket = fsockopen($this->host, $this->port, $errno, $errstr, 1);
    expect($clientSocket)->toBeResource();

    // Wait for connection message in server output
    $startTime = microtime(true);
    $connectionAccepted = false;

    while (microtime(true) - $startTime < 0.2) { // 200ms timeout
        $line = fgets($this->pipes[1]); // Read from stdout
        if ($line && strpos($line, '#1 Connection accepted from') !== false) {
            $connectionAccepted = true;
            break;
        }
        usleep(10000); // 10ms sleep
    }

    // Assert
    expect($connectionAccepted)->toBeTrue("Server didn't log connection acceptance");

    // Cleanup
    fclose($clientSocket);
});

test('manages multiple connections', function () {
    // Arrange
    ['process' => $this->process, 'pid' => $this->pid, 'pipes' => $this->pipes] = start_server_and_wait_for_listening($this->serverScript, $this->host, $this->port);

    // Act
    $connections = [];
    for ($i = 0; $i < 3; $i++) {
        $clientSocket = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 1);
        expect($clientSocket)->toBeResource();
        $connections[] = $clientSocket;
    }

    // Check if each connection was handled by the server
    $childPids = [];
    $startTime = microtime(true);
    $i = 0;

    while ((microtime(true) - $startTime) < 1) { // 1000ms timeout
        $read = [$this->pipes[1]]; // stdout
        $write = $except = [];

        // Wait for data with 10ms timeout
        if (stream_select($read, $write, $except, 0, 10000) > 0) {
            $line = fgets($this->pipes[1]);
            if ($line && strpos($line, 'Connection #') !== false) {
                $childPids[] = $line;
            }
        }

        $i++;
    }

    expect(count($childPids))->toBe(3);

    // Cleanup
    foreach ($connections as $socket) {
        fclose($socket);
    }
})->markTestSkipped('Not working on GitHub CI atm, but works wonderfully locally and on test servers');


test('reloads apps when SIGHUP is received', function () {
    // Arrange
    ['process' => $this->process, 'pid' => $pid, 'pipes' => $this->pipes] = start_server_and_wait_for_listening($this->serverScript, $this->host, $this->port);
    expect($pid)->toBeRunning();

    // Act
    posix_kill($pid, SIGHUP);
    $line = fgets($this->pipes[1]); // Read from stdout

    // Assert
    expect($line)->toContain('Caught SIGHUP in parent');
    expect($pid)->toBeRunning(); // Should still be running, we're not stopping the server, just restarting and reloading apps
});


test('supports SIGHUP multiple times', function () {
    // Arrange
    ['process' => $this->process, 'pid' => $pid, 'pipes' => $this->pipes] = start_server_and_wait_for_listening($this->serverScript, $this->host, $this->port);
    expect($pid)->toBeRunning();

    // Act
    posix_kill($pid, SIGHUP);
    $line = fgets($this->pipes[1]); // Read from stdout

    // Assert
    expect($line)->toContain('Caught SIGHUP in parent');
    expect($pid)->toBeRunning(); // Should still be running, we're not stopping the server, just restarting and reloading apps


    // Act again
    posix_kill($pid, SIGHUP);
    $line = fgets($this->pipes[1]); // Read from stdout

    // TODO: Test a new app in the 'apps' directory gets auto discovered

    // Assert
    expect($line)->toContain('Caught SIGHUP in parent');
    expect($pid)->toBeRunning(); // Should still be running, we're not stopping the server, just restarting and reloading apps
});

test('supports SIGUSR2 restarting server', function () {
    // Arrange
    ['process' => $this->process, 'pid' => $pid, 'pipes' => $this->pipes] = start_server_and_wait_for_listening($this->serverScript, $this->host, $this->port);
    expect($pid)->toBeRunning();

    // Act
    posix_kill($pid, SIGUSR2);

    // Assert
    expect(fgets($this->pipes[1]))->toContain('Caught SIGUSR2 in parent');
    expect(fgets($this->pipes[1]))->toContain('Shutting down...');
    expect(fgets($this->pipes[1]))->toContain('Terminating 0 child processes');
    expect(fgets($this->pipes[1]))->toContain('Server stopped');
    expect(fgets($this->pipes[1]))->toContain('Whisp listening on ');
    expect($pid)->toBeRunning(); // Should still be running, we're not stopping the server, just restarting and reloading apps

    // Act again
    posix_kill($pid, SIGUSR2);
    $line = fread($this->pipes[1], 8096); // Read from stdout

    // Assert
    expect($line)->toContain('Caught SIGUSR2 in parent');
    expect($pid)->toBeRunning(); // Should still be running, we're not stopping the server, just restarting and reloading apps
});
