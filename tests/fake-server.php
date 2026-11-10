<?php

/**
 * Scripted FTP server for unit tests: replays a JSON scenario over real sockets.
 *
 * Usage: php tests/fake-server.php scenario.json
 * Prints the control port on stdout, writes mismatches to stderr and exits 1 when any occurred.
 *
 * Steps (one key each):
 *   {"send": "220 hi"}          reply line, CRLF appended; {p1} {p2} {port} expand to the passive port
 *   {"raw": "bytes"}            raw bytes, nothing appended
 *   {"expect": "USER bob"}      next command must equal this ("/regex/" also accepted)
 *   {"passive": true}           open a data listener
 *   {"accept": true}            accept the data connection
 *   {"dataSend": "text"}        write to the data connection
 *   {"dataSendB64": "..."}      same, base64-encoded (for binary payloads)
 *   {"dataExpect": "text"}      read the data connection to EOF and compare
 *   {"dataExpectSha1": "hex"}   same, comparing the SHA-1
 *   {"dataClose": true}         close the data connection
 *   {"sleep": 1.5}              pause
 *   {"close": true}             close the control connection
 */

declare(strict_types=1);

const IO_TIMEOUT = 5;

$scenarioFile = $argv[1] ?? '';
$steps = json_decode((string) file_get_contents($scenarioFile), true);
if (!is_array($steps)) {
    fwrite(STDERR, "invalid scenario\n");
    exit(2);
}

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "listen failed: $errstr\n");
    exit(2);
}
$name = (string) stream_socket_get_name($server, false);
fwrite(STDOUT, substr($name, strrpos($name, ':') + 1) . "\n");
fflush(STDOUT);

$errors = [];
$client = stream_socket_accept($server, IO_TIMEOUT);
if ($client === false) {
    fwrite(STDERR, "client did not connect\n");
    exit(1);
}
stream_set_timeout($client, IO_TIMEOUT);

$dataServer = null;
$data = null;
$dataPort = 0;

$expand = static function (string $s) use (&$dataPort): string {
    return strtr($s, [
        '{port}' => (string) $dataPort,
        '{p1}' => (string) intdiv($dataPort, 256),
        '{p2}' => (string) ($dataPort % 256),
    ]);
};

$readAll = static function ($stream): string {
    $buffer = '';
    while (!feof($stream)) {
        $chunk = fread($stream, 65536);
        if ($chunk === false) {
            break;
        }
        if ($chunk === '' && stream_get_meta_data($stream)['timed_out']) {
            break;
        }
        $buffer .= $chunk;
    }

    return $buffer;
};

foreach ($steps as $i => $step) {
    $op = (string) array_key_first($step);
    $arg = $step[$op];
    $where = sprintf('step %d (%s)', $i, $op);
    switch ($op) {
        case 'send':
            fwrite($client, $expand((string) $arg) . "\r\n");
            break;
        case 'raw':
            fwrite($client, $expand((string) $arg));
            break;
        case 'expect':
            $line = fgets($client);
            if ($line === false) {
                $errors[] = "$where: expected \"$arg\", got EOF/timeout";
                break 2;
            }
            $line = rtrim($line, "\r\n");
            $ok = str_starts_with((string) $arg, '/') ? preg_match((string) $arg, $line) === 1 : $line === $arg;
            if (!$ok) {
                $errors[] = "$where: expected \"$arg\", got \"$line\"";
                fwrite($client, "599 unexpected command\r\n");
                break 2;
            }
            break;
        case 'passive':
            $dataServer = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            if ($dataServer === false) {
                $errors[] = "$where: $errstr";
                break 2;
            }
            $n = (string) stream_socket_get_name($dataServer, false);
            $dataPort = (int) substr($n, strrpos($n, ':') + 1);
            break;
        case 'accept':
            $data = $dataServer === null ? false : stream_socket_accept($dataServer, IO_TIMEOUT);
            if ($data === false) {
                $errors[] = "$where: no data connection";
                break 2;
            }
            stream_set_timeout($data, IO_TIMEOUT);
            break;
        case 'dataSend':
            fwrite($data, (string) $arg);
            break;
        case 'dataSendB64':
            fwrite($data, (string) base64_decode((string) $arg, true));
            break;
        case 'dataExpect':
        case 'dataExpectSha1':
            $received = $readAll($data);
            $actual = $op === 'dataExpect' ? $received : sha1($received);
            if ($actual !== $arg) {
                $errors[] = sprintf('%s: data mismatch, got %d bytes', $where, strlen($received));
            }
            break;
        case 'dataClose':
            fclose($data);
            $data = null;
            break;
        case 'sleep':
            usleep((int) ((float) $arg * 1e6));
            break;
        case 'close':
            fclose($client);
            $client = null;
            break 2;
        default:
            $errors[] = "$where: unknown step";
            break 2;
    }
}

if ($client !== null) {
    $rest = trim($readAll($client));
    if ($rest !== '' && $errors === []) {
        $errors[] = 'unexpected commands after the scenario: ' . str_replace("\r\n", ' | ', $rest);
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}
exit(0);
