# RocheDB PHP Driver

PHP FFI driver for [RocheDB](https://github.com/puffball1567/rochedb).

This package wraps the RocheDB C ABI. It is intended as the foundation for
plain PHP integrations and later Laravel/Symfony adapters. It does not try to
pretend RocheDB is an SQL database or an Eloquent model backend.

## Status

- Package: [Packagist `rochedb/rochedb`](https://packagist.org/packages/rochedb/rochedb)
- Current mode: C ABI / FFI wrapper
- PHP: 8.2+
- Requires: `ext-ffi`
- RocheDB core: local C ABI v2 shared library, RocheDB core v0.3.0+

## Install

Install from Packagist:

```sh
composer require rochedb/rochedb:^0.1
```

For local development from a checkout, you can still use a Composer path repository.

Build the RocheDB shared library first:

```sh
git clone https://github.com/puffball1567/rochedb.git
cd rochedb
nimble install -y
nim c --app:lib -d:release --nimcache:/tmp/nimcache_roche_capi -o:lib/librochedb.so src/rochedb_capi.nim
```

At runtime, make sure PHP can find both the driver and `librochedb.so`:

```sh
LD_LIBRARY_PATH=/path/to/rochedb/lib php app.php
```

Local PHP must have `ext-ffi` enabled. If Composer reports `ext-ffi` as missing, enable PHP FFI for CLI and runtime use before installing in a real project. For repository verification without changing local PHP, use the Docker smoke test below.

## Example

```php
<?php
use RocheDB\RocheDB;
use RocheDB\RocheId;

$db = RocheDB::open(8, "/path/to/rochedb/lib/librochedb.so");
$db->setGalaxyDescription("Product and support knowledge");
$db->setRingDescription("docs", "Documentation ring");

$id = $db->putJson("docs/php", [
    "title" => "PHP context",
    "kind" => "example",
]);

$roundtrip = RocheId::parse((string) $id);
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
cd /path/to/rochedb
nim c --app:lib -d:release --nimcache:/tmp/nimcache_roche_capi -o:lib/librochedb.so src/rochedb_capi.nim
```

From this driver repository:

```sh
ROCHEDB_CORE_DIR=/path/to/rochedb ./docker-test.sh
```

`docker-test.sh` builds a small `php:8.3-cli` based image with FFI enabled and
mounts the RocheDB core checkout into the container.

## Current API

| Area | API |
|---|---|
| Open / connect | `RocheDB::open`, `openDir`, `connect`, `connectAuth` |
| Writes | `put`, `putCodec`, `putJson`, `putNif`, `putBif`, `putVec`, `putVecCodec`, `putJsonVec`, `putNifVec`, `putBifVec` |
| Reads | `get`, `getEncoded`, `getJson`, `batchGet`, `readRing` |
| Payload codecs | `EncodedPayload`, `raw`, `json`, `nif`, `bif` |
| Projection | `query`, `queryJson` |
| Retrieval | `retrieve`, `RetrieveResult`, `RocheHit` |
| Atlas | `atlas` |
| Metadata | `configureRing`, `setGalaxyDescription`, `setRingDescription` |
| Orbit helpers | `locate`, `nextVisit`, `nextJoin` |
| IDs | `RocheId`, `RocheId::parse`, `RocheId::__toString` |
| Errors | `RocheDBException` |

## Laravel Direction

Laravel support should live in a separate thin adapter, likely
`rochedb-laravel`. The PHP driver should stay framework-neutral. The Laravel
package can provide a service provider, facade, configuration, and a more
Laravel-shaped API for persistent semantic state, context, and retrieval
working sets. It should not try to emulate Eloquent.
