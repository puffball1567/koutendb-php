<?php
declare(strict_types=1);

namespace KoutenDB;

require_once __DIR__ . '/TcpId.php';
require_once __DIR__ . '/TcpConnection.php';

/** Native PHP stream transport. Only the documented common API is exposed. */
final class TcpClient
{
    private array $connections = [];
    private array $options;
    private bool $closed = false;

    public function __construct(
        private readonly array $peers,
        private readonly float $timeout = 3.0,
        private readonly float $readTimeout = 5.0,
        private readonly float $writeTimeout = 5.0,
        #[\SensitiveParameter] array $options = [],
    ) {
        if (PHP_INT_SIZE < 8) { throw new \RuntimeException('Native TCP requires 64-bit PHP'); }
        $defaults = ['username' => '', 'password' => '', 'authToken' => '', 'secretKey' => '', 'galaxy' => '',
            'tls' => false, 'tlsCaFile' => '', 'tlsServerName' => '', 'tlsInsecureSkipVerify' => false,
            'maxFrameBytes' => 67108864, 'maxRedirects' => 8, 'retryReads' => true];
        if (array_diff_key($options, $defaults)) { throw new \InvalidArgumentException('Unknown TCP option'); }
        $this->options = array_merge($defaults, $options);
        foreach ($defaults as $key => $value) {
            if (get_debug_type($this->options[$key]) !== get_debug_type($value)) {
                throw new \InvalidArgumentException('Invalid TCP option type: ' . $key);
            }
        }
        if (!array_is_list($peers) || count($peers) < 1 || count($peers) > 64) {
            throw new \InvalidArgumentException('Provide 1..64 ordered peers');
        }
        foreach ($peers as $peer) {
            if (!is_string($peer) || !preg_match('/^(?:[a-zA-Z0-9._-]+|\[[a-fA-F0-9:]+\]):([0-9]{1,5})$/D', $peer, $m) ||
                (int)$m[1] < 1 || (int)$m[1] > 65535) { throw new \InvalidArgumentException('Invalid TCP endpoint'); }
        }
        foreach ([$timeout, $readTimeout, $writeTimeout] as $t) {
            if (!is_finite($t) || $t <= 0 || $t > 3600) { throw new \InvalidArgumentException('Timeout must be in (0, 3600] seconds'); }
        }
        if ($this->options['maxFrameBytes'] < 1 || $this->options['maxFrameBytes'] > 67108864 ||
            $this->options['maxRedirects'] < 0 || $this->options['maxRedirects'] > 32) {
            throw new \InvalidArgumentException('Invalid TCP resource limit');
        }
        if ($this->options['username'] === '' && $this->options['authToken'] !== '') {
            $this->options['username'] = 'token';
            $this->options['password'] = $this->options['authToken'];
        }
        foreach (['username', 'password', 'galaxy'] as $key) {
            if (strlen($this->options[$key]) > 1024 || preg_match('/[\x00-\x20\x7f]/', $this->options[$key])) {
                throw new \InvalidArgumentException('Authentication and galaxy fields must not contain whitespace or controls');
            }
        }
        if (($this->options['secretKey'] !== '' || $this->options['password'] !== '') && $this->options['username'] === '') {
            throw new \InvalidArgumentException('Authentication requires a username');
        }
        if ($this->options['secretKey'] !== '' && !extension_loaded('sodium')) {
            throw new AuthenticationException('Secret-key authentication requires ext-sodium');
        }
        if (!$this->options['tls'] && ($this->options['tlsCaFile'] !== '' || $this->options['tlsServerName'] !== '' || $this->options['tlsInsecureSkipVerify'])) {
            throw new \InvalidArgumentException('TLS options require tls=true');
        }
        $this->connection(0);
    }

    public function __debugInfo(): array { return ['transport' => 'tcp', 'closed' => $this->closed]; }
    public function __destruct() { $this->close(); }
    public function close(): void
    {
        foreach ($this->connections as $c) { $c->close(); }
        $this->connections = [];
        $this->closed = true;
    }

    private function dropConnections(): void
    {
        foreach ($this->connections as $c) { $c->close(); }
        $this->connections = [];
    }

    private function expect(#[\SensitiveParameter] array $parts, string $tag, int $count, bool $auth = false): void
    {
        if ($parts[0] === 'ERR') {
            // Do not reflect arbitrary server text, which may contain credentials.
            if ($auth) { throw new AuthenticationException('Authentication or galaxy selection rejected'); }
            throw new ServerException('KoutenDB rejected the request');
        }
        if (count($parts) !== $count || $parts[0] !== $tag) { throw new ProtocolException('Unexpected response shape'); }
    }

    private function connection(int $node): TcpConnection
    {
        if ($this->closed) { throw new ConnectionException('TCP client is closed'); }
        if ($node < 0 || $node >= count($this->peers)) { throw new ProtocolException('Redirect target is outside configured peers'); }
        if (isset($this->connections[$node])) { return $this->connections[$node]; }
        $o = $this->options;
        $c = new TcpConnection($this->peers[$node], $this->timeout, $this->readTimeout, $this->writeTimeout, $o['maxFrameBytes'], $o);
        try {
            if ($o['username'] !== '') {
                if ($o['secretKey'] !== '') {
                    $c->send('AUTHCHAL ' . $o['username']);
                    $chal = $c->header();
                    $this->expect($chal, 'CHAL', 2, true);
                    if (!preg_match('/^[a-fA-F0-9]{64}$/D', $chal[1])) { throw new ProtocolException('Invalid authentication challenge'); }
                    $nonce = random_bytes(24);
                    $key = sodium_crypto_generichash("koutendb-auth-v1\0box\0" . $o['secretKey'], '', 32);
                    $message = "koutendb-auth-v1\n" . $o['username'] . "\n" . $o['password'] . "\n" . $chal[1];
                    $c->send('AUTHRESP ' . bin2hex($nonce . sodium_crypto_secretbox($message, $nonce, $key)));
                    $this->expect($c->header(), 'OK', 2, true);
                    $c->enableSecure(sodium_crypto_generichash("koutendb-auth-v1\0transport\0" . $chal[1] . "\0" . $o['secretKey'], '', 32));
                } else {
                    $c->send('AUTH ' . $o['username'] . ' ' . $o['password']);
                    $this->expect($c->header(), 'OK', 2, true);
                }
            }
            if ($o['galaxy'] !== '') {
                $c->send('HELLO ' . $o['galaxy']);
                $this->expect($c->header(), 'OK', 2, true);
            }
            $c->send('WIREVER');
            $version = $c->header();
            if ($version[0] === 'ERR') { throw new AuthenticationException('Connection negotiation rejected'); }
            $this->expect($version, 'WIREVER', 2);
            if ($version[1] !== '1') { throw new VersionMismatchException('Unsupported KoutenDB wire version (expected 1)'); }
            $c->send('CODECMETA ON');
            $codecAck = $c->header();
            $this->expect($codecAck, 'OK', 2);
            if ($codecAck[1] !== 'codec-metadata') { throw new ProtocolException('Codec negotiation failed'); }
            return $this->connections[$node] = $c;
        } catch (\Throwable $e) { $c->close(); throw $e; }
    }

    public function put(string $ring, string $payload): KoutenId { return $this->putCodec($ring, $payload, 'raw'); }
    public function putJson(string $ring, mixed $value): KoutenId
    {
        return $this->putCodec($ring, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'json');
    }
    public function putCodec(string $ring, string $payload, string $codec): KoutenId
    {
        if (!in_array($codec, ['raw', 'json', 'nif', 'bif'], true) || $ring === '' || strlen($ring) + strlen($payload) > $this->options['maxFrameBytes']) {
            throw new \InvalidArgumentException('Invalid codec, ring, or oversized payload');
        }
        $attempted = false;
        try {
            $c = $this->connection(0);
            $c->send('PUTR ' . strlen($ring) . ' ' . strlen($payload) . ' 0 ' . $codec, $ring . $payload, $attempted);
            $r = $c->header();
            $this->expect($r, 'ID', 7);
            return TcpId::fromFields(array_slice($r, 1));
        } catch (ConnectionException | ProtocolException $e) {
            $this->dropConnections();
            if ($attempted) { throw new IndeterminateWriteException('Write outcome is unknown; do not automatically retry', 0, $e); }
            throw $e;
        } catch (ServerException $e) { $this->dropConnections(); throw $e; }
    }

    private function readWithRetry(callable $operation): mixed
    {
        for ($attempt = 0; ; $attempt++) {
            try { return $operation(); }
            catch (ConnectionException $e) {
                $this->dropConnections();
                if ($this->closed || !$this->options['retryReads'] || $attempt === 1) { throw $e; }
            } catch (ProtocolException | ServerException | AuthenticationException $e) {
                $this->dropConnections(); throw $e;
            }
        }
    }

    public function health(int $node = 0): string
    {
        return $this->readWithRetry(function () use ($node): string {
            $c = $this->connection($node);
            $c->send('HEALTH');
            $r = $c->header();
            if ($r[0] === 'ERR') { throw new ServerException('Health request rejected'); }
            if ($r[0] !== 'OK' || count($r) < 2 || !preg_match('/^node=[0-9]+$/D', $r[1])) {
                throw new ProtocolException('Invalid health response');
            }
            return implode(' ', array_slice($r, 1));
        });
    }

    private function read(KoutenId $id, ?string $selection): ?EncodedPayload
    {
        $initial = TcpId::fields($id);
        if ($selection !== null && strlen($selection) > $this->options['maxFrameBytes']) { throw new \InvalidArgumentException('Selection exceeds limit'); }
        return $this->readWithRetry(function () use ($initial, $selection): ?EncodedPayload {
            $fields = $initial;
            $node = 0;
            for ($redirects = 0; ; $redirects++) {
                $c = $this->connection($node);
                $c->send(($selection === null ? 'GETID ' : 'QRYID ') . implode(' ', $fields) .
                    ($selection === null ? '' : ' ' . strlen($selection)), $selection ?? '');
                $r = $c->header();
                if (in_array($r[0], ['MISS', 'GONE'], true)) {
                    $this->expect($r, $r[0], 1);
                    return null;
                }
                if ($r[0] === 'FWD') {
                    if ($redirects >= $this->options['maxRedirects'] || !in_array(count($r), [7, 8], true)) {
                        throw new ProtocolException('Redirect limit or malformed redirect');
                    }
                    $fields = TcpId::fields(TcpId::fromFields(array_slice($r, 1, 6)));
                    // A forwarder without an owner is resubmitted to this peer for resolution.
                    if (count($r) === 8) { $node = TcpConnection::length($r[7], count($this->peers) - 1); }
                    continue;
                }
                $this->expect($r, 'VAL', 4);
                TcpConnection::length($r[1], count($this->peers) - 1);
                $length = TcpConnection::length($r[2], $this->options['maxFrameBytes']);
                if (!in_array($r[3], ['raw', 'json', 'nif', 'bif'], true)) { throw new ProtocolException('Unknown payload codec'); }
                return new EncodedPayload($c->bytes($length), $r[3]);
            }
        });
    }

    public function get(KoutenId $id): ?string { return $this->read($id, null)?->payload; }
    public function getEncoded(KoutenId $id): ?EncodedPayload { return $this->read($id, null); }
    public function getJson(KoutenId $id): mixed
    {
        $value = $this->get($id);
        return $value === null ? null : json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }
    public function query(KoutenId $id, string $selection): string { return $this->read($id, $selection)?->payload ?? ''; }
    public function queryJson(KoutenId $id, string $selection): mixed
    {
        $value = $this->query($id, $selection);
        return $value === '' ? null : json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }
}
