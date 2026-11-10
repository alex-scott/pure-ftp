# PureFTP

A small FTP/FTPS client in pure PHP. It uses only core streams (`stream_socket_client`,
`stream_socket_enable_crypto`) and never needs ext-ftp or ext-sockets. FTPS needs ext-openssl.

- PHP 8.2+, `declare(strict_types=1)`, PHPStan at max level
- Plain FTP, explicit FTPS (`AUTH TLS`) and implicit FTPS (port 990)
- TLS certificate verification on by default
- TLS session reuse on data connections, so it works with vsftpd `require_ssl_reuse=YES` and ProFTPD defaults
- Passive mode only: EPSV with fallback to PASV; the PASV address is ignored by default (NAT fix)
- `MLSD` listings, with a fallback to `LIST` parsed for Unix `ls -l`, Windows/IIS DOS and EPLF formats
- Uploads and downloads are streamed, so large files are never loaded into memory
- Control characters (CR, LF, NUL) in arguments are rejected, so nothing can be injected into the control connection
- Errors throw `PureFTP\FtpException` with the server's reply code and text
- Optional PSR-3 logger; passwords are logged as `PASS ****`

## One file

The whole library is a single file, `src/PureFTP.php`. The dist archive contains only that file,
`composer.json` and `LICENSE`.

Updating the package therefore replaces exactly one file. Copying one file, or renaming it into
place, is atomic: a request served during an upgrade sees either the old version or the new one, never
a mix of old and new classes. This matters for applications that update their own libraries while they
are running (for example a self-upgrading web application). It also makes the library easy to bundle
without Composer:

```php
require __DIR__ . '/PureFTP.php'; // also needs the psr/log interfaces
```

With Composer, the file is loaded through a classmap.

## Installation

```bash
composer require alex-scott/pure-ftp
```

## Usage

```php
use PureFTP\FtpClient;
use PureFTP\FtpException;

$ftp = new FtpClient();                       // plain FTP
$ftp->connect('ftp.example.com');             // port 21, 30 s timeout
$ftp->login('user', 'password');

$ftp->mkdirRecursive('backups/2026/11');
$ftp->chdir('backups/2026/11');
$ftp->put('/var/backups/db.sql.gz', 'db.sql.gz');      // local path or stream
$ftp->putString('notes.txt', "hello\n");
$ftp->get('db.sql.gz', '/tmp/db.sql.gz');               // local path or stream
echo $ftp->getString('notes.txt');

foreach ($ftp->list() as $name => $entry) {             // array<string, FtpEntry>
    printf("%s %s %d %s\n", $entry->type, $name, $entry->size, $entry->modified?->format('c'));
}

$ftp->close();
```

### FTPS

```php
$ftp = new FtpClient(tls: FtpClient::TLS_EXPLICIT);   // AUTH TLS, then PBSZ 0 / PROT P
$ftp = new FtpClient(tls: FtpClient::TLS_IMPLICIT);   // TLS from the start, default port 990
```

The certificate is checked against the system CA store and the host name passed to `connect()`.
When the check fails, the exception message names the option that helps:

| Option | Use when |
|---|---|
| `caFile: '/path/ca.pem'` | the server certificate is issued by a private CA |
| `allowSelfSigned: true` | the server uses a self-signed certificate |
| `peerName: 'server.host.com'` | the certificate is issued for another host name (common on shared hosting) |
| `verifyPeer: false` | skip verification entirely (not recommended) |

Data connections resume the control connection's TLS session (the fourth argument of
`stream_socket_enable_crypto()`, which calls OpenSSL's `SSL_copy_session_id`). Servers that require
session reuse therefore work. If a data connection handshake still fails, the exception explains
which server setting to change.

### Other options

```php
new FtpClient(
    usePasvAddress: false, // true: connect to the address from the PASV reply instead of the control host
    useEpsv: true,         // false: always use PASV
    maxReplyLines: 1000,   // protection against a misbehaving server
);
```

### Commands

| Method | FTP command |
|---|---|
| `pwd(): string` | `PWD` |
| `chdir($dir)`, `cdup()`, `isDir($path): bool` | `CWD`, `CDUP` |
| `mkdir($dir)`, `mkdirRecursive($path)`, `rmdir($dir)` | `MKD`, `RMD` |
| `delete($path)`, `rename($from, $to)` | `DELE`, `RNFR`/`RNTO` |
| `chmod($mode, $path): bool` | `SITE CHMOD`; `false` if the server does not support it |
| `size($path): int`, `mdtm($path): DateTimeImmutable` | `SIZE`, `MDTM` (UTC) |
| `put($local, $remote)`, `putString($remote, $contents)` | `STOR` |
| `get($remote, $local)`, `getString($remote): string` | `RETR` |
| `nlist($dir): list<string>` | `NLST` |
| `list($dir): array<string, FtpEntry>` | `MLSD`, or `LIST` when MLSD is not available |
| `raw($command): FtpReply` | anything else, reply returned unchecked |
| `getFeatures()`, `hasFeature($name)` | `FEAT` result |

`FtpEntry` has `name`, `type` (`file`, `dir`, `link`, `unknown`), `size`, `modified`, `mode` (Unix
permission bits), `target` (symlink target), `perm` and `facts` (MLSD) and `raw` (the listing line).
LIST timestamps carry no time zone and are returned as UTC wall-clock values. `.` and `..` are skipped.

### Errors

Every failure throws `FtpException`. `getReplyCode()` and `getReplyText()` return the server reply
when there is one; the message includes it as well.

```php
try {
    $ftp->get('missing.txt', '/tmp/x');
} catch (FtpException $e) {
    echo $e->getReplyCode();   // 550
    echo $e->getMessage();     // RETR failed (550 Failed to open file.)
}
```

After a timeout, a closed connection or a protocol error, the client disconnects; other errors
leave the connection usable.

## Not supported

- Active mode (`PORT`/`EPRT`). It rarely works behind NAT.
- `ACCT`, `REST` (resume) and ASCII transfer mode.

## Development

```bash
composer install
vendor/bin/phpunit --testsuite unit      # no network; uses a scripted fake server
vendor/bin/phpstan analyse
```

Integration tests run against vsftpd, ProFTPD and Pure-FTPd in Docker (plain, explicit and implicit
TLS, a server that requires TLS session reuse, one without MLSD, and one that announces a wrong PASV
address). When ext-ftp is loaded, they also run the same operations through ext-ftp and compare the
results.

```bash
docker compose up -d --build --wait
vendor/bin/phpunit --testsuite integration
```

## License

GNU Lesser General Public License, version 2.1 (LGPL-2.1-only). See [LICENSE](LICENSE).
