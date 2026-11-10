<?php

declare(strict_types=1);

namespace PureFTP\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PureFTP\FtpEntry;
use PureFTP\FtpListParser;

final class FtpListParserTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function fixture(string $name): array
    {
        $lines = file(dirname(__DIR__) . '/fixtures/list/' . $name, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);

        return $lines;
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-20 12:00:00', new DateTimeZone('UTC'));
    }

    private static function date(?DateTimeImmutable $date): ?string
    {
        return $date?->format('Y-m-d H:i:s');
    }

    /**
     * Unix listings captured from the docker test servers (see tests/docker).
     *
     * @return iterable<string, array{string}>
     */
    public static function realUnixListings(): iterable
    {
        yield 'vsftpd' => ['vsftpd.txt'];
        yield 'proftpd' => ['proftpd.txt'];
        yield 'pure-ftpd' => ['pure-ftpd.txt'];
    }

    #[DataProvider('realUnixListings')]
    public function testRealUnixListings(string $fixture): void
    {
        $entries = FtpListParser::parseList(self::fixture($fixture), self::now());

        self::assertSame(
            ['big.bin', 'link', 'name with spaces.txt', 'plain.txt', 'setuid.sh', 'sticky', 'sub dir', 'юникод файл.txt'],
            array_keys($entries),
        );
        self::assertSame(5368709120, $entries['big.bin']->size);
        self::assertSame('2026-11-10 13:55:00', self::date($entries['big.bin']->modified));

        self::assertTrue($entries['link']->isLink());
        self::assertSame('plain.txt', $entries['link']->target);

        self::assertTrue($entries['plain.txt']->isFile());
        self::assertSame(12, $entries['plain.txt']->size);
        self::assertSame(0644, $entries['plain.txt']->mode);
        self::assertSame('2019-03-04 00:00:00', self::date($entries['plain.txt']->modified));

        self::assertSame(0755, $entries['setuid.sh']->mode);
        self::assertTrue($entries['sticky']->isDir());
        self::assertSame(01777, $entries['sticky']->mode);
        self::assertTrue($entries['sub dir']->isDir());
        self::assertTrue($entries['юникод файл.txt']->isFile());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function realMlsdListings(): iterable
    {
        yield 'proftpd' => ['mlsd-proftpd.txt'];
        yield 'pure-ftpd' => ['mlsd-pure-ftpd.txt'];
    }

    #[DataProvider('realMlsdListings')]
    public function testRealMlsdListings(string $fixture): void
    {
        $entries = FtpListParser::parseMlsd(self::fixture($fixture));
        $names = array_keys($entries);
        sort($names);

        self::assertSame(
            ['big.bin', 'link', 'name with spaces.txt', 'plain.txt', 'setuid.sh', 'sticky', 'sub dir', 'юникод файл.txt'],
            $names,
        );
        self::assertSame(5368709120, $entries['big.bin']->size);
        self::assertTrue($entries['link']->isLink());
        self::assertNull($entries['link']->target);
        self::assertSame(12, $entries['plain.txt']->size);
        self::assertSame(0644, $entries['plain.txt']->mode);
        self::assertSame('2019-03-04 05:06:07', self::date($entries['plain.txt']->modified));
        self::assertSame('UTC', $entries['plain.txt']->modified?->getTimezone()->getName());
        self::assertSame(0755, $entries['setuid.sh']->mode);
        self::assertTrue($entries['sticky']->isDir());
        self::assertSame(01777, $entries['sticky']->mode);
        self::assertTrue($entries['sub dir']->isDir());
        self::assertArrayHasKey('unique', $entries['sub dir']->facts);
    }

    public function testMlsdFactsInAnyOrderAndCase(): void
    {
        $entries = FtpListParser::parseMlsd(self::fixture('mlsd-mixed.txt'));

        self::assertSame(
            ['welcome.msg', 'incoming', 'hosts', 'semi;colon name.txt', ' leading space', 'junction'],
            array_keys($entries),
        );
        self::assertSame(1830, $entries['welcome.msg']->size);
        self::assertSame('1999-09-29 00:33:55', self::date($entries['welcome.msg']->modified));
        self::assertTrue($entries['incoming']->isDir());
        self::assertSame('el', $entries['incoming']->perm);
        self::assertSame('2024-01-01 12:00:00.123', $entries['incoming']->modified?->format('Y-m-d H:i:s.v'));
        self::assertTrue($entries['hosts']->isLink());
        self::assertSame('/etc/hosts', $entries['hosts']->target);
        self::assertSame(0644, $entries['semi;colon name.txt']->mode);
        self::assertSame(0, $entries['semi;colon name.txt']->size);
        self::assertSame(FtpEntry::TYPE_UNKNOWN, $entries['junction']->type);
    }

    public function testFileZillaServer(): void
    {
        $entries = FtpListParser::parseList(self::fixture('filezilla.txt'), self::now());

        self::assertSame(['Backups 2023', 'report.pdf', 'disk image.iso', 'Ünïcødé ✓.txt'], array_keys($entries));
        self::assertTrue($entries['Backups 2023']->isDir());
        self::assertSame('2026-02-28 09:15:00', self::date($entries['Backups 2023']->modified));
        self::assertSame('2022-12-31 00:00:00', self::date($entries['report.pdf']->modified));
        self::assertSame(7516192768, $entries['disk image.iso']->size);
        self::assertSame(0444, $entries['Ünïcødé ✓.txt']->mode);
    }

    public function testTimeWithoutYearInTheFutureMeansLastYear(): void
    {
        $now = new DateTimeImmutable('2026-01-10 00:00:00', new DateTimeZone('UTC'));
        $entry = FtpListParser::parseListLine('-rw-r--r-- 1 u g 1 Dec 24 18:00 x', $now);
        self::assertSame('2025-12-24 18:00:00', self::date($entry?->modified));
    }

    public function testIisDosFormat(): void
    {
        $entries = FtpListParser::parseList(self::fixture('iis-dos.txt'));

        self::assertSame(['aspnet_client', 'index.html', 'old backup.zip', 'My Documents'], array_keys($entries));
        self::assertTrue($entries['aspnet_client']->isDir());
        self::assertNull($entries['aspnet_client']->size);
        self::assertSame('2024-01-15 10:20:00', self::date($entries['aspnet_client']->modified));
        self::assertSame(1234, $entries['index.html']->size);
        self::assertSame('2024-02-29 12:05:00', self::date($entries['index.html']->modified));
        self::assertSame(4294967296, $entries['old backup.zip']->size);
        self::assertSame('1999-12-31 23:59:00', self::date($entries['old backup.zip']->modified));
        self::assertSame('2023-07-04 00:00:00', self::date($entries['My Documents']->modified));
    }

    public function testIisIsoDates(): void
    {
        $entries = FtpListParser::parseList(self::fixture('iis-dos-iso.txt'));

        self::assertTrue($entries['wwwroot']->isDir());
        self::assertSame(987654, $entries['log 2024.txt']->size);
        self::assertSame('2024-03-01 23:45:00', self::date($entries['log 2024.txt']->modified));
    }

    public function testSynology(): void
    {
        $entries = FtpListParser::parseList(self::fixture('synology.txt'), self::now());

        self::assertSame(['photo', 'backup.hbk', '@eaDir', 'read me.txt'], array_keys($entries));
        self::assertSame(0777, $entries['photo']->mode);
        self::assertTrue($entries['@eaDir']->isDir());
        self::assertSame(2147483648, $entries['backup.hbk']->size);
        self::assertSame(42, $entries['read me.txt']->size);
    }

    public function testCpanel(): void
    {
        $entries = FtpListParser::parseList(self::fixture('cpanel.txt'), self::now());

        self::assertSame(
            ['.htaccess', 'public_html', 'www', 'special', 'file -> not a link.txt'],
            array_keys($entries),
        );
        self::assertSame('public_html', $entries['www']->target);
        self::assertSame(07755, $entries['special']->mode);
        self::assertSame(0750, $entries['public_html']->mode);
        self::assertTrue($entries['file -> not a link.txt']->isFile());
    }

    public function testEplf(): void
    {
        $entries = FtpListParser::parseList(self::fixture('eplf.txt'));

        self::assertSame(['2.4', 'RFC959.txt', 'empty file'], array_keys($entries));
        self::assertTrue($entries['2.4']->isDir());
        self::assertSame('1996-02-13 23:58:22', self::date($entries['2.4']->modified));
        self::assertSame(10376, $entries['RFC959.txt']->size);
        self::assertSame(0644, $entries['RFC959.txt']->mode);
        self::assertSame(0, $entries['empty file']->size);
    }

    public function testUnparseableLinesAreSkipped(): void
    {
        self::assertSame([], FtpListParser::parseList(['', 'total 0', 'garbage line', '-rw-r--r-- broken']));
    }

    public function testUnixModeParsing(): void
    {
        self::assertSame(04755, FtpListParser::parseUnixMode('rwsr-xr-x'));
        self::assertSame(02644, FtpListParser::parseUnixMode('rw-r-Sr--'));
        self::assertSame(01777, FtpListParser::parseUnixMode('rwxrwxrwt'));
        self::assertSame(01776, FtpListParser::parseUnixMode('rwxrwxrwT'));
        self::assertNull(FtpListParser::parseUnixMode('rwx'));
    }
}
