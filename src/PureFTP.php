<?php

/**
 * PureFTP: FTP/FTPS client built on core PHP streams.
 * https://github.com/alex-scott/pure-ftp
 *
 * The whole library is this one file, so an upgrade replaces a single file and is atomic.
 *
 * Copyright (c) 2026 Alex Scott
 *
 * This library is free software; you can redistribute it and/or modify it under the terms of the
 * GNU Lesser General Public License as published by the Free Software Foundation; version 2.1.
 *
 * This library is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without
 * even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License (LICENSE file) for more details.
 */

declare(strict_types=1);

namespace PureFTP;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * A complete (possibly multi-line) control connection reply.
 */
final readonly class FtpReply
{
    /**
     * @param list<string> $lines raw reply lines without CRLF
     */
    public function __construct(
        public int $code,
        public array $lines,
    ) {
    }

    /**
     * Reply text with the leading status codes removed; lines joined with "\n".
     */
    public function text(): string
    {
        $out = [];
        $last = count($this->lines) - 1;
        foreach ($this->lines as $i => $line) {
            if (($i === 0 || $i === $last) && preg_match('/^\d{3}(?:[ -]|$)/', $line) === 1) {
                $line = substr($line, 4);
            }
            $out[] = $line;
        }

        return implode("\n", $out);
    }

    public function isPreliminary(): bool
    {
        return $this->code >= 100 && $this->code < 200;
    }

    public function isSuccess(): bool
    {
        return $this->code >= 200 && $this->code < 300;
    }

    public function isIntermediate(): bool
    {
        return $this->code >= 300 && $this->code < 400;
    }

    public function isError(): bool
    {
        return $this->code >= 400;
    }

    public function __toString(): string
    {
        return implode("\n", $this->lines);
    }
}

final class FtpException extends RuntimeException
{
    private readonly ?FtpReply $reply;

    public function __construct(string $message, ?FtpReply $reply = null, ?Throwable $previous = null)
    {
        $this->reply = $reply;
        if ($reply !== null) {
            $message .= sprintf(' (%d %s)', $reply->code, str_replace("\n", ' ', $reply->text()));
        }
        parent::__construct($message, $reply->code ?? 0, $previous);
    }

    public function getReply(): ?FtpReply
    {
        return $this->reply;
    }

    public function getReplyCode(): ?int
    {
        return $this->reply?->code;
    }

    public function getReplyText(): ?string
    {
        return $this->reply?->text();
    }
}

final readonly class FtpEntry
{
    public const TYPE_FILE = 'file';
    public const TYPE_DIR = 'dir';
    public const TYPE_LINK = 'link';
    public const TYPE_UNKNOWN = 'unknown';

    /**
     * @param self::TYPE_* $type
     * @param int|null $mode Unix permission bits (e.g. 0644), when the server reports them
     * @param string|null $target symlink target, when known
     * @param string|null $perm MLSD "perm" fact
     * @param array<string, string> $facts MLSD facts with lower-cased names
     * @param string $raw the listing line the entry was parsed from
     */
    public function __construct(
        public string $name,
        public string $type,
        public ?int $size = null,
        public ?DateTimeImmutable $modified = null,
        public ?int $mode = null,
        public ?string $target = null,
        public ?string $perm = null,
        public array $facts = [],
        public string $raw = '',
    ) {
    }

    public function isFile(): bool
    {
        return $this->type === self::TYPE_FILE;
    }

    public function isDir(): bool
    {
        return $this->type === self::TYPE_DIR;
    }

    public function isLink(): bool
    {
        return $this->type === self::TYPE_LINK;
    }
}

/**
 * Parses MLSD output and LIST output in Unix "ls -l", Windows/IIS DOS and EPLF formats.
 *
 * LIST timestamps carry no time zone; they are returned as UTC wall-clock values.
 */
final class FtpListParser
{
    private const UNIX = '/^
        (?<type>[-dlbcpsDn?])
        (?<perms>[-rwxsStTlL]{9})
        [.+@]?\s+
        (?:\d+\s+)?
        (?<owner>\S+)\s+
        (?:(?<group>\S+)\s+)?
        (?<size>\d+)\s+
        (?:
            (?<month>[A-Za-z]{3})\s+(?<day>\d{1,2})\s+(?<timeyear>\d{1,2}:\d{2}|\d{4})
          | (?<isodate>\d{4}-\d{2}-\d{2})\s+(?<isotime>\d{2}:\d{2})
        )
        [ ](?<name>.+)
    $/x';

    private const DOS = '/^
        (?<d1>\d{1,4})[-\/](?<d2>\d{1,2})[-\/](?<d3>\d{1,4})\s+
        (?<hour>\d{1,2}):(?<minute>\d{2})\s*(?<ampm>[AaPp][Mm])?\s+
        (?<size><DIR>|\d+)\s+
        (?<name>.+)
    $/x';

    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    /**
     * @param iterable<string> $lines
     * @return array<string, FtpEntry>
     */
    public static function parseMlsd(iterable $lines): array
    {
        $entries = [];
        foreach ($lines as $line) {
            $entry = self::parseMlsdLine($line);
            if ($entry !== null) {
                $entries[$entry->name] = $entry;
            }
        }

        return $entries;
    }

    public static function parseMlsdLine(string $line): ?FtpEntry
    {
        $line = rtrim($line, "\r\n");
        $pos = strpos($line, ' ');
        if ($pos === false) {
            return null;
        }
        $name = substr($line, $pos + 1);
        if ($name === '' || $name === '.' || $name === '..') {
            return null;
        }

        $facts = [];
        foreach (explode(';', substr($line, 0, $pos)) as $fact) {
            $eq = strpos($fact, '=');
            if ($eq === false || $eq === 0) {
                continue;
            }
            $facts[strtolower(substr($fact, 0, $eq))] = substr($fact, $eq + 1);
        }

        $typeFact = strtolower($facts['type'] ?? '');
        if ($typeFact === 'cdir' || $typeFact === 'pdir') {
            return null;
        }
        $target = null;
        if ($typeFact === 'file') {
            $type = FtpEntry::TYPE_FILE;
        } elseif ($typeFact === 'dir') {
            $type = FtpEntry::TYPE_DIR;
        } elseif ($typeFact === 'os.unix=symlink' || str_starts_with($typeFact, 'os.unix=slink')) {
            $type = FtpEntry::TYPE_LINK;
            $colon = strpos($facts['type'], ':');
            if ($colon !== false && $colon < strlen($facts['type']) - 1) {
                $target = substr($facts['type'], $colon + 1);
            }
        } else {
            $type = FtpEntry::TYPE_UNKNOWN;
        }

        $size = $facts['size'] ?? $facts['sizd'] ?? null;
        $mode = $facts['unix.mode'] ?? null;

        return new FtpEntry(
            name: $name,
            type: $type,
            size: $size !== null && ctype_digit($size) ? (int) $size : null,
            modified: isset($facts['modify']) ? self::parseMlsdTime($facts['modify']) : null,
            mode: $mode !== null && preg_match('/^[0-7]{1,5}$/', $mode) === 1 ? (int) octdec($mode) & 07777 : null,
            target: $target,
            perm: $facts['perm'] ?? null,
            facts: $facts,
            raw: $line,
        );
    }

    /**
     * Parses an RFC 3659 time value (YYYYMMDDHHMMSS[.sss], UTC).
     */
    public static function parseMlsdTime(string $value): ?DateTimeImmutable
    {
        if (preg_match('/^(\d{14})(?:\.(\d{1,6}))?$/', trim($value), $m) !== 1) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat(
            '!YmdHis.u',
            $m[1] . '.' . str_pad($m[2] ?? '', 6, '0'),
            new DateTimeZone('UTC'),
        );

        return $date === false ? null : $date;
    }

    /**
     * @param iterable<string> $lines
     * @param DateTimeImmutable|null $now reference time for listings that omit the year
     * @return array<string, FtpEntry>
     */
    public static function parseList(iterable $lines, ?DateTimeImmutable $now = null): array
    {
        $entries = [];
        foreach ($lines as $line) {
            $entry = self::parseListLine($line, $now);
            if ($entry !== null && $entry->name !== '.' && $entry->name !== '..') {
                $entries[$entry->name] = $entry;
            }
        }

        return $entries;
    }

    public static function parseListLine(string $line, ?DateTimeImmutable $now = null): ?FtpEntry
    {
        $line = rtrim($line, "\r\n");
        if (trim($line) === '' || preg_match('/^total\s+\d+/i', $line) === 1) {
            return null;
        }
        if ($line[0] === '+') {
            return self::parseEplf($line);
        }
        if (preg_match(self::UNIX, $line, $m) === 1) {
            return self::fromUnix($m, $line, $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));
        }
        if (preg_match(self::DOS, $line, $m) === 1) {
            return self::fromDos($m, $line);
        }

        return null;
    }

    /**
     * @param array<int|string, string> $m
     */
    private static function fromUnix(array $m, string $line, DateTimeImmutable $now): FtpEntry
    {
        $name = $m['name'];
        $target = null;
        $type = match ($m['type']) {
            'd' => FtpEntry::TYPE_DIR,
            '-' => FtpEntry::TYPE_FILE,
            'l' => FtpEntry::TYPE_LINK,
            default => FtpEntry::TYPE_UNKNOWN,
        };
        if ($type === FtpEntry::TYPE_LINK) {
            $arrow = strpos($name, ' -> ');
            if ($arrow !== false) {
                $target = substr($name, $arrow + 4);
                $name = substr($name, 0, $arrow);
            }
        }

        $modified = null;
        $utc = new DateTimeZone('UTC');
        if (($m['isodate'] ?? '') !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $m['isodate'] . ' ' . $m['isotime'], $utc);
            $modified = $date === false ? null : $date;
        } elseif (isset(self::MONTHS[strtolower($m['month'])])) {
            $month = self::MONTHS[strtolower($m['month'])];
            $day = (int) $m['day'];
            if (str_contains($m['timeyear'], ':')) {
                [$hour, $minute] = array_map('intval', explode(':', $m['timeyear']));
                $year = (int) $now->format('Y');
                $modified = self::makeDate($year, $month, $day, $hour, $minute);
                // "ls" shows a time instead of a year for dates within the last six months.
                if ($modified !== null && $modified > $now->modify('+1 day')) {
                    $modified = self::makeDate($year - 1, $month, $day, $hour, $minute);
                }
            } else {
                $modified = self::makeDate((int) $m['timeyear'], $month, $day, 0, 0);
            }
        }

        return new FtpEntry(
            name: $name,
            type: $type,
            size: (int) $m['size'],
            modified: $modified,
            mode: self::parseUnixMode($m['perms']),
            target: $target,
            raw: $line,
        );
    }

    /**
     * @param array<int|string, string> $m
     */
    private static function fromDos(array $m, string $line): FtpEntry
    {
        if (strlen($m['d1']) === 4) {
            [$year, $month, $day] = [(int) $m['d1'], (int) $m['d2'], (int) $m['d3']];
        } else {
            [$month, $day, $year] = [(int) $m['d1'], (int) $m['d2'], (int) $m['d3']];
        }
        if ($year < 100) {
            $year += $year < 70 ? 2000 : 1900;
        }
        $hour = (int) $m['hour'];
        $ampm = strtolower($m['ampm'] ?? '');
        if ($ampm === 'pm' && $hour < 12) {
            $hour += 12;
        } elseif ($ampm === 'am' && $hour === 12) {
            $hour = 0;
        }
        $isDir = strtoupper($m['size']) === '<DIR>';

        return new FtpEntry(
            name: $m['name'],
            type: $isDir ? FtpEntry::TYPE_DIR : FtpEntry::TYPE_FILE,
            size: $isDir ? null : (int) $m['size'],
            modified: self::makeDate($year, $month, $day, $hour, (int) $m['minute']),
            raw: $line,
        );
    }

    /**
     * EPLF: "+facts,separated,by,commas,\tname"
     */
    private static function parseEplf(string $line): ?FtpEntry
    {
        $tab = strpos($line, "\t");
        if ($tab === false) {
            return null;
        }
        $name = substr($line, $tab + 1);
        if ($name === '') {
            return null;
        }
        $type = FtpEntry::TYPE_UNKNOWN;
        $size = null;
        $modified = null;
        $mode = null;
        foreach (explode(',', substr($line, 1, $tab - 1)) as $fact) {
            if ($fact === '') {
                continue;
            }
            $value = substr($fact, 1);
            switch ($fact[0]) {
                case '/':
                    $type = FtpEntry::TYPE_DIR;
                    break;
                case 'r':
                    $type = FtpEntry::TYPE_FILE;
                    break;
                case 's':
                    $size = ctype_digit($value) ? (int) $value : null;
                    break;
                case 'm':
                    if (ctype_digit($value)) {
                        $modified = (new DateTimeImmutable('@' . $value))->setTimezone(new DateTimeZone('UTC'));
                    }
                    break;
                case 'u':
                    if (preg_match('/^p([0-7]{1,5})$/', $value, $pm) === 1) {
                        $mode = (int) octdec($pm[1]) & 07777;
                    }
                    break;
            }
        }

        return new FtpEntry(name: $name, type: $type, size: $size, modified: $modified, mode: $mode, raw: $line);
    }

    /**
     * Converts "rwxr-xr-x" style permissions to mode bits, including setuid, setgid and sticky.
     */
    public static function parseUnixMode(string $perms): ?int
    {
        if (strlen($perms) !== 9) {
            return null;
        }
        $mode = 0;
        $special = [04000, 02000, 01000];
        for ($i = 0; $i < 3; $i++) {
            $triad = substr($perms, $i * 3, 3);
            $shift = (2 - $i) * 3;
            if ($triad[0] === 'r') {
                $mode |= 4 << $shift;
            }
            if ($triad[1] === 'w') {
                $mode |= 2 << $shift;
            }
            $x = $triad[2];
            if ($x === 'x' || $x === 's' || $x === 't') {
                $mode |= 1 << $shift;
            }
            if (($i < 2 && ($x === 's' || $x === 'S')) || ($i === 2 && ($x === 't' || $x === 'T'))) {
                $mode |= $special[$i];
            }
        }

        return $mode;
    }

    private static function makeDate(int $year, int $month, int $day, int $hour, int $minute): ?DateTimeImmutable
    {
        if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat(
            '!Y-n-j G:i',
            sprintf('%d-%d-%d %d:%02d', $year, $month, $day, $hour, $minute),
            new DateTimeZone('UTC'),
        );

        return $date === false ? null : $date;
    }
}

/**
 * FTP/FTPS client built on core PHP streams. Passive mode only.
 */
final class FtpClient implements LoggerAwareInterface
{
    public const TLS_NONE = 'none';
    public const TLS_EXPLICIT = 'explicit';
    public const TLS_IMPLICIT = 'implicit';

    public const MAX_LINE_LENGTH = 8192;

    private const CRYPTO_METHOD = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

    private const SESSION_REUSE_HINT = 'If the server requires TLS session reuse on data connections '
        . '(vsftpd require_ssl_reuse=YES, ProFTPD without "TLSOptions NoSessionReuseRequired") and resumption '
        . 'did not work, turn that requirement off on the server.';

    /** @var resource|null */
    private $control = null;
    private string $host = '';
    private string $peerHost = '';
    private float $timeout = 30.0;
    /** @var array<string, string> */
    private array $features = [];
    private ?string $passiveCommand = null;
    private bool $mlsdBroken = false;
    private bool $protectData = false;
    private ?FtpReply $lastReply = null;
    private LoggerInterface $logger;

    /**
     * @param self::TLS_* $tls security mode
     * @param bool $verifyPeer verify the server certificate and host name (FTPS)
     * @param bool $allowSelfSigned accept a self-signed server certificate (FTPS)
     * @param string|null $caFile PEM file with CA certificates to trust instead of the system store (FTPS)
     * @param string|null $peerName host name expected in the certificate; defaults to the connect() host
     * @param bool $usePasvAddress connect to the address from the PASV reply instead of the control host
     * @param bool $useEpsv try EPSV before PASV
     * @param int $maxReplyLines maximum number of lines accepted in one reply
     */
    public function __construct(
        private readonly string $tls = self::TLS_NONE,
        private readonly bool $verifyPeer = true,
        private readonly bool $allowSelfSigned = false,
        private readonly ?string $caFile = null,
        private readonly ?string $peerName = null,
        private readonly bool $usePasvAddress = false,
        private readonly bool $useEpsv = true,
        private readonly int $maxReplyLines = 1000,
    ) {
        if (!in_array($tls, [self::TLS_NONE, self::TLS_EXPLICIT, self::TLS_IMPLICIT], true)) {
            throw new InvalidArgumentException('Unknown TLS mode: ' . $tls);
        }
        if ($maxReplyLines < 2) {
            throw new InvalidArgumentException('maxReplyLines must be at least 2');
        }
        $this->logger = new NullLogger();
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Opens the control connection and reads the greeting. Port defaults to 21, or 990 for implicit TLS.
     */
    public function connect(string $host, ?int $port = null, float $timeout = 30.0): FtpReply
    {
        if ($this->control !== null) {
            throw new FtpException('Already connected');
        }
        self::assertSafe($host, 'Host');
        if ($host === '') {
            throw new InvalidArgumentException('Host must not be empty');
        }
        if ($timeout <= 0) {
            throw new InvalidArgumentException('Timeout must be positive');
        }
        $port ??= $this->tls === self::TLS_IMPLICIT ? 990 : 21;
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Invalid port: ' . $port);
        }
        if ($this->tls !== self::TLS_NONE && !extension_loaded('openssl')) {
            throw new FtpException('FTPS requires the openssl extension');
        }

        $this->host = trim($host, '[]');
        $this->timeout = $timeout;
        $this->features = [];
        $this->passiveCommand = null;
        $this->mlsdBroken = false;
        $this->protectData = false;
        $this->lastReply = null;

        $this->control = $this->openSocket($this->host, $port);
        $this->peerHost = self::peerAddress($this->control) ?? $this->host;

        try {
            if ($this->tls === self::TLS_IMPLICIT) {
                $this->enableControlCrypto();
            }
            $greeting = $this->readReply();
            if ($greeting->code === 120) {
                $greeting = $this->readReply();
            }
            if ($greeting->code !== 220) {
                throw new FtpException('Server refused the connection', $greeting);
            }
            if ($this->tls === self::TLS_EXPLICIT) {
                $reply = $this->send('AUTH TLS');
                if ($reply->code !== 234) {
                    throw new FtpException('Server refused AUTH TLS', $reply);
                }
                $this->enableControlCrypto();
            }
        } catch (FtpException $e) {
            $this->disconnect();
            throw $e;
        }

        return $greeting;
    }

    /**
     * Logs in, then sets up data protection (FTPS), reads FEAT, enables UTF-8 and binary mode.
     */
    public function login(string $user = 'anonymous', string $password = ''): FtpReply
    {
        self::assertSafe($user, 'User name');
        self::assertSafe($password, 'Password');

        $reply = $this->send('USER ' . $user);
        if ($reply->code === 331) {
            $reply = $this->send('PASS ' . $password);
        }
        if ($reply->code === 332) {
            throw new FtpException('Server requires an account (ACCT), which is not supported', $reply);
        }
        if ($reply->code !== 230 && $reply->code !== 202) {
            throw new FtpException('Login failed', $reply);
        }

        if ($this->tls !== self::TLS_NONE) {
            $this->expectSuccess($this->send('PBSZ 0'), 'PBSZ failed');
            $this->expectSuccess($this->send('PROT P'), 'Server refused to protect the data connection (PROT P)');
            $this->protectData = true;
        }

        $this->readFeatures();
        if ($this->hasFeature('UTF8')) {
            $this->send('OPTS UTF8 ON');
        }
        $this->expectSuccess($this->send('TYPE I'), 'Unable to switch to binary mode');

        return $reply;
    }

    /**
     * Sends QUIT and closes the connection. Never throws.
     */
    public function close(): void
    {
        if ($this->control !== null) {
            try {
                $this->send('QUIT');
            } catch (FtpException) {
            }
        }
        $this->disconnect();
    }

    public function isConnected(): bool
    {
        return $this->control !== null;
    }

    public function getLastReply(): ?FtpReply
    {
        return $this->lastReply;
    }

    /**
     * @return array<string, string> FEAT features, upper-cased name => parameters
     */
    public function getFeatures(): array
    {
        return $this->features;
    }

    public function hasFeature(string $name): bool
    {
        return isset($this->features[strtoupper($name)]);
    }

    /**
     * Sends any command and returns the reply without checking it.
     * Do not use it for commands that open a data connection.
     */
    public function raw(string $command): FtpReply
    {
        return $this->send($command);
    }

    public function pwd(): string
    {
        $reply = $this->send('PWD');
        if ($reply->code !== 257 || preg_match('/"((?:[^"]|"")*)"/', $reply->text(), $m) !== 1) {
            throw new FtpException('PWD failed', $reply);
        }

        return str_replace('""', '"', $m[1]);
    }

    public function chdir(string $dir): void
    {
        $this->expectSuccess($this->send('CWD ' . $dir), sprintf('Unable to change directory to "%s"', $dir));
    }

    public function cdup(): void
    {
        $this->expectSuccess($this->send('CDUP'), 'Unable to change to the parent directory');
    }

    public function isDir(string $path): bool
    {
        self::assertSafe($path, 'Path');
        $current = $this->pwd();
        if (!$this->send('CWD ' . $path)->isSuccess()) {
            return false;
        }
        $this->chdir($current);

        return true;
    }

    public function mkdir(string $dir): void
    {
        $this->expectSuccess($this->send('MKD ' . $dir), sprintf('Unable to create directory "%s"', $dir));
    }

    /**
     * Creates the directory and any missing parents. Existing directories are fine.
     */
    public function mkdirRecursive(string $path): void
    {
        self::assertSafe($path, 'Path');
        $current = str_starts_with($path, '/') ? '/' : '';
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            $current .= ($current === '' || $current === '/' ? '' : '/') . $part;
            $reply = $this->send('MKD ' . $current);
            if (!$reply->isSuccess() && !$this->isDir($current)) {
                throw new FtpException(sprintf('Unable to create directory "%s"', $current), $reply);
            }
        }
    }

    public function rmdir(string $dir): void
    {
        $this->expectSuccess($this->send('RMD ' . $dir), sprintf('Unable to remove directory "%s"', $dir));
    }

    public function delete(string $path): void
    {
        $this->expectSuccess($this->send('DELE ' . $path), sprintf('Unable to delete "%s"', $path));
    }

    public function rename(string $from, string $to): void
    {
        self::assertSafe($to, 'Path');
        $reply = $this->send('RNFR ' . $from);
        if ($reply->code !== 350) {
            throw new FtpException(sprintf('Unable to rename "%s"', $from), $reply);
        }
        $this->expectSuccess($this->send('RNTO ' . $to), sprintf('Unable to rename "%s" to "%s"', $from, $to));
    }

    /**
     * SITE CHMOD. Returns false when the server does not implement it; throws on other errors.
     */
    public function chmod(int $mode, string $path): bool
    {
        if ($mode < 0 || $mode > 07777) {
            throw new InvalidArgumentException(sprintf('Invalid mode %o', $mode));
        }
        $reply = $this->send(sprintf('SITE CHMOD %03o %s', $mode, $path));
        if ($reply->isSuccess()) {
            return true;
        }
        if (in_array($reply->code, [500, 501, 502, 504], true)) {
            return false;
        }
        throw new FtpException(sprintf('Unable to change mode of "%s"', $path), $reply);
    }

    public function size(string $path): int
    {
        $reply = $this->send('SIZE ' . $path);
        if ($reply->code !== 213 || preg_match('/^\s*(\d+)/', $reply->text(), $m) !== 1) {
            throw new FtpException(sprintf('Unable to get size of "%s"', $path), $reply);
        }

        return (int) $m[1];
    }

    /**
     * Modification time (MDTM), in UTC.
     */
    public function mdtm(string $path): DateTimeImmutable
    {
        $reply = $this->send('MDTM ' . $path);
        $time = null;
        if ($reply->code === 213 && preg_match('/^\s*(\d{14}(?:\.\d+)?)/', $reply->text(), $m) === 1) {
            $time = FtpListParser::parseMlsdTime($m[1]);
        }
        if ($time === null) {
            throw new FtpException(sprintf('Unable to get modification time of "%s"', $path), $reply);
        }

        return $time;
    }

    /**
     * Uploads a local file path or a readable stream.
     *
     * @param string|resource $local
     */
    public function put($local, string $remote): void
    {
        if (is_string($local)) {
            [$stream, $warning] = self::guard(static fn () => fopen($local, 'rb'));
            if ($stream === false) {
                throw new FtpException(sprintf('Unable to open "%s" for reading: %s', $local, $warning));
            }
            try {
                $this->upload($stream, $remote);
            } finally {
                fclose($stream);
            }

            return;
        }
        self::assertStream($local);
        $this->upload($local, $remote);
    }

    public function putString(string $remote, string $contents): void
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new FtpException('Unable to open a temporary stream');
        }
        try {
            fwrite($stream, $contents);
            rewind($stream);
            $this->upload($stream, $remote);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Downloads to a local file path (removed again on failure) or a writable stream.
     *
     * @param string|resource $local
     */
    public function get(string $remote, $local): void
    {
        if (is_string($local)) {
            [$stream, $warning] = self::guard(static fn () => fopen($local, 'wb'));
            if ($stream === false) {
                throw new FtpException(sprintf('Unable to open "%s" for writing: %s', $local, $warning));
            }
            try {
                $this->download($remote, $stream);
            } catch (FtpException $e) {
                fclose($stream);
                self::guard(static fn () => unlink($local));
                throw $e;
            }
            fclose($stream);

            return;
        }
        self::assertStream($local);
        $this->download($remote, $local);
    }

    public function getString(string $remote): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new FtpException('Unable to open a temporary stream');
        }
        try {
            $this->download($remote, $stream);
            rewind($stream);

            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Names in the directory (NLST), as returned by the server.
     *
     * @return list<string>
     */
    public function nlist(string $dir = ''): array
    {
        $names = [];
        foreach ($this->readListing($dir === '' ? 'NLST' : 'NLST ' . $dir) as $line) {
            if ($line !== '' && $line !== '.' && $line !== '..') {
                $names[] = $line;
            }
        }

        return $names;
    }

    /**
     * Directory contents keyed by name, using MLSD when available and LIST otherwise. Skips "." and "..".
     *
     * @return array<string, FtpEntry>
     */
    public function list(string $dir = ''): array
    {
        $suffix = $dir === '' ? '' : ' ' . $dir;
        if ($this->hasFeature('MLST') && !$this->mlsdBroken) {
            try {
                return FtpListParser::parseMlsd($this->readListing('MLSD' . $suffix));
            } catch (FtpException $e) {
                if (!in_array($e->getReplyCode(), [500, 502], true)) {
                    throw $e;
                }
                $this->mlsdBroken = true;
            }
        }

        return FtpListParser::parseList($this->readListing('LIST' . $suffix));
    }

    /**
     * @return list<string>
     */
    private function readListing(string $command): array
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new FtpException('Unable to open a temporary stream');
        }
        try {
            $this->transfer($command, function ($data) use ($stream): void {
                $this->copy($data, $stream, $data);
            });
            rewind($stream);
            $lines = [];
            while (($line = fgets($stream)) !== false) {
                $lines[] = rtrim($line, "\r\n");
            }

            return $lines;
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param resource $source
     */
    private function upload($source, string $remote): void
    {
        $this->transfer('STOR ' . $remote, function ($data) use ($source): void {
            $this->copy($source, $data, $data);
        });
    }

    /**
     * @param resource $target
     */
    private function download(string $remote, $target): void
    {
        $this->transfer('RETR ' . $remote, function ($data) use ($target): void {
            $this->copy($data, $target, $data);
        });
    }

    /**
     * @param resource $from
     * @param resource $to
     * @param resource $socket the data socket, checked for timeouts
     */
    private function copy($from, $to, $socket): void
    {
        [$copied, $warning] = self::guard(static fn () => stream_copy_to_stream($from, $to));
        $meta = stream_get_meta_data($socket);
        if ($meta['timed_out']) {
            throw new FtpException(sprintf('Data connection timed out after %s seconds', $this->timeout));
        }
        // Servers that close TLS without close_notify make reads warn; the final reply decides then.
        if ($copied === false || ($warning !== '' && $to === $socket)) {
            throw new FtpException('Data transfer failed' . ($warning !== '' ? ': ' . $warning : ''));
        }
        if ($warning !== '') {
            $this->logger->notice('Data connection: ' . $warning);
        }
    }

    /**
     * Opens a passive data connection, sends the command, runs the transfer and checks the final reply.
     *
     * @param callable(resource): void $transfer
     */
    private function transfer(string $command, callable $transfer): FtpReply
    {
        self::assertSafe($command, 'Command');
        $verb = strtok($command, ' ');
        $data = $this->openDataConnection();
        $started = false;
        try {
            $reply = $this->send($command);
            if ($reply->code !== 125 && $reply->code !== 150) {
                throw new FtpException(sprintf('%s failed', $verb), $reply);
            }
            $started = true;
            if ($this->protectData) {
                $this->enableDataCrypto($data);
            }
            $this->applyTimeout($data);
            $transfer($data);
            $this->shutdownData($data);
        } catch (FtpException $e) {
            self::closeStream($data);
            if ($started && $this->control !== null) {
                $final = $this->readReplyIfReady(5.0);
                if ($final !== null && $e->getReply() === null) {
                    throw new FtpException($e->getMessage(), $final, $e);
                }
            }
            throw $e;
        }
        self::closeStream($data);

        $final = $this->readReply();
        if ($final->code !== 226 && $final->code !== 250) {
            $message = sprintf('%s failed', $verb);
            if ($this->protectData && ($final->code === 425 || $final->code === 522)) {
                $message .= '. ' . self::SESSION_REUSE_HINT;
            }
            throw new FtpException($message, $final);
        }

        return $final;
    }

    /**
     * Closes our side gracefully and waits for the server to close. Closing a socket that still has unread
     * data (such as TLS 1.3 session tickets) sends a reset, which can make the server lose the end of an upload.
     *
     * @param resource $data
     */
    private function shutdownData($data): void
    {
        if ($this->protectData) {
            self::guard(static fn () => stream_socket_enable_crypto($data, false));
        }
        self::guard(static fn () => stream_socket_shutdown($data, STREAM_SHUT_WR));
        while (!feof($data)) {
            [$chunk] = self::guard(static fn () => fread($data, 65536));
            if (!is_string($chunk) || ($chunk === '' && stream_get_meta_data($data)['timed_out'])) {
                break;
            }
        }
    }

    /**
     * @return resource
     */
    private function openDataConnection()
    {
        if ($this->useEpsv && $this->passiveCommand !== 'PASV') {
            $reply = $this->send('EPSV');
            if ($reply->code === 229) {
                if (preg_match('/\(([!-~])\1\1(\d{1,5})\1\)/', $reply->text(), $m) !== 1) {
                    throw new FtpException('Malformed EPSV reply', $reply);
                }
                $port = self::validPort((int) $m[2], $reply);
                $this->passiveCommand = 'EPSV';

                return $this->openSocket($this->peerHost, $port);
            }
            if ($this->passiveCommand === 'EPSV' || $reply->code < 500) {
                throw new FtpException('EPSV failed', $reply);
            }
        }

        $reply = $this->send('PASV');
        if ($reply->code !== 227) {
            throw new FtpException('Server refused passive mode', $reply);
        }
        $pattern = '/(\d{1,3}),\s*(\d{1,3}),\s*(\d{1,3}),\s*(\d{1,3}),\s*(\d{1,3}),\s*(\d{1,3})/';
        if (preg_match($pattern, $reply->text(), $m) !== 1) {
            throw new FtpException('Malformed PASV reply', $reply);
        }
        $octets = array_map('intval', array_slice($m, 1));
        foreach ($octets as $octet) {
            if ($octet > 255) {
                throw new FtpException('Malformed PASV reply', $reply);
            }
        }
        $port = self::validPort($octets[4] * 256 + $octets[5], $reply);
        $this->passiveCommand = 'PASV';
        $host = $this->usePasvAddress ? implode('.', array_slice($octets, 0, 4)) : $this->peerHost;

        return $this->openSocket($host, $port);
    }

    private static function validPort(int $port, FtpReply $reply): int
    {
        if ($port < 1 || $port > 65535) {
            throw new FtpException('Server returned an invalid data port ' . $port, $reply);
        }

        return $port;
    }

    /**
     * @return resource
     */
    private function openSocket(string $host, int $port)
    {
        $context = stream_context_create(['ssl' => $this->sslOptions()]);
        $address = sprintf('tcp://%s:%d', str_contains($host, ':') ? '[' . $host . ']' : $host, $port);
        $errno = 0;
        $errstr = '';
        [$stream, $warning] = self::guard(function () use ($address, $context, &$errno, &$errstr) {
            return stream_socket_client($address, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        });
        if ($stream === false) {
            $reason = $errstr !== '' ? $errstr : $warning;
            throw new FtpException(sprintf('Unable to connect to %s:%d: %s', $host, $port, $reason));
        }
        $this->applyTimeout($stream);

        return $stream;
    }

    /**
     * @return array<string, bool|string>
     */
    private function sslOptions(): array
    {
        $options = [
            'verify_peer' => $this->verifyPeer,
            'verify_peer_name' => $this->verifyPeer,
            'allow_self_signed' => $this->allowSelfSigned,
            'peer_name' => $this->peerName ?? $this->host,
            'SNI_enabled' => true,
            'disable_compression' => true,
        ];
        if ($this->caFile !== null) {
            $options['cafile'] = $this->caFile;
        }

        return $options;
    }

    private function enableControlCrypto(): void
    {
        $control = $this->requireControl();
        [$result, $warning] = self::guard(static fn () => stream_socket_enable_crypto($control, true, self::CRYPTO_METHOD));
        if ($result !== true) {
            throw new FtpException(self::tlsFailureMessage('TLS handshake failed', $warning));
        }
    }

    /**
     * @param resource $data
     */
    private function enableDataCrypto($data): void
    {
        $control = $this->requireControl();
        // Passing the control stream resumes its TLS session (SSL_copy_session_id), which servers may require.
        [$result, $warning] = self::guard(
            static fn () => stream_socket_enable_crypto($data, true, self::CRYPTO_METHOD, $control),
        );
        if ($result !== true) {
            throw new FtpException(
                self::tlsFailureMessage('TLS handshake on the data connection failed', $warning)
                    . '. ' . self::SESSION_REUSE_HINT,
            );
        }
    }

    private static function tlsFailureMessage(string $prefix, string $warning): string
    {
        $message = $prefix . ($warning !== '' ? ': ' . $warning : '');
        if (str_contains($warning, 'certificate verify failed')) {
            $message .= '. Certificate verification failed: set caFile to trust a private CA, '
                . 'or allowSelfSigned for a self-signed certificate';
        } elseif (str_contains($warning, 'did not match expected')) {
            $message .= '. The certificate is issued for another host name: set peerName to that name';
        }

        return $message;
    }

    private function readFeatures(): void
    {
        $this->features = [];
        $reply = $this->send('FEAT');
        if ($reply->code !== 211) {
            return;
        }
        foreach (array_slice($reply->lines, 1, -1) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode(' ', $line, 2);
            $this->features[strtoupper($parts[0])] = trim($parts[1] ?? '');
        }
    }

    private function send(string $command): FtpReply
    {
        self::assertSafe($command, 'Command');
        $control = $this->requireControl();
        $this->logger->debug('> ' . (preg_match('/^(PASS|ACCT)\s/i', $command) === 1 ? substr($command, 0, 4) . ' ****' : $command));

        $buffer = $command . "\r\n";
        $this->applyTimeout($control);
        while ($buffer !== '') {
            [$written, $warning] = self::guard(static fn () => fwrite($control, $buffer));
            if ($written === false || $written === 0) {
                $this->disconnect();
                throw new FtpException('Unable to send command' . ($warning !== '' ? ': ' . $warning : ''));
            }
            $buffer = (string) substr($buffer, $written);
        }

        return $this->readReply();
    }

    private function readReply(): FtpReply
    {
        $first = $this->readLine();
        if (preg_match('/^(\d{3})([ -]|$)/', $first, $m) !== 1) {
            $this->disconnect();
            throw new FtpException('Malformed reply from server: ' . substr($first, 0, 200));
        }
        $lines = [$first];
        if ($m[2] === '-') {
            $end = $m[1] . ' ';
            do {
                if (count($lines) >= $this->maxReplyLines) {
                    $this->disconnect();
                    throw new FtpException(sprintf('Server reply has more than %d lines', $this->maxReplyLines));
                }
                $line = $this->readLine();
                $lines[] = $line;
            } while (!str_starts_with($line, $end) && $line !== $m[1]);
        }

        $reply = new FtpReply((int) $m[1], $lines);
        $this->lastReply = $reply;
        $this->logger->debug('< ' . implode("\n< ", $lines));

        return $reply;
    }

    private function readReplyIfReady(float $wait): ?FtpReply
    {
        $control = $this->control;
        if ($control === null) {
            return null;
        }
        $read = [$control];
        $write = $except = null;
        $seconds = (int) $wait;
        [$ready] = self::guard(
            static fn () => stream_select($read, $write, $except, $seconds, (int) (($wait - $seconds) * 1e6)),
        );
        if ($ready !== 1) {
            return null;
        }
        try {
            return $this->readReply();
        } catch (FtpException) {
            return null;
        }
    }

    private function readLine(): string
    {
        $control = $this->requireControl();
        $this->applyTimeout($control);
        [$line] = self::guard(static fn () => fgets($control, self::MAX_LINE_LENGTH + 3));
        $complete = is_string($line) && str_ends_with($line, "\n");
        if (!$complete) {
            if (is_string($line) && strlen($line) >= self::MAX_LINE_LENGTH + 2) {
                $this->disconnect();
                throw new FtpException(sprintf('Server reply line is longer than %d bytes', self::MAX_LINE_LENGTH));
            }
            $timedOut = stream_get_meta_data($control)['timed_out'];
            $this->disconnect();
            throw new FtpException($timedOut
                ? sprintf('Timed out after %s seconds waiting for a server reply', $this->timeout)
                : 'Connection closed by the server');
        }

        return rtrim($line, "\r\n");
    }

    /**
     * @return resource
     */
    private function requireControl()
    {
        if ($this->control === null) {
            throw new FtpException('Not connected');
        }

        return $this->control;
    }

    private function expectSuccess(FtpReply $reply, string $message): FtpReply
    {
        if (!$reply->isSuccess()) {
            throw new FtpException($message, $reply);
        }

        return $reply;
    }

    /**
     * @param resource $stream
     */
    private function applyTimeout($stream): void
    {
        $seconds = (int) $this->timeout;
        stream_set_timeout($stream, $seconds, (int) (($this->timeout - $seconds) * 1e6));
    }

    private function disconnect(): void
    {
        if ($this->control !== null) {
            self::closeStream($this->control);
            $this->control = null;
        }
        $this->protectData = false;
    }

    /**
     * @param resource $stream
     */
    private static function closeStream($stream): void
    {
        self::guard(static fn () => is_resource($stream) && fclose($stream));
    }

    /**
     * @param resource $control
     */
    private static function peerAddress($control): ?string
    {
        $name = stream_socket_get_name($control, true);
        if ($name === false || $name === '') {
            return null;
        }
        if (preg_match('/^\[(.+)\]:\d+$/', $name, $m) === 1) {
            return $m[1];
        }
        $colon = strrpos($name, ':');

        return $colon === false ? $name : substr($name, 0, $colon);
    }

    private static function assertSafe(string $value, string $what): void
    {
        if (strpbrk($value, "\r\n\0") !== false) {
            throw new FtpException($what . ' must not contain CR, LF or NUL characters');
        }
    }

    /**
     * @param mixed $stream
     */
    private static function assertStream($stream): void
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException('Expected a file path or an open stream');
        }
    }

    /**
     * Runs $fn with PHP warnings captured instead of emitted.
     *
     * @template T
     * @param callable(): T $fn
     * @return array{T, string}
     */
    private static function guard(callable $fn): array
    {
        $messages = [];
        set_error_handler(static function (int $errno, string $message) use (&$messages): bool {
            $messages[] = preg_replace('/^[\w:]+\(\): /', '', $message) ?? $message;

            return true;
        });
        try {
            $result = $fn();
        } finally {
            restore_error_handler();
        }

        return [$result, implode('; ', $messages)];
    }
}
