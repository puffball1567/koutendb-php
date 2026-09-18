<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use KoutenDB\KoutenDB;
use KoutenDB\KoutenId;

$db = KoutenDB::connectTcp(
    peers: explode(',', getenv('KOUTENDB_ENDPOINTS') ?: '127.0.0.1:17301'),
    options: ['galaxy' => getenv('KOUTENDB_GALAXY') ?: 'publarish'],
);
try {
    $id = $db->putJson('publarish/articles', ['title' => 'Example', 'status' => 'draft']);
    echo 'ID: ', $id, PHP_EOL;
    echo json_encode($db->getJson(KoutenId::parse((string)$id)), JSON_THROW_ON_ERROR), PHP_EOL;
    echo json_encode($db->queryJson($id, '{ title }'), JSON_THROW_ON_ERROR), PHP_EOL;
    echo $db->health(), PHP_EOL;
} finally {
    $db->close();
}
