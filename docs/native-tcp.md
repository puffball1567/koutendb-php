# Native PHP TCP Transport

Native TCP uses PHP streams to connect to `koutend`. It needs neither
`libkoutendb.so` nor `ext-ffi` nor `ext-sockets`. TLS requires `ext-openssl`;
SECRET_KEY authentication additionally requires `ext-sodium`. These are PHP
extensions, not KoutenDB native bindings. PHP must be 64-bit and version 8.2+.

This feature is currently unreleased. The published 0.1.3 package remains FFI-only.
Use a Composer path repository pointing to this checkout to test this branch.
After publication the package name remains `koutendb/koutendb`.

## Choose A Transport

| API | Result | Runtime dependency | Use |
| --- | --- | --- | --- |
| `open()`, `openDir()` | `KoutenDB` | FFI + shared library | Embedded storage |
| `connect()`, `connectAuth()`, `connectAuthTls()` | `KoutenDB` | FFI + shared library | Existing C ABI remote transport |
| `connectTcp()`, `connectTcpAuth()`, `connectTcpTls()` | `TcpClient` | PHP streams | Native server transport |

Existing factories have not changed semantics. The new factories return a
dedicated `TcpClient`, not the FFI class: unsupported administrative methods do
not accidentally load FFI. Type an application adapter for the methods it uses,
or use `TcpClient` explicitly. Connection establishment is eager and errors are
reported before the factory returns.

## Start A Server

Install `koutend` following the [core installation guide](https://github.com/puffball1567/koutendb/blob/main/docs/installation.md).
For a local development server:

```sh
koutend --id=0 --peers=127.0.0.1:17301 --data=./data --galaxy=publarish
```

Without TLS, restrict the listener to localhost or a trusted private network
(including an isolated Docker network). Do not expose a plaintext server to
the public Internet. A Docker network is not trusted merely because it is Docker.
For production, configure server credentials, TLS certificates, persistent
storage, and access controls using the core deployment documentation.

## Save And Read

```php
use KoutenDB\KoutenDB;
use KoutenDB\KoutenId;

$db = KoutenDB::connectTcp(
    peers: ['127.0.0.1:17301'],
    timeout: 3.0,
    readTimeout: 5.0,
    writeTimeout: 5.0,
    options: ['galaxy' => 'publarish'],
);
try {
    $id = $db->putJson('publarish/articles', ['title' => 'Example']);
    $savedId = (string)$id;
    $article = $db->getJson(KoutenId::parse($savedId));
    $title = $db->queryJson($id, '{ title }');
    $health = $db->health();
} finally {
    $db->close();
}
```

`put(ring, bytes)` stores raw bytes; `get(id)` returns bytes or `null` for a
missing record. `putCodec(ring, bytes, codec)` supports `raw`, `json`, `nif`, and
`bif`. `getEncoded(id)` preserves the codec. `query()` returns projected JSON
text (`''` on a missing record); `queryJson()` returns its decoded value or
`null`. As with the existing PHP API, JSON values are decoded as associative arrays.

TCP IDs contain six fields, including the server-returned `period` and `head`.
Persist the entire string. The existing four-field FFI ID representation is
unchanged, but it cannot be used directly for TCP reads because it lacks routing
metadata. The driver does not derive orbit or placement rules. There is no
all-node fallback search. A missing result is not proof of global absence while
an asynchronous cluster is still converging.

## Authentication And TLS

```php
$db = KoutenDB::connectTcpAuth(
    peers: ['127.0.0.1:17301'],
    username: 'writer',
    password: $password,
    secretKey: $secretKey,
    galaxy: 'publarish',
);

$db = KoutenDB::connectTcpTls(
    peers: ['db.example.com:17301'],
    tlsCaFile: '/etc/publarish/db-ca.pem',
    tlsServerName: 'db.example.com',
    options: ['username' => 'writer', 'password' => $password, 'galaxy' => 'publarish'],
);
```

`authToken` without a username uses the server's `token` user convention.
`secretKey` enables the existing challenge/response and authenticated SEC
frames; it is not an alternative implementation of TLS. TLS verifies the
certificate and hostname by default and uses TLS 1.2 or later. Supply
`tlsInsecureSkipVerify: true` only for explicit development testing, never
production. TLS does not fall back to plaintext on failure.

## Connection Options

| Option | Default | Meaning |
| --- | --- | --- |
| `username`, `password`, `authToken`, `secretKey`, `galaxy` | empty | Authentication and galaxy binding |
| `tls` | `false` | Enable TLS in `connectTcp` / `connectTcpAuth` options |
| `tlsCaFile`, `tlsServerName` | empty | CA file and peer name/SNI override |
| `tlsInsecureSkipVerify` | `false` | Development-only verification bypass |
| `maxFrameBytes` | 67,108,864 | Maximum body bytes; can be lowered |
| `maxRedirects` | 8 | Redirect bound, configurable from 0 to 32 |
| `retryReads` | `true` | At most one reconnect and retry for read I/O failures |

The peer list is ordered by server node ID, just as in the core topology. Do
not reorder it or provide only a subset of a cluster. Redirects can only target
these configured peers. Each client reuses one stream per contacted node;
connections are not shared between PHP processes. `close()` is idempotent and
terminal: create a new client to reconnect after an explicit close.

`timeout`, `readTimeout`, and `writeTimeout` are separate positive seconds values
(maximum 3600). Reads use one monotonic deadline across a response header and
body; drip-fed bytes do not reset it. A retry and redirects add their own bounded
round trips, so these are not a single deadline for the entire multi-hop query.
DNS resolution follows the platform resolver's policy; the connect timeout is
not an end-to-end DNS deadline.

## Laravel Configuration And Fallback

Example environment names for the application's adapter:

```dotenv
KOUTENDB_ENABLED=true
KOUTENDB_TRANSPORT=tcp
KOUTENDB_ENDPOINTS=127.0.0.1:17301
KOUTENDB_CONNECT_TIMEOUT=3
KOUTENDB_READ_TIMEOUT=5
KOUTENDB_WRITE_TIMEOUT=5
KOUTENDB_GALAXY=publarish
KOUTENDB_TLS_ENABLED=false
```

Map these through Laravel `config()` to the factory parameters. Parse booleans
as booleans, not strings; `"false"` must not become truthy. Credentials, CA path,
and server name can be added to the same application configuration. The driver
does not read global environment variables implicitly.

```php
use KoutenDB\ConnectionException;
use KoutenDB\IndeterminateWriteException;

try {
    $article = $db->getJson($id);
} catch (ConnectionException $e) {
    $article = $articleRepository->findInMariaDb($articleId);
}
```

`ConnectionTimeoutException` extends `ConnectionException`. Other public errors
are `AuthenticationException`, `ProtocolException`, `VersionMismatchException`
(a protocol error), `ServerException`, and `IndeterminateWriteException`.
All extend `KoutenDBException`. Server error messages are deliberately not echoed
back into exceptions, and credentials are excluded from debug output.

Treat authentication/version/protocol errors as configuration or integrity
incidents, not normal cache misses. MariaDB can remain the canonical article
store while KoutenDB provides a derived retrieval path. A failed derived write
can be queued for reconciliation, but **do not blindly retry a PUT after
`IndeterminateWriteException`**: the original write may already exist. No write
is automatically replayed after its request may have been transmitted.

## Protocol And Tests

Every new connection authenticates, binds the galaxy if requested, verifies
`WIREVER 1`, and enables `CODECMETA ON` before application commands. Unsupported
versions fail closed. Wire v1 is pre-v1 product protocol, not a promise that all
future servers will remain compatible. Pin and test the server/driver pair.

```sh
php -n tests/tcp_unit.php
COMPOSER_PHAR=/path/to/composer.phar bash tests/composer_tcp.sh
cd ../koutendb
bash scripts/native_driver_conformance.sh php -n ../koutendb-php/tests/tcp_adapter.php
```

The last command requires the matching core conformance-harness change. It
builds a TLS server, runs scripted fragmentation/error/redirect fixtures and
real plaintext/password/token/secret/TLS scenarios, and removes temporary data.
`php -n` must have no compiled-in FFI; the unit/packaging tests explicitly check
this. It may still provide OpenSSL and sodium as built-in extensions.

The existing FFI test remains `php -d ffi.enable=1 tests/driver_test.php` with a
compatible library. The native API is intentionally limited; full admin APIs,
pooling, automatic write deduplication, PDO, and Eloquent are not included.

### Validation Record

Validated on 2026-09-18. All four jobs passed in the
[PHP compatibility matrix](https://github.com/puffball1567/koutendb-php/actions/runs/35305266253).

| Runtime | Native TCP without FFI | Existing FFI regression |
| --- | --- | --- |
| PHP 8.2 / Ubuntu | PASS | PASS |
| PHP 8.3 / Ubuntu | PASS | PASS |

Each native job verifies Composer installation and input validation without FFI,
then runs 27 scripted protocol scenarios and six real-server modes: plaintext,
password, token, SECRET_KEY, TLS, and TLS + SECRET_KEY. Credential redaction is
checked with exception argument capture enabled, including stack traces. Each
FFI job builds the v0.14.3 C ABI and runs the existing driver tests and embedded
example. The native jobs pin core commit
`e36b424bcfd9cd0dfa24ae121f4b4dd028b0eaac`, which adds the shared harness without
changing the v0.14.3 server runtime.

PHP 8.3.13 also passed the complete native suite locally and a separate Composer
consumer installation using a path repository. The core harness was merged in
[core PR #136](https://github.com/puffball1567/koutendb/pull/136) after its Linux
and macOS CI passed.

The local Docker rerun was blocked by insufficient Docker storage during base
image extraction; it is not counted as a passed test. The matrix above ran
directly on Ubuntu CI runners. Real-server scenarios use one node; redirect
behavior uses scripted peers. Publarish application-level integration has not
been exercised here.
