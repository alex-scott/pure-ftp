<?php

declare(strict_types=1);

namespace PureFTP\Tests\Integration;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PureFTP\FtpClient;
use PureFTP\FtpException;

final class ServerTest extends IntegrationTestCase
{
    #[DataProvider('servers')]
    public function testDirectories(string $server): void
    {
        $client = $this->client($server);
        $dir = $this->workDir($client);
        $base = $client->pwd();
        self::assertStringEndsWith('/' . $dir, $base);

        $client->mkdirRecursive('a/b/c');
        $client->mkdirRecursive('a/b/c');
        $client->chdir('a/b/c');
        self::assertSame($base . '/a/b/c', $client->pwd());
        $client->cdup();
        self::assertSame($base . '/a/b', $client->pwd());
        $client->chdir($base);
        self::assertTrue($client->isDir('a/b'));
        self::assertFalse($client->isDir('a/missing'));
        self::assertSame($base, $client->pwd());

        $client->rmdir('a/b/c');
        self::assertFalse($client->isDir('a/b/c'));
    }

    #[DataProvider('servers')]
    public function testTransfersAndListings(string $server): void
    {
        $client = $this->client($server);
        $this->workDir($client);

        $allBytes = '';
        for ($i = 0; $i < 256; $i++) {
            $allBytes .= chr($i);
        }
        $files = [
            'empty.txt' => '',
            'bytes.bin' => str_repeat($allBytes, 33),
            'name with spaces.txt' => "spaces\n",
            'юникод файл ü.txt' => "unicode\n",
        ];
        foreach ($files as $name => $contents) {
            $client->putString($name, $contents);
        }
        $client->mkdir('sub dir');

        foreach ($files as $name => $contents) {
            self::assertSame($contents, $client->getString($name), $name);
            self::assertSame(strlen($contents), $client->size($name), $name);
        }

        $nlist = array_map('basename', $client->nlist());
        sort($nlist);
        $expected = [...array_keys($files), 'sub dir'];
        sort($expected);
        self::assertSame($expected, $nlist);

        $list = $client->list();
        $names = array_keys($list);
        sort($names, SORT_STRING);
        self::assertSame($expected, $names);
        self::assertTrue($list['sub dir']->isDir());
        self::assertTrue($list['bytes.bin']->isFile());
        self::assertSame(256 * 33, $list['bytes.bin']->size);
        self::assertNotNull($list['bytes.bin']->modified);
        self::assertSame($client->hasFeature('MLST'), static::serverConfigs()[$server]['mlsd']);

        $mdtm = $client->mdtm('bytes.bin');
        self::assertEqualsWithDelta((new DateTimeImmutable())->getTimestamp(), $mdtm->getTimestamp(), 300);
    }

    #[DataProvider('servers')]
    public function testLargeFileIsStreamed(string $server): void
    {
        $megabytes = (int) (getenv('FTP_TEST_LARGE_MB') ?: 50);
        $client = $this->client($server);
        $this->workDir($client);

        $local = (string) tempnam(sys_get_temp_dir(), 'ftp-large');
        $back = (string) tempnam(sys_get_temp_dir(), 'ftp-large');
        try {
            $out = fopen($local, 'wb');
            self::assertIsResource($out);
            $block = random_bytes(1 << 20);
            for ($i = 0; $i < $megabytes; $i++) {
                fwrite($out, $block);
            }
            fclose($out);
            unset($block);

            memory_reset_peak_usage();
            $before = memory_get_usage();
            $client->put($local, 'large.bin');
            $client->get('large.bin', $back);
            $peak = memory_get_peak_usage() - $before;

            self::assertSame($megabytes << 20, $client->size('large.bin'));
            self::assertSame(hash_file('sha256', $local), hash_file('sha256', $back));
            self::assertLessThan(4 << 20, $peak, 'Transfers must not buffer the file in memory');
        } finally {
            unlink($local);
            unlink($back);
        }
    }

    #[DataProvider('servers')]
    public function testChmodRenameDelete(string $server): void
    {
        $client = $this->client($server);
        $this->workDir($client);
        $client->putString('file.txt', 'x');
        $client->mkdir('dir');

        self::assertTrue($client->chmod(0600, 'file.txt'));
        self::assertSame(0600, $client->list()['file.txt']->mode);
        self::assertTrue($client->chmod(0755, 'file.txt'));
        self::assertSame(0755, $client->list()['file.txt']->mode);
        self::assertTrue($client->chmod(0700, 'dir'));
        self::assertSame(0700, $client->list()['dir']->mode);

        $client->rename('file.txt', 'dir/renamed file.txt');
        self::assertSame(['renamed file.txt'], array_keys($client->list('dir')));
        $client->delete('dir/renamed file.txt');
        $client->rmdir('dir');
        self::assertSame([], $client->list());
    }

    #[DataProvider('servers')]
    public function testErrors(string $server): void
    {
        $client = $this->client($server);
        $this->workDir($client);

        $this->assertFtpError(550, static fn () => $client->getString('missing.txt'));
        $this->assertFtpError(550, static fn () => $client->size('missing.txt'));
        $this->assertFtpError(550, static fn () => $client->delete('missing.txt'));
        $this->assertFtpError(550, static fn () => $client->chdir('missing'));

        $client->mkdir('readonly');
        self::assertTrue($client->chmod(0555, 'readonly'));
        $this->assertFtpError(null, static fn () => $client->putString('readonly/x.txt', 'x'));
        $this->assertFtpError(550, static fn () => $client->mkdir('readonly/sub'));
        self::assertTrue($client->chmod(0755, 'readonly'));

        // The control connection is still usable after the errors.
        self::assertNotSame('', $client->pwd());
    }

    #[DataProvider('servers')]
    public function testWrongPassword(string $server): void
    {
        $client = $this->client($server, login: false);
        try {
            $client->login(self::USER, 'wrong');
            self::fail('Expected exception');
        } catch (FtpException $e) {
            self::assertSame(530, $e->getReplyCode());
        } finally {
            $client->close();
        }
    }

    /**
     * @param callable(): mixed $call
     */
    private function assertFtpError(?int $code, callable $call): void
    {
        try {
            $call();
            self::fail('Expected FtpException');
        } catch (FtpException $e) {
            $code === null
                ? self::assertGreaterThanOrEqual(450, $e->getReplyCode())
                : self::assertSame($code, $e->getReplyCode(), $e->getMessage());
        }
    }

    public function testPasvAddressIsIgnoredByDefault(): void
    {
        $this->requireServer(2125);
        $client = new FtpClient(useEpsv: false, usePasvAddress: true);
        $client->connect(self::host(), 2125, 2);
        $client->login(self::USER, self::PASSWORD);
        try {
            $client->nlist();
            self::fail('Expected the announced address 10.255.255.1 to be unreachable');
        } catch (FtpException $e) {
            self::assertStringContainsString('10.255.255.1', $e->getMessage());
        }
    }
}
