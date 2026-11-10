<?php

declare(strict_types=1);

namespace PureFTP\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PureFTP\FtpClient;
use PureFTP\FtpException;

/**
 * Tests against the servers from docker-compose.yml. Skipped when a server is not reachable.
 *
 * Environment: FTP_TEST_HOST (default 127.0.0.1).
 */
abstract class IntegrationTestCase extends TestCase
{
    public const USER = 'ftpuser';
    public const PASSWORD = 'ftppass';

    /** @var list<array{FtpClient, string}> clients and the work directories to remove */
    private array $cleanup = [];

    /**
     * @return array<string, array{port: int, tls: FtpClient::TLS_*, useEpsv?: bool, mlsd: bool}>
     */
    public static function serverConfigs(): array
    {
        return [
            'vsftpd' => ['port' => 2121, 'tls' => FtpClient::TLS_NONE, 'mlsd' => false],
            'vsftpd explicit TLS' => ['port' => 2121, 'tls' => FtpClient::TLS_EXPLICIT, 'mlsd' => false],
            'vsftpd require_ssl_reuse' => ['port' => 2122, 'tls' => FtpClient::TLS_EXPLICIT, 'mlsd' => false],
            'proftpd' => ['port' => 2123, 'tls' => FtpClient::TLS_NONE, 'mlsd' => true],
            'proftpd explicit TLS' => ['port' => 2123, 'tls' => FtpClient::TLS_EXPLICIT, 'mlsd' => true],
            'proftpd implicit TLS' => ['port' => 2990, 'tls' => FtpClient::TLS_IMPLICIT, 'mlsd' => true],
            'proftpd without MLSD' => ['port' => 2124, 'tls' => FtpClient::TLS_NONE, 'mlsd' => false],
            'pure-ftpd' => ['port' => 2125, 'tls' => FtpClient::TLS_NONE, 'mlsd' => true],
            'pure-ftpd PASV with wrong address' => [
                'port' => 2125, 'tls' => FtpClient::TLS_NONE, 'useEpsv' => false, 'mlsd' => true,
            ],
        ];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function servers(): iterable
    {
        foreach (array_keys(static::serverConfigs()) as $name) {
            yield $name => [$name];
        }
    }

    protected static function host(): string
    {
        $host = getenv('FTP_TEST_HOST');

        return is_string($host) && $host !== '' ? $host : '127.0.0.1';
    }

    protected static function caFile(): string
    {
        return dirname(__DIR__) . '/docker/certs/server.crt';
    }

    protected function requireServer(int $port): void
    {
        set_error_handler(static fn (): bool => true);
        try {
            $socket = stream_socket_client(sprintf('tcp://%s:%d', self::host(), $port), $errno, $errstr, 1);
        } finally {
            restore_error_handler();
        }
        if ($socket === false) {
            self::markTestSkipped(sprintf('No FTP server on %s:%d; run "docker compose up -d --build --wait"', self::host(), $port));
        }
        fclose($socket);
    }

    protected function client(string $server, bool $login = true): FtpClient
    {
        $config = static::serverConfigs()[$server];
        $this->requireServer($config['port']);
        $client = new FtpClient(tls: $config['tls'], caFile: self::caFile(), useEpsv: $config['useEpsv'] ?? true);
        $client->connect(self::host(), $config['port'], 15);
        if ($login) {
            $client->login(self::USER, self::PASSWORD);
        }

        return $client;
    }

    /**
     * Creates a unique directory, changes into it and removes it after the test.
     */
    protected function workDir(FtpClient $client): string
    {
        $dir = 'pureftp-test-' . bin2hex(random_bytes(6));
        $client->mkdir($dir);
        $client->chdir($dir);
        $this->cleanup[] = [$client, $client->pwd()];

        return $dir;
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as [$client, $dir]) {
            try {
                if ($client->isConnected()) {
                    $client->chdir('/');
                    $this->removeTree($client, $dir);
                    $client->close();
                }
            } catch (FtpException) {
            }
        }
        $this->cleanup = [];
    }

    private function removeTree(FtpClient $client, string $dir): void
    {
        $client->chmod(0755, $dir);
        foreach ($client->list($dir) as $name => $entry) {
            $path = $dir . '/' . $name;
            if ($entry->isDir()) {
                $this->removeTree($client, $path);
            } else {
                $client->delete($path);
            }
        }
        $client->rmdir($dir);
    }
}
