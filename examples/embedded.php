<?php
declare(strict_types=1);

require __DIR__ . '/../src/RocheDB.php';

use RocheDB\RocheDB;
use RocheDB\RocheId;

$db = RocheDB::open();

try {
    $db->setGalaxyDescription('Example PHP knowledge galaxy');
    $db->setRingDescription('docs/php', 'PHP driver example documents');

    $id = $db->putJson('docs/php', [
        'title' => 'RocheDB PHP driver',
        'kind' => 'example',
    ]);

    $parsed = RocheId::parse((string) $id);
    $doc = $db->getJson($parsed);
    $view = $db->queryJson($id, '{ title }');

    $vecId = $db->putVec('docs/php', 'vector payload', [1.0, 0.0]);
    $result = $db->retrieve([1.0, 0.0], 'docs/php', 4);

    echo json_encode([
        'id' => (string) $id,
        'title' => $doc['title'] ?? null,
        'view' => $view,
        'vectorId' => (string) $vecId,
        'hits' => count($result->hits),
        'scanned' => $result->stats['scanned'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    $db->close();
}
