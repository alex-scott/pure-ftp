<?php

declare(strict_types=1);

namespace PureFTP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PureFTP\FtpClient;
use PureFTP\FtpException;
use Psr\Log\AbstractLogger;
use Stringable;

final class FtpClientTest extends TestCase
{
    private ?FakeServer $server = null;

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            $this->server->finish();
            $this->server = null;
        }
    }

    /**
     * @param list<string> $features
     * @return list<array<string, mixed>>
     */
    private static function login(array $features = ['EPSV', 'SIZE', 'MDTM', 'UTF8']): array
    {
        $steps = [
            ['send' => '220 Fake FTP ready'],
            ['expect' => 'USER bob'],
            ['send' => '331 Password required'],
            ['expect' => 'PASS secret'],
            ['send' => '230 Logged in'],
            ['expect' => 'FEAT'],
            ['send' => '211-Features:'],
        ];
        foreach ($features as $feature) {
            $steps[] = ['send' => ' ' . $feature];
        }
        $steps[] = ['send' => '211 End'];
        if (in_array('UTF8', $features, true)) {
            $steps[] = ['expect' => 'OPTS UTF8 ON'];
            $steps[] = ['send' => '200 UTF8 enabled'];
        }
        $steps[] = ['expect' => 'TYPE I'];
        $steps[] = ['send' => '200 Binary'];

        return $steps;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function quit(): array
    {
        return [['expect' => 'QUIT'], ['send' => '221 Bye']];
    }

    /**
     * @param list<array<string, mixed>> $steps
     */
    private function connect(array $steps, ?FtpClient $client = null, float $timeout = 5.0): FtpClient
    {
        $this->server = new FakeServer($steps);
        $client ??= new FtpClient();
        $client->connect('127.0.0.1', $this->server->port, $timeout);

        return $client;
    }

    /**
     * @param list<array<string, mixed>> $steps
     */
    private function connectAndLogin(array $steps, ?FtpClient $client = null): FtpClient
    {
        $client = $this->connect($steps, $client);
        $client->login('bob', 'secret');

        return $client;
    }

    private function assertScenarioPassed(FtpClient $client): void
    {
        $client->close();
        $server = $this->server;
        self::assertNotNull($server);
        $this->server = null;
        self::assertSame('', $server->finish());
    }

    public function testMultilineRepliesAndLogin(): void
    {
        $steps = [
            ['send' => '220-Welcome'],
            ['send' => '220-220 appears inside the text'],
            ['send' => ' 220 indented'],
            ['send' => '200 another code'],
            ['send' => '220 Ready'],
            ...array_slice(self::login(['MLST type*;size*;modify*;', 'EPSV', 'UTF8', 'REST STREAM']), 1),
            ['expect' => 'PWD'],
            ['send' => '257 "/home/""quoted""" is current directory'],
            ...self::quit(),
        ];
        $this->server = new FakeServer($steps);
        $client = new FtpClient();
        $greeting = $client->connect('127.0.0.1', $this->server->port, 5);
        self::assertSame(220, $greeting->code);
        self::assertCount(5, $greeting->lines);
        self::assertSame("Welcome\n220-220 appears inside the text\n 220 indented\n200 another code\nReady", $greeting->text());

        $client->login('bob', 'secret');
        self::assertTrue($client->hasFeature('mlst'));
        self::assertTrue($client->hasFeature('REST'));
        self::assertSame('type*;size*;modify*;', $client->getFeatures()['MLST']);
        self::assertSame('/home/"quoted"', $client->pwd());
        $this->assertScenarioPassed($client);
    }

    public function testLoginWithoutPassword(): void
    {
        $client = $this->connect([
            ['send' => '220 Ready'],
            ['expect' => 'USER anonymous'],
            ['send' => '230 Welcome'],
            ['expect' => 'FEAT'],
            ['send' => '500 Unknown command'],
            ['expect' => 'TYPE I'],
            ['send' => '200 OK'],
            ...self::quit(),
        ]);
        self::assertSame(230, $client->login()->code);
        self::assertSame([], $client->getFeatures());
        $this->assertScenarioPassed($client);
    }

    public function testLoginFailure(): void
    {
        $client = $this->connect([
            ['send' => '220 Ready'],
            ['expect' => 'USER bob'],
            ['send' => '331 Password required'],
            ['expect' => 'PASS wrong'],
            ['send' => '530 Login incorrect.'],
            ...self::quit(),
        ]);
        try {
            $client->login('bob', 'wrong');
            self::fail('Expected exception');
        } catch (FtpException $e) {
            self::assertSame(530, $e->getReplyCode());
            self::assertSame(530, $e->getCode());
            self::assertSame('Login incorrect.', $e->getReplyText());
            self::assertStringContainsString('Login failed', $e->getMessage());
        }
        $this->assertScenarioPassed($client);
    }

    public function testAccountNotSupported(): void
    {
        $client = $this->connect([
            ['send' => '220 Ready'],
            ['expect' => 'USER bob'],
            ['send' => '332 Need account'],
            ...self::quit(),
        ]);
        $this->expectExceptionMessageMatches('/ACCT/');
        try {
            $client->login('bob', 'x');
        } finally {
            $this->assertScenarioPassed($client);
        }
    }

    public function testPasswordIsNotLogged(): void
    {
        $logger = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->lines[] = (string) $message;
            }
        };
        $client = new FtpClient();
        $client->setLogger($logger);
        $client = $this->connectAndLogin([...self::login(), ...self::quit()], $client);
        $this->assertScenarioPassed($client);

        $log = implode("\n", $logger->lines);
        self::assertStringContainsString('> PASS ****', $log);
        self::assertStringContainsString('> USER bob', $log);
        self::assertStringNotContainsString('secret', $log);
    }

    public function testEpsvRefusedFallsBackToPasvAndIgnoresPasvAddress(): void
    {
        $client = $this->connectAndLogin([
            ...self::login(),
            ['passive' => true],
            ['expect' => 'EPSV'],
            ['send' => '500 EPSV not understood'],
            ['expect' => 'PASV'],
            ['send' => '227 Entering Passive Mode (10,255,255,1,{p1},{p2}).'],
            ['expect' => 'NLST'],
            ['send' => '150 Here comes the listing'],
            ['accept' => true],
            ['dataSend' => "a.txt\r\nb c.txt\r\n.\r\n..\r\n"],
            ['dataClose' => true],
            ['send' => '226 Done'],
            // Second listing goes straight to PASV.
            ['passive' => true],
            ['expect' => 'PASV'],
            ['send' => '227 Entering Passive Mode (192,168,0,1,{p1},{p2})'],
            ['expect' => 'NLST sub dir'],
            ['send' => '125 Transfer starting'],
            ['accept' => true],
            ['dataClose' => true],
            ['send' => '250 OK'],
            ...self::quit(),
        ]);
        self::assertSame(['a.txt', 'b c.txt'], $client->nlist());
        self::assertSame([], $client->nlist('sub dir'));
        $this->assertScenarioPassed($client);
    }

    public function testUsePasvAddress(): void
    {
        $client = $this->connectAndLogin([
            ...self::login(),
            ['passive' => true],
            ['expect' => 'PASV'],
            ['send' => '227 Entering Passive Mode (127,0,0,1,{p1},{p2})'],
            ['expect' => 'RETR f'],
            ['send' => '150 OK'],
            ['accept' => true],
            ['dataSend' => 'hello'],
            ['dataClose' => true],
            ['send' => '226 Done'],
            ...self::quit(),
        ], new FtpClient(usePasvAddress: true, useEpsv: false));
        self::assertSame('hello', $client->getString('f'));
        $this->assertScenarioPassed($client);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badPasvReplies(): iterable
    {
        yield 'port zero' => ['227 Entering Passive Mode (127,0,0,1,0,0)'];
        yield 'octet over 255' => ['227 Entering Passive Mode (127,0,0,1,256,1)'];
        yield 'garbage' => ['227 Entering Passive Mode'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badPasvReplies')]
    public function testInvalidPasvReplyIsRejected(string $reply): void
    {
        $client = $this->connectAndLogin([
            ...self::login(),
            ['expect' => 'PASV'],
            ['send' => $reply],
            ...self::quit(),
        ], new FtpClient(useEpsv: false));
        try {
            $client->nlist();
            self::fail('Expected exception');
        } catch (FtpException $e) {
            self::assertSame(227, $e->getReplyCode());
        }
        $this->assertScenarioPassed($client);
    }

    public function testDownloadWithEpsvAnd125(): void
    {
        $payload = '';
        for ($i = 0; $i < 256; $i++) {
            $payload .= chr($i);
        }
        $payload = str_repeat($payload, 1000);
        $client = $this->connectAndLogin([
            ...self::login(),
            ['passive' => true],
            ['expect' => 'EPSV'],
            ['send' => '229 Entering Extended Passive Mode (|||{port}|)'],
            ['expect' => 'RETR dir/file name.bin'],
            ['send' => '125 Data connection already open'],
            ['accept' => true],
            ['dataSendB64' => base64_encode($payload)],
            ['dataClose' => true],
            ['send' => '226 Transfer complete'],
            ['passive' => true],
            ['expect' => 'EPSV'],
            ['send' => '229 Entering Extended Passive Mode (!!!{port}!)'],
            ['expect' => 'RETR other'],
            ['send' => '150 Opening'],
            ['accept' => true],
            ['dataSend' => 'second'],
            ['dataClose' => true],
            ['send' => '226 Transfer complete'],
            ...self::quit(),
        ]);
        $local = (string) tempnam(sys_get_temp_dir(), 'ftp');
        try {
            $client->get('dir/file name.bin', $local);
            self::assertSame($payload, file_get_contents($local));
        } finally {
            unlink($local);
        }
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $client->get('other', $stream);
        rewind($stream);
        self::assertSame('second', stream_get_contents($stream));
        $this->assertScenarioPassed($client);
    }

    public function testUpload(): void
    {
        $payload = random_bytes(300000);
        $client = $this->connectAndLogin([
            ...self::login(),
            ['passive' => true],
            ['expect' => 'EPSV'],
            ['send' => '229 Entering Extended Passive Mode (|||{port}|)'],
            ['expect' => 'STOR upload.bin'],
            ['send' => '150 Ok to send data'],
            ['accept' => true],
            ['dataExpectSha1' => sha1($payload)],
            ['dataClose' => true],
            ['send' => '226 Transfer complete'],
            ['passive' => true],
            ['expect' => 'EPSV'],
            ['send' => '229 Entering Extended Passive Mode (|||{port}|)'],
            ['expect' => 'STOR empty.txt'],
            ['send' => '150 Ok to send data'],
            ['accept' => true],
            ['dataExpect' => ''],
            ['dataClose' => true],
            ['send' => '226 Transfer complete'],
            ...self::quit(),
        ]);
        $local = (string) tempnam(sys_get_temp_dir(), 'ftp');
        file_put_contents($local, $payload);
        try {
            $client->put($local, 'upload.bin');
        } finally {
            unlink($local);
        }
        $client->putString('empty.txt', '');
        $this->assertScenarioPassed($client);
    }

    public function testDataConnectionRefused425(): void
    {
        $client = $this->connectAndLogin([
            ...self::login(),
            ['passive' => true],
            ['expect' => 'EPSV'],
            ['send' => '229 Entering Extended Passive Mode (|||{port}|)'],
            ['expect' => 'RETR missing'],
            ['send' => "425 Can't open data connection."],
            ['expect' => 'NOOP'],
            ['send' => '200 OK'],
            ...self::quit(),
        ]);
        $local = sys_get_temp_dir() . '/ftp-' . bin2hex(random_bytes(4));
        try {
            $client->get('missing', $local);
            self::fail('Expected exception');
        } catch (FtpException $e) {
            self::assertSame(425, $e->getReplyCode());
            self::assertFileDoesNotExist($local);
        }
        self::assertSame(200, $client->raw('NOOP')->code);
        $this->assertScenarioPassed($client);
    }

    public function testTransferAborted426KeepsControlConnectionInSync(): void
    {
        $client = $this->connectAndLogin([
            ...self::login(),
            ['passive' => true],
            ['expect' => 'EPSV'],
            ['send' => '229 Entering Extended Passive Mode (|||{port}|)'],
            ['expect' => 'RETR f'],
            ['send' => '150 OK'],
            ['accept' => true],
            ['dataSend' => 'partial'],
            ['dataClose' => true],
            ['send' => '426 Connection closed; transfer aborted.'],
            ['expect' => 'PWD'],
            ['send' => '257 "/"'],
            ...self::quit(),
        ]);
        try {
            $client->getString('f');
            self::fail('Expected exception');
        } catch (FtpException $e) {
            self::assertSame(426, $e->getReplyCode());
        }
        self::assertSame('/', $client->pwd());
        $this->assertScenarioPassed($client);
    }

    public function testTimeoutWhileReadingReply(): void
    {
        $this->server = new FakeServer([['sleep' => 1.5], ['send' => '220 Late']]);
        $client = new FtpClient();
        try {
            $client->connect('127.0.0.1', $this->server->port, 0.5);
            self::fail('Expected exception');
        } catch (FtpException $e) {
            self::assertStringContainsString('Timed out after 0.5 seconds', $e->getMessage());
        }
        self::assertFalse($client->isConnected());
    }

    public function testEofInsideMultilineReply(): void
    {
        $this->server = new FakeServer([['send' => '220-Hello'], ['close' => true]]);
        $this->expectExceptionMessage('Connection closed by the server');
        (new FtpClient())->connect('127.0.0.1', $this->server->port, 5);
    }

    public function testLineTooLong(): void
    {
        $this->server = new FakeServer([['send' => '220 ' . str_repeat('a', 9000)]]);
        $this->expectExceptionMessage('longer than 8192 bytes');
        (new FtpClient())->connect('127.0.0.1', $this->server->port, 5);
    }

    public function testTooManyLines(): void
    {
        $steps = [['send' => '220-start']];
        for ($i = 0; $i < 60; $i++) {
            $steps[] = ['send' => ' line ' . $i];
        }
        $steps[] = ['send' => '220 end'];
        $this->server = new FakeServer($steps);
        $this->expectExceptionMessage('more than 50 lines');
        (new FtpClient(maxReplyLines: 50))->connect('127.0.0.1', $this->server->port, 5);
    }

    public function testMalformedReply(): void
    {
        $this->server = new FakeServer([['send' => 'HTTP/1.1 400 Bad Request']]);
        $this->expectExceptionMessage('Malformed reply');
        (new FtpClient())->connect('127.0.0.1', $this->server->port, 5);
    }

    public function testControlCharactersAreRejectedBeforeSending(): void
    {
        $client = $this->connectAndLogin([...self::login(), ...self::quit()]);
        $calls = [
            static fn () => $client->chdir("a\r\nDELE x"),
            static fn () => $client->putString("a\nb", 'x'),
            static fn () => $client->getString("a\0b"),
            static fn () => $client->list("a\rb"),
            static fn () => $client->rename('a', "b\nDELE c"),
            static fn () => $client->mkdirRecursive("a/b\n"),
            static fn () => $client->raw("NOOP\r\nDELE x"),
            static fn () => $client->login("bob\r\n", 'x'),
            static fn () => $client->login('bob', "x\nDELE y"),
        ];
        foreach ($calls as $i => $call) {
            try {
                $call();
                self::fail('Call ' . $i . ' was not rejected');
            } catch (FtpException $e) {
                self::assertStringContainsString('must not contain CR, LF or NUL', $e->getMessage());
            }
        }
        $this->assertScenarioPassed($client);
    }

    public function testListUsesMlsd(): void
    {
        $client = $this->connectAndLogin([
            ...self::login(['MLST type*;size*;modify*;unix.mode*;', 'EPSV']),
            ['passive' => true],
            ['expect' => 'EPSV'],
            ['send' => '229 Entering Extended Passive Mode (|||{port}|)'],
            ['expect' => 'MLSD pub'],
            ['send' => '150 Accepted data connection'],
            ['accept' => true],
            ['dataSend' => "type=cdir;modify=20240101000000; .\r\n"
                . "type=pdir;modify=20240101000000; ..\r\n"
                . "type=file;size=12;modify=20240115102030;UNIX.mode=0644; read me.txt\r\n"
                . "Type=DIR;Modify=20230102030405;unix.mode=0755; docs\r\n"],
            ['dataClose' => true],
            ['send' => '226 Options: -a -l'],
            ...self::quit(),
        ]);
        $entries = $client->list('pub');
        self::assertSame(['read me.txt', 'docs'], array_keys($entries));
        self::assertTrue($entries['read me.txt']->isFile());
        self::assertSame(12, $entries['read me.txt']->size);
        self::assertSame(0644, $entries['read me.txt']->mode);
        self::assertTrue($entries['docs']->isDir());
        $this->assertScenarioPassed($client);
    }

    public function testListFallsBackToListWhenMlsdIsNotAdvertised(): void
    {
        $client = $this->connectAndLogin([
            ...self::login(['EPSV']),
            ['passive' => true],
            ['expect' => 'EPSV'],
            ['send' => '229 Entering Extended Passive Mode (|||{port}|)'],
            ['expect' => 'LIST'],
            ['send' => '150 Here comes the directory listing'],
            ['accept' => true],
            ['dataSend' => "total 8\r\n-rw-r--r--    1 1000     1000           12 Jan 15  2024 a b.txt\r\n"
                . "drwxr-xr-x    2 1000     1000         4096 Jan 15  2024 dir\r\n"],
            ['dataClose' => true],
            ['send' => '226 Directory send OK.'],
            ...self::quit(),
        ]);
        $entries = $client->list();
        self::assertSame(['a b.txt', 'dir'], array_keys($entries));
        self::assertSame(12, $entries['a b.txt']->size);
        self::assertTrue($entries['dir']->isDir());
        $this->assertScenarioPassed($client);
    }

    public function testListFallsBackWhenMlsdIsRejected(): void
    {
        $client = $this->connectAndLogin([
            ...self::login(['MLST type*;', 'EPSV']),
            ['passive' => true],
            ['expect' => 'EPSV'],
            ['send' => '229 Entering Extended Passive Mode (|||{port}|)'],
            ['expect' => 'MLSD'],
            ['send' => '500 Unknown command'],
            ['passive' => true],
            ['expect' => 'EPSV'],
            ['send' => '229 Entering Extended Passive Mode (|||{port}|)'],
            ['expect' => 'LIST'],
            ['send' => '150 OK'],
            ['accept' => true],
            ['dataSend' => "01-15-24  10:20AM       <DIR>          folder\r\n"],
            ['dataClose' => true],
            ['send' => '226 OK'],
            ...self::quit(),
        ]);
        self::assertTrue($client->list()['folder']->isDir());
        $this->assertScenarioPassed($client);
    }

    public function testSimpleCommands(): void
    {
        $client = $this->connectAndLogin([
            ...self::login(),
            ['expect' => 'SIZE big.iso'],
            ['send' => '213 5368709120'],
            ['expect' => 'MDTM big.iso'],
            ['send' => '213 20240115102030.123'],
            ['expect' => 'RNFR a'],
            ['send' => '350 Ready for RNTO'],
            ['expect' => 'RNTO b c'],
            ['send' => '250 Rename successful'],
            ['expect' => 'SITE CHMOD 644 b c'],
            ['send' => '200 SITE CHMOD command ok'],
            ['expect' => 'SITE CHMOD 755 x'],
            ['send' => '500 Unknown SITE command'],
            ['expect' => 'DELE b c'],
            ['send' => '250 Deleted'],
            ['expect' => 'CWD /tmp'],
            ['send' => '250 OK'],
            ['expect' => 'CDUP'],
            ['send' => '200 OK'],
            ['expect' => 'RMD old'],
            ['send' => '550 Permission denied'],
            ...self::quit(),
        ]);
        self::assertSame(5368709120, $client->size('big.iso'));
        self::assertSame('2024-01-15T10:20:30.123+00:00', $client->mdtm('big.iso')->format('Y-m-d\TH:i:s.vP'));
        $client->rename('a', 'b c');
        self::assertTrue($client->chmod(0644, 'b c'));
        self::assertFalse($client->chmod(0755, 'x'));
        $client->delete('b c');
        $client->chdir('/tmp');
        $client->cdup();
        try {
            $client->rmdir('old');
            self::fail('Expected exception');
        } catch (FtpException $e) {
            self::assertSame(550, $e->getReplyCode());
        }
        $this->assertScenarioPassed($client);
    }

    public function testMkdirRecursive(): void
    {
        $client = $this->connectAndLogin([
            ...self::login(),
            ['expect' => 'MKD a'],
            ['send' => '550 Create directory operation failed.'],
            ['expect' => 'PWD'],
            ['send' => '257 "/home"'],
            ['expect' => 'CWD a'],
            ['send' => '250 OK'],
            ['expect' => 'CWD /home'],
            ['send' => '250 OK'],
            ['expect' => 'MKD a/b'],
            ['send' => '257 "/home/a/b" created'],
            ['expect' => 'MKD a/b/c'],
            ['send' => '257 "/home/a/b/c" created'],
            ['expect' => 'MKD /x'],
            ['send' => '550 Permission denied'],
            ['expect' => 'PWD'],
            ['send' => '257 "/home"'],
            ['expect' => 'CWD /x'],
            ['send' => '550 No such directory'],
            ...self::quit(),
        ]);
        $client->mkdirRecursive('a//b/./c/');
        try {
            $client->mkdirRecursive('/x/y');
            self::fail('Expected exception');
        } catch (FtpException $e) {
            self::assertSame(550, $e->getReplyCode());
            self::assertStringContainsString('"/x"', $e->getMessage());
        }
        $this->assertScenarioPassed($client);
    }

    public function testNotConnected(): void
    {
        $this->expectExceptionMessage('Not connected');
        (new FtpClient())->pwd();
    }
}
