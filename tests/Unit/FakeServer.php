<?php

declare(strict_types=1);

namespace PureFTP\Tests\Unit;

use RuntimeException;

/**
 * Runs tests/fake-server.php in a child process.
 */
final class FakeServer
{
    /** @var resource */
    private $process;
    /** @var array<int, resource> */
    private array $pipes;
    private string $scenarioFile;
    public readonly int $port;

    /**
     * @param list<array<string, mixed>> $steps
     */
    public function __construct(array $steps)
    {
        $this->scenarioFile = (string) tempnam(sys_get_temp_dir(), 'ftp-scenario');
        file_put_contents($this->scenarioFile, json_encode($steps, JSON_THROW_ON_ERROR));
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/fake-server.php', $this->scenarioFile],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if ($process === false) {
            throw new RuntimeException('Unable to start the fake server');
        }
        $this->process = $process;
        $this->pipes = $pipes;
        stream_set_timeout($pipes[1], 5);
        $port = fgets($pipes[1]);
        if ($port === false) {
            throw new RuntimeException('Fake server did not start: ' . stream_get_contents($pipes[2]));
        }
        $this->port = (int) $port;
    }

    /**
     * Waits for the server to finish and returns its error output ('' when the scenario ran as written).
     */
    public function finish(): string
    {
        $errors = (string) stream_get_contents($this->pipes[2]);
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        $code = proc_close($this->process);
        unlink($this->scenarioFile);

        return $code === 0 ? '' : ($errors !== '' ? $errors : 'fake server exited with ' . $code);
    }
}
