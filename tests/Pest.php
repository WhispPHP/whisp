<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

// pest()->extend(Tests\TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

function inGithubActions(): bool
{
    return !empty(getenv('GITHUB_ACTIONS'));
}

function pidRunning(int $pid): bool
{
    $running = posix_kill($pid, 0);
    if (! $running) {
        return false;
    }

    $result = pcntl_waitpid($pid, $status, WNOHANG);
    if ($result === $pid) {
        return false;
    }

    return true;
}

expect()->extend('toBeRunning', function (int $maxMs = 300) {
    $pid = $this->value;
    $startTime = microtime(true);
    $running = null;

    while (microtime(true) - $startTime < $maxMs / 1000) {
        $running = pidRunning($pid);
        if ($running) {
            break;
        }

        usleep(10000); // Sleep 10ms between checks
    }

    expect($running)->toBeTrue("Process {$pid} isn't running within {$maxMs} ms");

    return $this;
});

expect()->extend('toNotBeRunning', function (int $maxMs = 1000) {
    $pid = $this->value;
    $startTime = microtime(true);
    $running = null;

    while (microtime(true) - $startTime < $maxMs / 1000) {
        $running = pidRunning($pid);
        if ($running === false) {
            break;
        }

        usleep(10000); // Sleep 10ms between checks
    }

    expect($running)->toBeFalse("Process {$pid} did not exit within {$maxMs} ms");

    return $this;
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

arch()
    ->expect('App')
    ->toUseStrictTypes()
    ->not->toUse(['die', 'dd', 'dump']);

/**
 * Wait for the server to start listening
 *
 * @param  resource  $stdout
 * @return bool
 */
function wait_for_server_to_start($stdout, string $host, int $port, int $maxMs = 300)
{
    // Wait for a maximum of 300ms, proceed once we've read '$host:$port' from the stdout of the server process
    $startTime = microtime(true);
    while (microtime(true) - $startTime < $maxMs / 1000) {
        $line = fgets($stdout);
        if (strpos($line, "listening on {$host}:{$port}") !== false) {
            return true;
        }
    }

    throw new RuntimeException('Server did not start within '.$maxMs.'ms');
}

/**
 * Start the server and wait for it to start listening
 *
 * @param  string  $script
 */
function start_server_and_wait_for_listening(string $scriptPath, string $host, int $port, int $maxMs = 300): array
{
    $descriptorSpec = [
        0 => ['pipe', 'r'],  // stdin
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w'],   // stderr
    ];

    $command = sprintf('"%s" %s %d %s', escapeshellcmd(PHP_BINARY), escapeshellarg($scriptPath), $port, escapeshellarg($host));
    $process = proc_open($command, $descriptorSpec, $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Failed to start server process');
    }

    wait_for_server_to_start($pipes[1], $host, $port, $maxMs);

    return ['process' => $process, 'pid' => proc_get_status($process)['pid'], 'pipes' => $pipes];
}
