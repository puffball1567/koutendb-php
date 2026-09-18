<?php
declare(strict_types=1);

require __DIR__ . '/../src/TcpClient.php';

use KoutenDB\KoutenDB;
use KoutenDB\KoutenId;
use KoutenDB\ProtocolException;
use KoutenDB\TcpConnection;

function check(bool $value, string $message): void
{
    if (!$value) { throw new RuntimeException($message); }
}
function rejects(callable $call, string $exception): void
{
    try { $call(); } catch (Throwable $e) {
        check($e instanceof $exception, 'Unexpected exception: ' . $e::class);
        return;
    }
    throw new RuntimeException('Expected rejection');
}

check(!class_exists('FFI'), 'Run this test with FFI absent');
rejects(fn() => KoutenDB::open(), RuntimeException::class);
$old = new KoutenId('42', 1, 7, 1.25);
check((string)$old === '42:1:7:1.25', 'FFI ID compatibility');
$native = KoutenId::parse('18446744073709551615:4294967295:4294967295:1.2345678901234567:60:0.5');
ini_set('precision', '3');
ini_set('serialize_precision', '3');
check(KoutenId::parse((string)$native)->tWrite === $native->tWrite, 'TCP ID precision');
check(KoutenId::parse((string)$native)->parent === '18446744073709551615', 'Unsigned ID preserved');
foreach (['18446744073709551616:1:1:1:60:0', '1:4294967296:1:1:60:0', '1:1:1:NaN:60:0',
          '1:1:1:1:0:0', '1:1:1:1:-1:0', '1:1:1:1:60:INF'] as $id) {
    rejects(fn() => KoutenId::parse($id), ProtocolException::class);
}
check(TcpConnection::length('0', 10) === 0, 'Zero length');
check(TcpConnection::length('10', 10) === 10, 'Inclusive limit');
foreach (['11', '-1', '1e1', '999999999999999999999', '+1', ' 1'] as $n) {
    rejects(fn() => TcpConnection::length($n, 10), ProtocolException::class);
}
foreach ([[], ['unix:/tmp/socket'], ['host:0'], ['host:65536'], ['tcp://host:1'], ["host:1\nAUTH x y"], ['host:1/path']] as $peers) {
    rejects(fn() => KoutenDB::connectTcp($peers), InvalidArgumentException::class);
}
foreach ([['retryReads' => 'false'], ['maxFrameBytes' => 0], ['maxFrameBytes' => 67108865],
          ['maxRedirects' => 33], ['username' => "a\nb"], ['password' => 'secret'],
          ['username' => 'user', 'password' => "secret\n"], ['tlsCaFile' => 'ca.pem'],
          ['unsupported' => true]] as $options) {
    rejects(fn() => KoutenDB::connectTcp(['localhost:1'], options: $options), InvalidArgumentException::class);
}
foreach ([0.0, -1.0, INF, NAN, 3601.0] as $timeout) {
    rejects(fn() => KoutenDB::connectTcp(['localhost:1'], timeout: $timeout), InvalidArgumentException::class);
}
echo "PASS native TCP validation and FFI-free loading\n";
