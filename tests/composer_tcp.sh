#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
: "${COMPOSER_PHAR:?Set COMPOSER_PHAR to the composer.phar path}"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/kouten-php-composer.XXXXXX")"
trap 'rm -rf -- "$TMP"' EXIT
php -n -r 'if (class_exists("FFI")) { exit(1); }'
php -n -r '
$manifest = ["name" => "test/native-tcp-consumer", "require" => ["koutendb/koutendb" => "*@dev"],
    "repositories" => [["type" => "path", "url" => $argv[2], "options" => ["symlink" => false]], ["packagist.org" => false]]];
file_put_contents($argv[1], json_encode($manifest, JSON_THROW_ON_ERROR));
' "$TMP/composer.json" "$ROOT"
COMPOSER_HOME="$TMP/home" php -n "$COMPOSER_PHAR" install --working-dir="$TMP" --no-interaction --no-dev
php -n -r '
require $argv[1];
if (class_exists("FFI") || !class_exists("KoutenDB\\TcpClient") || !class_exists("KoutenDB\\ConnectionException")) { exit(1); }
echo "PASS Composer consumer install without FFI\n";
' "$TMP/vendor/autoload.php"
