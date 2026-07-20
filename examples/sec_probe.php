<?php
// Parametrized security probe: build a connection from ORBP_* environment
// variables, attempt a put, print SUCCESS or REJECT:<msg>. Used by the
// cross-driver security matrix harness.
declare(strict_types=1);

require __DIR__ . '/../src/KoutenDB.php';

use KoutenDB\KoutenDB;

function e(string $k): string
{
    $v = getenv($k);
    return $v === false ? '' : $v;
}
function flag(string $k): bool
{
    return getenv($k) === '1';
}

$peers = e('ORBP_PEERS');
$user = e('ORBP_USER');
$pass = e('ORBP_PASS');
$secret = e('ORBP_SECRET');
$ca = e('ORBP_CA');
$sni = e('ORBP_SNI');
$tls = flag('ORBP_TLS') || $ca !== '' || $sni !== '' || flag('ORBP_INSECURE');

try {
    $db = $tls
        ? KoutenDB::connectAuthTls($peers, $user, $pass, '', $secret, '', $ca, $sni, flag('ORBP_INSECURE'))
        : KoutenDB::connectAuth($peers, $user, $pass, '', $secret, '');
    $db->putJson('secure/demo', ['probe' => 1]);
    echo "SUCCESS\n";
} catch (Throwable $ex) {
    echo 'REJECT:' . substr($ex->getMessage(), 0, 50) . "\n";
}
