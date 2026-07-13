<?php
declare(strict_types=1);

require __DIR__ . '/../src/RocheDB.php';

use RocheDB\RocheDB;
use RocheDB\RocheId;

function assert_true(bool $value, string $message): void
{
    if (!$value) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$db = RocheDB::open(8);
$db->setGalaxyDescription('PHP test galaxy');
$db->setRingDescription('docs/php', 'PHP driver documents');
$db->configureRing('docs/php', 30.0);

$id = $db->putVec('docs/php', 'hello php', [1.0, 0.0]);
assert_true((string) RocheId::parse((string) $id) === (string) $id, 'id parse roundtrip');
assert_true($db->get($id) === 'hello php', 'get roundtrip');

$batch = $db->batchGet([$id]);
assert_true(count($batch) === 1 && $batch[0] === 'hello php', 'batch get');

$jsonId = $db->putJsonVec('docs/php', ['title' => 'PHP context', 'kind' => 'json'], [1.0, 0.0]);
$json = $db->getJson($jsonId);
assert_true(is_array($json) && $json['title'] === 'PHP context', 'json roundtrip');

$encoded = $db->getEncoded($jsonId);
assert_true($encoded !== null && $encoded->codec === 'json', 'encoded json codec');
assert_true(json_decode($encoded->payload, true, flags: JSON_THROW_ON_ERROR)['title'] === 'PHP context', 'encoded json payload');

$selected = $db->queryJson($jsonId, '{ title }');
assert_true(is_array($selected) && $selected['title'] === 'PHP context', 'query json');

$page = $db->readRing('docs/php', [
    'filter' => ['kind' => 'json'],
    'selection' => '{ title }',
    'limit' => 1,
    'rsort' => 'time',
]);
assert_true($page['count'] === 1, 'read ring count');
assert_true($page['items'][0]['codec'] === 'json', 'read ring codec');
assert_true($page['items'][0]['payload']['title'] === 'PHP context', 'read ring selection');

$bifId = $db->putBifVec('artifacts/bif', "\x01\x02\x03\x04", [0.0, 0.0, 1.0]);
$bif = $db->getEncoded($bifId);
assert_true($bif !== null && $bif->codec === 'bif', 'bif codec');
assert_true($bif->payload === "\x01\x02\x03\x04", 'bif payload');
$bifPage = $db->readRing('artifacts/bif', ['limit' => 1]);
assert_true($bifPage['count'] === 1, 'bif read ring count');
assert_true($bifPage['items'][0]['encoding'] === 'base64', 'bif read ring encoding');

$rr = $db->retrieve([1.0, 0.0], 'docs/php', 4);
assert_true(count($rr->hits) >= 1, 'retrieve hit count');
assert_true($rr->stats['scanned'] >= 1, 'retrieve scanned');

$atlas = $db->atlas([1.0, 0.0], 8);
assert_true(str_contains($atlas, 'PHP test galaxy'), 'atlas galaxy description');
assert_true(str_contains($atlas, 'PHP driver documents'), 'atlas ring description');

$node = $db->locate($id);
assert_true($node >= 0, 'locate');
assert_true($db->nextVisit($id, $node) >= 0.0, 'next visit');

$db->close();
echo "PHP driver OK\n";
