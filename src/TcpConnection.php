<?php
declare(strict_types=1);

namespace KoutenDB;

/** One bounded, reusable stream. A framing or I/O failure invalidates it. @internal */
final class TcpConnection
{
    private $stream;
    private string $raw = '';
    private string $plain = '';
    private ?string $key = null;
    private float $deadline = 0;
    private const HEADER_LIMIT = 8192;

    public function __construct(
        string $endpoint,
        float $connectTimeout,
        private readonly float $readTimeout,
        private readonly float $writeTimeout,
        private readonly int $maxFrameBytes,
        #[\SensitiveParameter] array $options,
    ) {
        $tls = $options['tls'];
        if ($tls && !extension_loaded('openssl')) {
            throw new ConnectionException('TLS requires ext-openssl');
        }
        $ssl = [
            'verify_peer' => !$options['tlsInsecureSkipVerify'],
            'verify_peer_name' => !$options['tlsInsecureSkipVerify'],
            'SNI_enabled' => true,
            'disable_compression' => true,
        ];
        if ($tls) {
            $ssl['crypto_method'] = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }
        if ($options['tlsServerName'] !== '') {
            $ssl['peer_name'] = $options['tlsServerName'];
        }
        if ($options['tlsCaFile'] !== '') {
            $ssl['cafile'] = $options['tlsCaFile'];
        }
        $ctx = stream_context_create(['ssl' => $ssl, 'socket' => ['tcp_nodelay' => true]]);
        $start = hrtime(true);
        $this->stream = @stream_socket_client(($tls ? 'tls' : 'tcp') . '://' . $endpoint,
            $errno, $error, $connectTimeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($this->stream === false) {
            if ((hrtime(true) - $start) / 1e9 >= $connectTimeout) {
                throw new ConnectionTimeoutException('Connection or TLS handshake timed out');
            }
            throw new ConnectionException('Unable to connect or verify TLS peer');
        }
        stream_set_blocking($this->stream, false);
    }

    public function __debugInfo(): array { return ['connected' => is_resource($this->stream)]; }
    public function __destruct() { $this->close(); }
    public function close(): void
    {
        if (is_resource($this->stream)) { fclose($this->stream); }
        $this->stream = null;
        $this->raw = $this->plain = '';
        if ($this->key !== null) { sodium_memzero($this->key); }
        $this->key = null;
    }

    public function enableSecure(#[\SensitiveParameter] string $key): void { $this->key = $key; }
    public function beginRead(): void { $this->deadline = hrtime(true) / 1e9 + $this->readTimeout; }

    private function wait(bool $writing): void
    {
        $left = $this->deadline - hrtime(true) / 1e9;
        if ($left <= 0) { throw new ConnectionTimeoutException('TCP operation timed out'); }
        $read = $writing ? [] : [$this->stream];
        $write = $writing ? [$this->stream] : [];
        $except = [];
        $seconds = (int)$left;
        $ready = @stream_select($read, $write, $except, $seconds, (int)(($left - $seconds) * 1e6));
        if ($ready === false) { throw new ConnectionException('TCP readiness check failed'); }
        if ($ready === 0) { throw new ConnectionTimeoutException('TCP operation timed out'); }
    }

    public function send(#[\SensitiveParameter] string $header, #[\SensitiveParameter] string $body = '', ?bool &$attempted = null): void
    {
        if (strlen($header) > self::HEADER_LIMIT || strpbrk($header, "\r\n\0") !== false || strlen($body) > $this->maxFrameBytes) {
            throw new \InvalidArgumentException('Request exceeds wire bounds');
        }
        $frame = $header . "\n" . $body;
        if ($this->key !== null) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = $nonce . sodium_crypto_secretbox($frame, $nonce, $this->key);
            $frame = 'SEC ' . strlen($cipher) . "\n" . $cipher;
        }
        $this->deadline = hrtime(true) / 1e9 + $this->writeTimeout;
        $offset = 0;
        while ($offset < strlen($frame)) {
            $this->wait(true);
            // Even a failed write can have transmitted bytes: never replay a mutation.
            $attempted = true;
            $n = @fwrite($this->stream, substr($frame, $offset, 65536));
            if ($n === false || $n === 0) { throw new ConnectionException('TCP write failed'); }
            $offset += $n;
        }
        $this->beginRead();
    }

    private function fillRaw(): void
    {
        $this->wait(false);
        $chunk = @fread($this->stream, 8192);
        if ($chunk === false || ($chunk === '' && feof($this->stream))) {
            throw new ConnectionException('TCP peer disconnected');
        }
        $this->raw .= $chunk;
    }

    private function rawLine(): string
    {
        while (($nl = strpos($this->raw, "\n")) === false) {
            if (strlen($this->raw) > self::HEADER_LIMIT) { throw new ProtocolException('Response header exceeds limit'); }
            $this->fillRaw();
        }
        if ($nl > self::HEADER_LIMIT) { throw new ProtocolException('Response header exceeds limit'); }
        $line = substr($this->raw, 0, $nl);
        $this->raw = substr($this->raw, $nl + 1);
        return $line;
    }

    private function rawBytes(int $length): string
    {
        while (strlen($this->raw) < $length) { $this->fillRaw(); }
        $result = substr($this->raw, 0, $length);
        $this->raw = substr($this->raw, $length);
        return $result;
    }

    public static function length(#[\SensitiveParameter] string $value, int $maximum): int
    {
        if (!preg_match('/^(0|[1-9][0-9]*)$/D', $value) || strlen($value) > strlen((string)$maximum) ||
            (strlen($value) === strlen((string)$maximum) && strcmp($value, (string)$maximum) > 0)) {
            throw new ProtocolException('Invalid or oversized response length');
        }
        return (int)$value;
    }

    private function fillPlain(): void
    {
        $parts = explode(' ', $this->rawLine());
        if (count($parts) !== 2 || $parts[0] !== 'SEC') { throw new ProtocolException('Expected encrypted frame'); }
        $n = self::length($parts[1], $this->maxFrameBytes + self::HEADER_LIMIT + 41);
        if ($n < 40) { throw new ProtocolException('Truncated encrypted frame'); }
        $cipher = $this->rawBytes($n);
        $value = sodium_crypto_secretbox_open(substr($cipher, 24), substr($cipher, 0, 24), $this->key);
        if ($value === false) { throw new ProtocolException('Encrypted frame authentication failed'); }
        if (strlen($this->plain) + strlen($value) > $this->maxFrameBytes + self::HEADER_LIMIT + 1) {
            throw new ProtocolException('Decrypted response exceeds limit');
        }
        $this->plain .= $value;
    }

    public function header(): array
    {
        if ($this->key === null) {
            $line = $this->rawLine();
        } else {
            while (($nl = strpos($this->plain, "\n")) === false) {
                if (strlen($this->plain) > self::HEADER_LIMIT) { throw new ProtocolException('Response header exceeds limit'); }
                $this->fillPlain();
            }
            if ($nl > self::HEADER_LIMIT) { throw new ProtocolException('Response header exceeds limit'); }
            $line = substr($this->plain, 0, $nl);
            $this->plain = substr($this->plain, $nl + 1);
        }
        $line = rtrim($line, "\r");
        if ($line === '' || preg_match('/[^\x20-\x7e]/', $line)) { throw new ProtocolException('Invalid response header'); }
        return explode(' ', $line);
    }

    public function bytes(int $length): string
    {
        if ($this->key === null) { return $this->rawBytes($length); }
        while (strlen($this->plain) < $length) { $this->fillPlain(); }
        $result = substr($this->plain, 0, $length);
        $this->plain = substr($this->plain, $length);
        return $result;
    }
}
