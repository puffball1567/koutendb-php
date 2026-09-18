<?php
declare(strict_types=1);

require __DIR__ . '/../src/KoutenDB.php';
require_once __DIR__ . '/../src/TcpExceptions.php';

use KoutenDB\KoutenDB;
use KoutenDB\KoutenId;

// JSONL adapter for the language-independent KoutenDB conformance runner.
ini_set('zend.exception_ignore_args', '0');
$secrets = ['secret-password'];
$db = null;
while (($line = fgets(STDIN)) !== false) {
    try {
        $op = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        foreach (['password', 'authToken', 'secretKey'] as $key) {
            $secret = $op['options'][$key] ?? '';
            if ($secret !== '') { $secrets[] = $secret; }
        }
        $result = match ($op['op']) {
            'connect' => (function () use ($op, &$db) {
                $db?->close();
                $db = KoutenDB::connectTcp($op['peers'], timeout: $op['timeout'] ?? 1.0,
                    readTimeout: $op['readTimeout'] ?? 1.0, writeTimeout: $op['writeTimeout'] ?? 1.0,
                    options: $op['options'] ?? []);
                return 'connected';
            })(),
            'put' => (string)$db->putCodec($op['ring'], base64_decode($op['payload'], true), $op['codec'] ?? 'raw'),
            'putJson' => (string)$db->putJson($op['ring'], $op['value']),
            'getJson' => $db->getJson(KoutenId::parse($op['id'])),
            'get' => (function () use ($op, $db) {
                $value = $db->getEncoded(KoutenId::parse($op['id']));
                return $value === null ? null : ['payload' => base64_encode($value->payload), 'codec' => $value->codec];
            })(),
            'query' => $db->queryJson(KoutenId::parse($op['id']), $op['selection']),
            'health' => $db->health(),
            'debug' => (function () use ($db) { ob_start(); var_dump($db); return ob_get_clean(); })(),
            'ffiAbsent' => !class_exists('FFI'),
            'ffiOpen' => KoutenDB::open(),
            'close' => (function () use ($db) { $db?->close(); return 'closed'; })(),
            default => throw new InvalidArgumentException('Unknown conformance operation'),
        };
        echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR) . "\n";
    } catch (Throwable $e) {
        $details = (string)$e . print_r($e->getTrace(), true);
        foreach ($secrets as $secret) {
            if (str_contains($details, $secret)) {
                fwrite(STDERR, "Credential found in exception diagnostics\n");
                exit(1);
            }
        }
        echo json_encode(['ok' => false, 'error' => (new ReflectionClass($e))->getShortName(),
            'message' => $e->getMessage()], JSON_THROW_ON_ERROR) . "\n";
    }
    flush();
}
$db?->close();
