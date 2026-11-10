<?php

declare(strict_types=1);

namespace PureFTP\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PureFTP\FtpClient;
use PureFTP\FtpException;

final class TlsTest extends IntegrationTestCase
{
    /**
     * @return iterable<string, array{int, FtpClient::TLS_*}>
     */
    public static function tlsServers(): iterable
    {
        yield 'vsftpd explicit' => [2121, FtpClient::TLS_EXPLICIT];
        yield 'proftpd explicit' => [2123, FtpClient::TLS_EXPLICIT];
        yield 'proftpd implicit' => [2990, FtpClient::TLS_IMPLICIT];
    }

    /**
     * @param FtpClient::TLS_* $tls
     */
    #[DataProvider('tlsServers')]
    public function testSelfSignedCertificateIsRejectedByDefault(int $port, string $tls): void
    {
        $this->requireServer($port);
        $client = new FtpClient(tls: $tls);
        try {
            $client->connect(self::host(), $port, 10);
            self::fail('Expected certificate verification to fail');
        } catch (FtpException $e) {
            self::assertStringContainsString('certificate verify failed', $e->getMessage());
            self::assertStringContainsString('caFile', $e->getMessage());
            self::assertStringContainsString('allowSelfSigned', $e->getMessage());
        }
        self::assertFalse($client->isConnected());
    }

    /**
     * @param FtpClient::TLS_* $tls
     */
    #[DataProvider('tlsServers')]
    public function testAllowSelfSigned(int $port, string $tls): void
    {
        $this->requireServer($port);
        $client = new FtpClient(tls: $tls, allowSelfSigned: true);
        $client->connect(self::host(), $port, 10);
        $client->login(self::USER, self::PASSWORD);
        $client->nlist();
        self::assertSame(226, $client->getLastReply()?->code);
        $client->close();
    }

    public function testHostNameMismatch(): void
    {
        $this->requireServer(2121);
        $client = new FtpClient(tls: FtpClient::TLS_EXPLICIT, caFile: self::caFile(), peerName: 'ftp.example.com');
        $this->expectExceptionMessage('peerName');
        $client->connect(self::host(), 2121, 10);
    }

    public function testPeerNameOverride(): void
    {
        $this->requireServer(2121);
        $client = new FtpClient(tls: FtpClient::TLS_EXPLICIT, caFile: self::caFile(), peerName: 'localhost');
        $client->connect(self::host(), 2121, 10);
        $client->login(self::USER, self::PASSWORD);
        $client->nlist();
        self::assertSame(226, $client->getLastReply()?->code);
        $client->close();
    }

    public function testDisabledVerification(): void
    {
        $this->requireServer(2121);
        $client = new FtpClient(tls: FtpClient::TLS_EXPLICIT, verifyPeer: false);
        $client->connect(self::host(), 2121, 10);
        $client->login(self::USER, self::PASSWORD);
        $client->nlist();
        self::assertSame(226, $client->getLastReply()?->code);
        $client->close();
    }

    public function testExplicitTlsAgainstPlainOnlyServerFails(): void
    {
        $this->requireServer(2125);
        $client = new FtpClient(tls: FtpClient::TLS_EXPLICIT);
        try {
            $client->connect(self::host(), 2125, 10);
            self::fail('Expected AUTH TLS to be refused');
        } catch (FtpException $e) {
            self::assertStringContainsString('AUTH TLS', $e->getMessage());
        }
    }
}
