# KoutenDB PHP Driver

PHP FFI driver for [KoutenDB](https://github.com/puffball1567/koutendb).

This package wraps the KoutenDB C ABI. It is intended as the foundation for
plain PHP integrations and later Laravel/Symfony adapters. It does not try to
pretend KoutenDB is an SQL database or an Eloquent model backend.

## Status

- Package: [Packagist `koutendb/koutendb`](https://packagist.org/packages/koutendb/koutendb)
- Current source version: `0.1.3`
- Current mode: C ABI / FFI wrapper
- PHP: 8.2+
- Requires: `ext-ffi`
- KoutenDB core: local C ABI v2 shared library; v0.12-compatible build required for persistence/maintenance APIs

## Install

Install from Packagist:

```sh
composer require koutendb/koutendb:^0.1
```

For local development from a checkout, you can still use a Composer path repository.

Build the KoutenDB shared library first:

```sh
git clone https://github.com/puffball1567/koutendb.git
cd koutendb
nimble install -y
nim c --app:lib -d:release --nimcache:/tmp/nimcache_kouten_capi -o:lib/libkoutendb.so src/koutendb_capi.nim
```

At runtime, make sure PHP can find both the driver and `libkoutendb.so`:

```sh
LD_LIBRARY_PATH=/path/to/koutendb/lib php app.php
```

Local PHP must have `ext-ffi` enabled. If Composer reports `ext-ffi` as missing, enable PHP FFI for CLI and runtime use before installing in a real project. For repository verification without changing local PHP, use the Docker smoke test below.

## Example

```php
<?php
use KoutenDB\KoutenDB;
use KoutenDB\KoutenId;

$db = KoutenDB::open(8, "/path/to/koutendb/lib/libkoutendb.so");
$db->setGalaxyDescription("Product and support knowledge");
$db->setRingDescription("docs", "Documentation ring");

$id = $db->putJson("docs/php", [
    "title" => "PHP context",
    "kind" => "example",
]);

$roundtrip = KoutenId::parse((string) $id);
$doc = $db->getJson($roundtrip);
$view = $db->queryJson($id, "{ title }");

$vecId = $db->putJsonVec("docs/php", [
    "title" => "Vector-backed PHP document",
    "kind" => "example",
], [1.0, 0.0]);
$encoded = $db->getEncoded($id);
$page = $db->readRing("docs/php", [
    "filter" => ["kind" => "example"],
    "selection" => "{ title }",
    "limit" => 10,
]);
$value = $db->get($vecId);
$atlas = $db->atlas([1.0, 0.0], 8);
$db->close();
```

## Test

```bash
cd /path/to/koutendb
nim c --app:lib -d:release --nimcache:/tmp/nimcache_kouten_capi -o:lib/libkoutendb.so src/koutendb_capi.nim
```

From this driver repository:

```sh
KOUTENDB_CORE_DIR=/path/to/koutendb ./docker-test.sh
```

`docker-test.sh` builds a small `php:8.3-cli` based image with FFI enabled and
mounts the KoutenDB core checkout into the container.

## Current API

| Area | API |
|---|---|
| Open / connect | `KoutenDB::open`, `openDir`, `openDirWith`, `connect`, `connectAuth` |
| TLS connect | `connectAuthTls`, `connectAuthTlsInsecure` |
| Writes / mutations | `put` and codec/vector helpers, `update`, `updateCodec`, `updateJson`, `remove` |
| Reads | `get`, `getEncoded`, `getJson`, `exists`, `batchGet`, `readRing` |
| Payload codecs | `EncodedPayload`, `raw`, `json`, `nif`, `bif` |
| Projection | `query`, `queryJson` |
| Retrieval | `retrieve`, `RetrieveResult`, `KoutenHit` |
| Atlas | `atlas` |
| Metadata | `configureRing`, `setGalaxyDescription`, `setRingDescription` |
| Orbit helpers | `locate`, `nextVisit`, `nextJoin` |
| IDs | `KoutenId`, `KoutenId::parse`, `KoutenId::__toString` |
| Errors | `KoutenDBException` |
| Metrics | `metrics`, `checkpointMetrics` |
| Segment maintenance | `segmentStatus`, `planSegmentMaintenance`, `runSegmentMaintenance`, `segmentMaintenanceStatus`, `recoverSegmentMaintenance` |
| Generation checkpoints | `createCheckpoint`, `checkpointStatus`, `listCheckpoints`, `cleanupCheckpoints`, `restoreCheckpoint` |

## TLS

TLS requires an KoutenDB core built with `-d:ssl`. The shared library from
`scripts/build_capi.sh` is built with it; a library built without it fails a TLS
connect with `TLS support requires building KoutenDB with -d:ssl`.

To reach a server whose certificate is signed by a private CA — or is
self-signed — point at the certificate PEM. Verification stays on:

```php
$db = KoutenDB::connectAuthTls(
    '127.0.0.1:17651',
    'alice',
    'secret',
    tlsCaFile: '/path/to/server.crt',
);
```

`connectAuthTlsInsecure()` (or `connectAuthTls(..., tlsInsecureSkipVerify: true)`)
disables certificate verification. The connection is then encrypted but
unauthenticated and trivially impersonable, so it is for local smoke tests only
— never a production server. Prefer a `tlsCaFile` for self-signed certificates.

## Laravel Direction

Laravel support should live in a separate thin adapter, likely
`koutendb-laravel`. The PHP driver should stay framework-neutral. The Laravel
package can provide a service provider, facade, configuration, and a more
Laravel-shaped API for persistent semantic state, context, and retrieval
working sets. It should not try to emulate Eloquent.
