<?php

declare(strict_types=1);

namespace PureFTP\Tests\Integration;

use FTP\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PureFTP\FtpClient;
use PureFTP\FtpEntry;
use PureFTP\FtpException;
use PureFTP\FtpListParser;

/**
 * Runs the same operations through ext-ftp and through FtpClient and compares the results.
 * ext-ftp is used only here, as a reference implementation.
 */
#[RequiresPhpExtension('ftp')]
final class ExtFtpComparisonTest extends IntegrationTestCase
{
    /**
     * ext-ftp has no implicit TLS, so that server is left out.
     *
     * @return iterable<string, array{string}>
     */
    public static function servers(): iterable
    {
        foreach (static::serverConfigs() as $name => $config) {
            if ($config['tls'] !== FtpClient::TLS_IMPLICIT) {
                yield $name => [$name];
            }
        }
    }

    private function ext(string $server): Connection
    {
        $config = static::serverConfigs()[$server];
        $connection = $config['tls'] === FtpClient::TLS_NONE
            ? ftp_connect(self::host(), $config['port'], 15)
            : ftp_ssl_connect(self::host(), $config['port'], 15);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertTrue(ftp_login($connection, self::USER, self::PASSWORD));
        ftp_set_option($connection, FTP_USEPASVADDRESS, false);
        self::assertTrue(ftp_pasv($connection, true));

        return $connection;
    }

    /**
     * Calls an ext-ftp function, turning its warnings into a false result.
     *
     * @template T
     * @param callable(): T $fn
     * @return T|false
     */
    private static function quiet(callable $fn): mixed
    {
        set_error_handler(static fn (): bool => true);
        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * ext-ftp's non-blocking data connect sometimes fails against Docker Desktop port forwarding
     * ("Operation now in progress"), so data operations of the reference client are retried.
     *
     * @template T
     * @param callable(): T $fn
     * @return T|false
     */
    private static function retry(callable $fn): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            $result = self::quiet($fn);
            if ($result !== false || $attempt === 3) {
                return $result;
            }
        }
    }

    #[DataProvider('servers')]
    public function testSameResults(string $server): void
    {
        $ours = $this->client($server);
        $dir = $this->workDir($ours);
        $ext = $this->ext($server);
        self::assertTrue(ftp_chdir($ext, $dir));

        self::assertSame(ftp_pwd($ext), $ours->pwd());

        // Upload with one client, download with the other.
        $payload = random_bytes(200000);
        $ours->putString('from ours.bin', $payload);
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, strrev($payload));
        rewind($stream);
        self::assertTrue(self::retry(static function () use ($ext, $stream) {
            rewind($stream);

            return ftp_fput($ext, 'from ext.bin', $stream, FTP_BINARY);
        }));
        self::assertTrue(self::retry(static function () use ($ext, $stream) {
            rewind($stream);
            ftruncate($stream, 0);

            return ftp_fget($ext, $stream, 'from ours.bin', FTP_BINARY);
        }));
        rewind($stream);
        self::assertSame($payload, stream_get_contents($stream));
        self::assertSame(strrev($payload), $ours->getString('from ext.bin'));

        $ours->putString('юникод file.txt', 'u');
        self::assertTrue(ftp_mkdir($ext, 'made by ext') !== false);
        self::assertTrue($ours->isDir('made by ext'));

        $extNames = self::retry(static fn () => ftp_nlist($ext, '.'));
        self::assertIsArray($extNames);
        $ourNames = $ours->nlist('.');
        sort($extNames);
        sort($ourNames);
        self::assertSame($extNames, $ourNames);

        foreach (['from ours.bin', 'from ext.bin', 'юникод file.txt'] as $file) {
            self::assertSame(ftp_size($ext, $file), $ours->size($file), $file);
            self::assertSame(ftp_mdtm($ext, $file), $ours->mdtm($file)->getTimestamp(), $file);
        }

        // LIST output fetched by ext-ftp, parsed by us, must match our own list().
        $raw = self::retry(static fn () => ftp_rawlist($ext, '.'));
        self::assertIsArray($raw);
        $ourList = $ours->list();
        $parsed = FtpListParser::parseList($raw);
        self::assertEqualsCanonicalizing(array_keys($parsed), array_keys($ourList));
        foreach ($parsed as $name => $entry) {
            self::assertSame($entry->type, $ourList[$name]->type, $name);
            if ($entry->isFile()) {
                self::assertSame($entry->size, $ourList[$name]->size, $name);
            }
        }

        if ($ours->hasFeature('MLST')) {
            $mlsd = self::retry(static fn () => ftp_mlsd($ext, '.'));
            self::assertIsArray($mlsd);
            $extFiles = [];
            foreach ($mlsd as $fact) {
                self::assertIsArray($fact);
                $type = $fact['type'] ?? '';
                $name = $fact['name'] ?? null;
                $size = $fact['size'] ?? $fact['sizd'] ?? '0';
                self::assertIsString($name);
                self::assertIsString($size);
                if ($type !== 'cdir' && $type !== 'pdir') {
                    $extFiles[$name] = (int) $size;
                }
            }
            $ourFiles = array_map(static fn (FtpEntry $e): int => $e->size ?? 0, $ourList);
            ksort($extFiles);
            ksort($ourFiles);
            self::assertSame($extFiles, $ourFiles);
        }

        self::assertSame(0640, ftp_chmod($ext, 0640, 'from ext.bin'));
        self::assertSame(0640, $ours->list()['from ext.bin']->mode);

        self::assertTrue(ftp_rename($ext, 'from ext.bin', 'made by ext/moved.bin'));
        self::assertSame(['moved.bin'], array_keys($ours->list('made by ext')));
        $ours->delete('made by ext/moved.bin');
        self::assertTrue(ftp_rmdir($ext, 'made by ext'));
        self::assertFalse($ours->isDir('made by ext'));

        self::quiet(static fn () => ftp_close($ext));
    }

    #[DataProvider('servers')]
    public function testSameFailures(string $server): void
    {
        $ours = $this->client($server);
        $dir = $this->workDir($ours);
        $ext = $this->ext($server);
        self::assertTrue(ftp_chdir($ext, $dir));
        $sink = fopen('php://temp', 'w+b');
        self::assertIsResource($sink);

        $cases = [
            'get missing' => [
                static fn () => ftp_fget($ext, $sink, 'missing', FTP_BINARY),
                static fn () => $ours->get('missing', $sink),
            ],
            'size missing' => [static fn () => ftp_size($ext, 'missing') !== -1, static fn () => $ours->size('missing')],
            'delete missing' => [static fn () => ftp_delete($ext, 'missing'), static fn () => $ours->delete('missing')],
            'chdir missing' => [static fn () => ftp_chdir($ext, 'missing'), static fn () => $ours->chdir('missing')],
            'rmdir missing' => [static fn () => ftp_rmdir($ext, 'missing'), static fn () => $ours->rmdir('missing')],
            'rename missing' => [
                static fn () => ftp_rename($ext, 'missing', 'x'),
                static fn () => $ours->rename('missing', 'x'),
            ],
        ];
        foreach ($cases as $name => [$extCall, $ourCall]) {
            self::assertFalse(self::quiet($extCall), 'ext-ftp: ' . $name);
            try {
                $ourCall();
                self::fail('FtpClient did not fail: ' . $name);
            } catch (FtpException $e) {
                self::assertGreaterThanOrEqual(500, $e->getReplyCode(), $name);
            }
        }

        // Both stay usable after the failures.
        self::assertSame(ftp_pwd($ext), $ours->pwd());
        self::quiet(static fn () => ftp_close($ext));
    }
}
