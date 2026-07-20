<?php
// Connect to a TLS-enabled koutend over the cluster wire protocol.
//
// Requires an KoutenDB core built with -d:ssl. Run against a TLS listener,
// e.g. the one from the core's scripts/cluster_tls_smoke.sh:
//
//   KOUTEN_PEERS=127.0.0.1:17651 KOUTEN_TLS_CA=/path/server.crt \
//   php -d ffi.enable=1 examples/cluster_tls.php
declare(strict_types=1);

require __DIR__ . '/../src/KoutenDB.php';

use KoutenDB\KoutenDB;

$peers = getenv('KOUTEN_PEERS') ?: '127.0.0.1:17651';
$ca = getenv('KOUTEN_TLS_CA') ?: '';
$serverName = getenv('KOUTEN_TLS_SERVER_NAME') ?: '';

// A CA file keeps certificate verification on, which is the right way to reach
// a server with a private CA or self-signed certificate. Without it, use
// connectAuthTlsInsecure() for local smoke tests only.
$db = $ca !== ''
    ? KoutenDB::connectAuthTls($peers, 'alice', 'secret', '', 'shared-secret', '', $ca, $serverName)
    : KoutenDB::connectAuthTlsInsecure($peers, 'alice', 'secret', '', 'shared-secret');

try {
    $id = $db->putJson('secure/demo', ['title' => 'tls smoke', 'ok' => true]);
    $page = $db->readRing('secure/demo', [
        'filter' => ['id' => (string) $id],
        'selection' => '{ title ok }',
    ]);
    echo json_encode([
        'id' => (string) $id,
        'count' => $page['count'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    $db->close();
}
