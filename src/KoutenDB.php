<?php
declare(strict_types=1);

namespace KoutenDB;

use FFI;
use FFI\CData;
use RuntimeException;

class KoutenDBException extends RuntimeException
{
}

final class KoutenId
{
    public function __construct(
        public readonly int|string $parent,
        public readonly int $epoch,
        public readonly int $seq,
        public readonly float $tWrite,
        public readonly ?float $period = null,
        public readonly ?float $head = null,
    ) {
    }

    public static function parse(string $value): self
    {
        $parts = explode(':', $value);
        if (count($parts) === 6) {
            require_once __DIR__ . '/TcpId.php';
            return TcpId::fromFields($parts);
        }
        if (count($parts) !== 4) {
            throw new KoutenDBException("invalid KoutenDB id '{$value}': expected parent:epoch:seq:tWrite");
        }

        if (
            preg_match('/^-?\d+$/', $parts[0]) !== 1 ||
            !ctype_digit($parts[1]) ||
            !ctype_digit($parts[2]) ||
            !is_numeric($parts[3])
        ) {
            throw new KoutenDBException("invalid KoutenDB id '{$value}': fields must be numeric");
        }

        return new self($parts[0], (int)$parts[1], (int)$parts[2], (float)$parts[3]);
    }

    public function __toString(): string
    {
        if ($this->period !== null && $this->head !== null) {
            require_once __DIR__ . '/TcpId.php';
            return implode(':', TcpId::fields($this));
        }
        return "{$this->parent}:{$this->epoch}:{$this->seq}:{$this->tWrite}";
    }
}

final class KoutenHit
{
    public function __construct(
        public readonly KoutenId $id,
        public readonly float $score,
        public readonly string $payload,
    ) {
    }
}

final class RetrieveResult
{
    /** @param list<KoutenHit> $hits */
    public function __construct(
        public readonly array $hits,
        public readonly array $stats,
    ) {
    }
}

final class EncodedPayload
{
    public function __construct(
        public readonly string $payload,
        public readonly string $codec,
    ) {
    }
}

final class KoutenDB
{
    /** Native TCP; no FFI extension or libkoutendb is loaded. */
    public static function connectTcp(
        array $peers,
        float $timeout = 3.0,
        float $readTimeout = 5.0,
        float $writeTimeout = 5.0,
        #[\SensitiveParameter] array $options = [],
    ): TcpClient {
        require_once __DIR__ . '/TcpClient.php';
        return new TcpClient($peers, $timeout, $readTimeout, $writeTimeout, $options);
    }

    public static function connectTcpAuth(
        array $peers,
        string $username = '',
        #[\SensitiveParameter] string $password = '',
        #[\SensitiveParameter] string $authToken = '',
        #[\SensitiveParameter] string $secretKey = '',
        string $galaxy = '',
        float $timeout = 3.0,
        float $readTimeout = 5.0,
        float $writeTimeout = 5.0,
        #[\SensitiveParameter] array $options = [],
    ): TcpClient {
        return self::connectTcp($peers, $timeout, $readTimeout, $writeTimeout,
            array_merge($options, compact('username', 'password', 'authToken', 'secretKey', 'galaxy')));
    }

    public static function connectTcpTls(
        array $peers,
        string $tlsCaFile = '',
        string $tlsServerName = '',
        bool $tlsInsecureSkipVerify = false,
        float $timeout = 3.0,
        float $readTimeout = 5.0,
        float $writeTimeout = 5.0,
        #[\SensitiveParameter] array $options = [],
    ): TcpClient {
        return self::connectTcp($peers, $timeout, $readTimeout, $writeTimeout,
            array_merge($options, compact('tlsCaFile', 'tlsServerName', 'tlsInsecureSkipVerify'), ['tls' => true]));
    }

    private const ABI_VERSION = 2;
    private const CODEC_RAW = 0;
    private const CODEC_JSON = 1;
    private const CODEC_NIF = 2;
    private const CODEC_BIF = 3;

    private static ?FFI $ffi = null;
    private ?CData $handle;

    private function __construct(CData $handle)
    {
        $this->handle = $handle;
    }

    public function __destruct()
    {
        $this->close();
    }

    public static function open(int $nodes = 8, ?string $lib = null): self
    {
        $ffi = self::ffi($lib);
        $handle = $ffi->kouten_open($nodes);
        if ($handle === null) {
            throw self::lastError();
        }
        return new self($handle);
    }

    public static function openDir(int $nodes, string $dir, ?string $lib = null): self
    {
        $ffi = self::ffi($lib);
        $handle = $ffi->kouten_open_dir($nodes, $dir);
        if ($handle === null) {
            throw self::lastError();
        }
        return new self($handle);
    }

    public static function openDirWith(
        string $dir,
        int $nodes = 8,
        bool $strongDurability = false,
        bool $diskBacked = false,
        ?string $lib = null,
    ): self {
        $ffi = self::ffi($lib);
        $handle = $ffi->kouten_open_dir_options(
            $nodes,
            $dir,
            $strongDurability ? 1 : 0,
            $diskBacked ? 1 : 0,
        );
        if ($handle === null) {
            throw self::lastError();
        }
        return new self($handle);
    }

    public static function connectAuth(
        string $peers,
        string $username = '',
        string $password = '',
        string $authToken = '',
        string $secretKey = '',
        string $galaxy = '',
        ?string $lib = null,
    ): self {
        $ffi = self::ffi($lib);
        $handle = $ffi->kouten_connect_auth($peers, $username, $password, $authToken, $secretKey, $galaxy);
        if ($handle === null) {
            throw self::lastError();
        }
        return new self($handle);
    }

    public static function connect(string $peers, ?string $lib = null): self
    {
        return self::connectAuth($peers, lib: $lib);
    }

    /**
     * Authenticated cluster connection with TLS. Enabling TLS requires a
     * KoutenDB core built with -d:ssl.
     *
     * $tlsCaFile verifies the server against a CA or self-signed certificate
     * PEM with verification left on, which is the right way to reach a server
     * with a private CA or self-signed certificate.
     *
     * $tlsInsecureSkipVerify disables certificate verification entirely. The
     * connection is then encrypted but unauthenticated and trivially
     * impersonable, so it is for local smoke tests only — never a production
     * server. Prefer $tlsCaFile for self-signed certificates. See also
     * self::connectAuthTlsInsecure().
     */
    public static function connectAuthTls(
        string $peers,
        string $username = '',
        string $password = '',
        string $authToken = '',
        string $secretKey = '',
        string $galaxy = '',
        string $tlsCaFile = '',
        string $tlsServerName = '',
        bool $tlsInsecureSkipVerify = false,
        ?string $lib = null,
    ): self {
        $ffi = self::ffi($lib);
        $handle = $ffi->kouten_connect_auth_tls(
            $peers,
            $username,
            $password,
            $authToken,
            $secretKey,
            $galaxy,
            1,
            $tlsCaFile,
            $tlsServerName,
            $tlsInsecureSkipVerify ? 1 : 0,
        );
        if ($handle === null) {
            throw self::lastError();
        }
        return new self($handle);
    }

    /**
     * TLS connection with certificate verification disabled. The name is a
     * warning: the connection is encrypted but unauthenticated. Local smoke
     * tests only — never a production server. Use connectAuthTls() with a
     * $tlsCaFile for self-signed certificates.
     */
    public static function connectAuthTlsInsecure(
        string $peers,
        string $username = '',
        string $password = '',
        string $authToken = '',
        string $secretKey = '',
        string $galaxy = '',
        ?string $lib = null,
    ): self {
        return self::connectAuthTls(
            $peers,
            $username,
            $password,
            $authToken,
            $secretKey,
            $galaxy,
            tlsInsecureSkipVerify: true,
            lib: $lib,
        );
    }

    public function close(): void
    {
        if ($this->handle !== null) {
            self::ffi()->kouten_close($this->handle);
            $this->handle = null;
        }
    }

    public function configureRing(string $ring, float $period): void
    {
        $this->check(self::ffi()->kouten_ring_configure($this->requireHandle(), $ring, $period));
    }

    public function setGalaxyDescription(string $description): void
    {
        $this->check(self::ffi()->kouten_set_galaxy_description($this->requireHandle(), $description));
    }

    public function setRingDescription(string $ring, string $description): void
    {
        $this->check(self::ffi()->kouten_set_ring_description($this->requireHandle(), $ring, $description));
    }

    public function put(string $ring, string $payload): KoutenId
    {
        $ffi = self::ffi();
        $id = $ffi->new('kouten_id');
        $this->check($ffi->kouten_put($this->requireHandle(), $ring, $payload, strlen($payload), FFI::addr($id)));
        return self::idFromC($id);
    }

    public function putCodec(string $ring, string $payload, string $codec): KoutenId
    {
        $ffi = self::ffi();
        $id = $ffi->new('kouten_id');
        $this->check($ffi->kouten_put_codec(
            $this->requireHandle(),
            $ring,
            $payload,
            strlen($payload),
            self::codecCode($codec),
            FFI::addr($id),
        ));
        return self::idFromC($id);
    }

    /** @param mixed $value */
    public function putJson(string $ring, mixed $value): KoutenId
    {
        return $this->putCodec($ring, self::encodeJson($value), 'json');
    }

    public function putNif(string $ring, string $payload): KoutenId
    {
        return $this->putCodec($ring, $payload, 'nif');
    }

    public function putBif(string $ring, string $payload): KoutenId
    {
        return $this->putCodec($ring, $payload, 'bif');
    }

    /** @param list<float|int> $vector */
    public function putVec(string $ring, string $payload, array $vector): KoutenId
    {
        $ffi = self::ffi();
        $id = $ffi->new('kouten_id');
        $vec = self::floatArray($vector);
        $vecPtr = count($vector) === 0 ? null : FFI::addr($vec[0]);
        $this->check($ffi->kouten_put_vec(
            $this->requireHandle(),
            $ring,
            $payload,
            strlen($payload),
            $vecPtr,
            count($vector),
            FFI::addr($id),
        ));
        return self::idFromC($id);
    }

    /** @param list<float|int> $vector */
    public function putVecCodec(string $ring, string $payload, array $vector, string $codec): KoutenId
    {
        $ffi = self::ffi();
        $id = $ffi->new('kouten_id');
        $vec = self::floatArray($vector);
        $vecPtr = count($vector) === 0 ? null : FFI::addr($vec[0]);
        $this->check($ffi->kouten_put_vec_codec(
            $this->requireHandle(),
            $ring,
            $payload,
            strlen($payload),
            self::codecCode($codec),
            $vecPtr,
            count($vector),
            FFI::addr($id),
        ));
        return self::idFromC($id);
    }

    /** @param mixed $value @param list<float|int> $vector */
    public function putJsonVec(string $ring, mixed $value, array $vector): KoutenId
    {
        return $this->putVecCodec($ring, self::encodeJson($value), $vector, 'json');
    }

    /** @param list<float|int> $vector */
    public function putNifVec(string $ring, string $payload, array $vector): KoutenId
    {
        return $this->putVecCodec($ring, $payload, $vector, 'nif');
    }

    /** @param list<float|int> $vector */
    public function putBifVec(string $ring, string $payload, array $vector): KoutenId
    {
        return $this->putVecCodec($ring, $payload, $vector, 'bif');
    }

    public function get(KoutenId $id): ?string
    {
        $ffi = self::ffi();
        $len = $ffi->new('size_t[1]');
        $ptr = $ffi->kouten_get($this->requireHandle(), self::idToC($id), FFI::addr($len[0]));
        if ($ptr === null) {
            $message = self::lastError()->getMessage();
            if (str_contains($message, 'not found')) {
                return null;
            }
            throw new RuntimeException($message);
        }
        try {
            return FFI::string($ptr, (int)$len[0]);
        } finally {
            $ffi->kouten_free($ptr);
        }
    }

    /** @return mixed|null */
    public function getJson(KoutenId $id): mixed
    {
        $value = $this->get($id);
        return $value === null ? null : self::decodeJson($value);
    }

    public function getEncoded(KoutenId $id): ?EncodedPayload
    {
        $ffi = self::ffi();
        $len = $ffi->new('size_t[1]');
        $codec = $ffi->new('int[1]');
        $ptr = $ffi->kouten_get_codec($this->requireHandle(), self::idToC($id), FFI::addr($len[0]), FFI::addr($codec[0]));
        if ($ptr === null) {
            $message = self::lastError()->getMessage();
            if (str_contains($message, 'not found')) {
                return null;
            }
            throw new RuntimeException($message);
        }
        try {
            return new EncodedPayload(FFI::string($ptr, (int)$len[0]), self::codecName((int)$codec[0]));
        } finally {
            $ffi->kouten_free($ptr);
        }
    }

    public function exists(KoutenId $id): bool
    {
        $result = self::ffi()->kouten_exists($this->requireHandle(), self::idToC($id));
        if ($result < 0) {
            throw self::lastError();
        }
        return $result === 1;
    }

    public function update(KoutenId $id, string $payload): void
    {
        $this->check(self::ffi()->kouten_update(
            $this->requireHandle(),
            self::idToC($id),
            $payload,
            strlen($payload),
        ));
    }

    public function updateCodec(KoutenId $id, string $payload, string $codec): void
    {
        $this->check(self::ffi()->kouten_update_codec(
            $this->requireHandle(),
            self::idToC($id),
            $payload,
            strlen($payload),
            self::codecCode($codec),
        ));
    }

    /** @param mixed $value */
    public function updateJson(KoutenId $id, mixed $value): void
    {
        $this->updateCodec($id, self::encodeJson($value), 'json');
    }

    public function remove(KoutenId $id): void
    {
        $this->check(self::ffi()->kouten_remove($this->requireHandle(), self::idToC($id)));
    }

    /** @param list<KoutenId> $ids @return list<string|null> */
    public function batchGet(array $ids): array
    {
        $ffi = self::ffi();
        $arr = $ffi->new('kouten_id[' . max(1, count($ids)) . ']');
        foreach ($ids as $i => $id) {
            $arr[$i] = self::idToC($id);
        }
        $res = $ffi->kouten_batch_get($this->requireHandle(), count($ids) === 0 ? null : FFI::addr($arr[0]), count($ids));
        if ($res === null) {
            throw self::lastError();
        }
        try {
            $out = [];
            for ($i = 0; $i < (int)$res->len; $i++) {
                $value = $res->values[$i];
                $out[] = $value->data === null ? null : FFI::string($value->data, (int)$value->len);
            }
            return $out;
        } finally {
            $ffi->kouten_batch_get_free($res);
        }
    }

    public function query(KoutenId $id, string $selection): string
    {
        $ffi = self::ffi();
        $len = $ffi->new('size_t[1]');
        $ptr = $ffi->kouten_query($this->requireHandle(), self::idToC($id), $selection, FFI::addr($len[0]));
        if ($ptr === null) {
            throw self::lastError();
        }
        try {
            return FFI::string($ptr, (int)$len[0]);
        } finally {
            $ffi->kouten_free($ptr);
        }
    }

    /** @return mixed */
    public function queryJson(KoutenId $id, string $selection): mixed
    {
        return self::decodeJson($this->query($id, $selection));
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function readRing(string $ring, array $options = []): array
    {
        if (array_key_exists('sort', $options) && array_key_exists('rsort', $options)) {
            throw new KoutenDBException('readRing options cannot set both sort and rsort');
        }
        $ffi = self::ffi();
        $len = $ffi->new('size_t[1]');
        $filterJson = $options['filterJson'] ?? self::encodeFilterJson($options['filter'] ?? []);
        $selection = (string)($options['selection'] ?? '');
        $sortField = (string)($options['sort'] ?? $options['rsort'] ?? '');
        $sortDesc = array_key_exists('sort', $options) ? 0 : 1;
        $ptr = $ffi->kouten_read_ring_json(
            $this->requireHandle(),
            $ring,
            (string)$filterJson,
            $selection,
            (int)($options['limit'] ?? 100),
            (string)($options['cursor'] ?? ''),
            !empty($options['pagination']) ? 1 : 0,
            (int)($options['page'] ?? 1),
            (int)($options['pageLimit'] ?? 20),
            $sortField,
            $sortDesc,
            FFI::addr($len[0]),
        );
        if ($ptr === null) {
            throw self::lastError();
        }
        try {
            return self::decodeJson(FFI::string($ptr, (int)$len[0]));
        } finally {
            $ffi->kouten_free($ptr);
        }
    }

    /** @param list<float|int> $vector */
    public function retrieve(array $vector, string $ring = '', int $budget = 8, int $topRings = 0, int $focus = 0): RetrieveResult
    {
        $ffi = self::ffi();
        $vec = self::floatArray($vector);
        $res = $ffi->kouten_retrieve(
            $this->requireHandle(),
            count($vector) === 0 ? null : FFI::addr($vec[0]),
            count($vector),
            $ring,
            $budget,
            $topRings,
            $focus,
        );
        if ($res === null) {
            throw self::lastError();
        }
        try {
            $hits = [];
            for ($i = 0; $i < (int)$res->len; $i++) {
                $hit = $res->hits[$i];
                $hits[] = new KoutenHit(
                    self::idFromC($hit->id),
                    (float)$hit->score,
                    $hit->payload === null ? '' : FFI::string($hit->payload, (int)$hit->payload_len),
                );
            }
            return new RetrieveResult($hits, [
                'totalVectors' => (int)$res->total_vectors,
                'scanned' => (int)$res->scanned,
                'skippedVectors' => (int)$res->skipped_vectors,
                'returned' => (int)$res->returned,
                'ringsTouched' => (int)$res->rings_touched,
                'payloadBytes' => (int)$res->payload_bytes,
                'estimatedTokens' => (int)$res->estimated_tokens,
                'fanoutNodes' => (int)$res->fanout_nodes,
                'candidateReduction' => (float)$res->candidate_reduction,
            ]);
        } finally {
            $ffi->kouten_retrieve_free($res);
        }
    }

    /** @param list<float|int> $queryVector */
    public function atlas(array $queryVector = [], int $maxCentroidDims = 8): string
    {
        $ffi = self::ffi();
        $vec = self::floatArray($queryVector);
        $len = $ffi->new('size_t[1]');
        $ptr = $ffi->kouten_atlas(
            $this->requireHandle(),
            count($queryVector) === 0 ? null : FFI::addr($vec[0]),
            count($queryVector),
            $maxCentroidDims,
            FFI::addr($len[0]),
        );
        if ($ptr === null) {
            throw self::lastError();
        }
        try {
            return FFI::string($ptr, (int)$len[0]);
        } finally {
            $ffi->kouten_free($ptr);
        }
    }

    public function metrics(string $format = 'key-value'): string
    {
        $ffi = self::ffi();
        $len = $ffi->new('size_t[1]');
        $ptr = $ffi->kouten_metrics_text(
            $this->requireHandle(),
            self::metricsFormatCode($format),
            FFI::addr($len[0]),
        );
        return self::readOwnedText($ptr, $len);
    }

    /** @return array<string,mixed> */
    public function segmentStatus(float $staleRatio = 0.25, int $minStaleRecords = 256): array
    {
        $ffi = self::ffi();
        $len = $ffi->new('size_t[1]');
        $ptr = $ffi->kouten_segment_status_json(
            $this->requireHandle(), $staleRatio, $minStaleRecords, FFI::addr($len[0]),
        );
        return self::readOwnedJson($ptr, $len);
    }

    /** @param array<string,int|float> $policy @return array<string,mixed> */
    public function planSegmentMaintenance(array $policy = []): array
    {
        return $this->segmentMaintenance(false, $policy);
    }

    /** @param array<string,int|float> $policy @return array<string,mixed> */
    public function runSegmentMaintenance(array $policy = []): array
    {
        return $this->segmentMaintenance(true, $policy);
    }

    /** @return array<string,mixed> */
    public function segmentMaintenanceStatus(): array
    {
        $ffi = self::ffi();
        $len = $ffi->new('size_t[1]');
        return self::readOwnedJson(
            $ffi->kouten_segment_maintenance_status_json($this->requireHandle(), FFI::addr($len[0])),
            $len,
        );
    }

    public function recoverSegmentMaintenance(): bool
    {
        $ffi = self::ffi();
        $recovered = $ffi->new('int[1]');
        $this->check($ffi->kouten_segment_maintenance_recover(
            $this->requireHandle(), FFI::addr($recovered[0]),
        ));
        return (int)$recovered[0] !== 0;
    }

    /** @return array<string,mixed> */
    public function createCheckpoint(?string $root = null, ?string $checkpointId = null): array
    {
        $ffi = self::ffi();
        $len = $ffi->new('size_t[1]');
        return self::readOwnedJson($ffi->kouten_checkpoint_create_json(
            $this->requireHandle(), $root, $checkpointId, FFI::addr($len[0]),
        ), $len);
    }

    /** @return array<string,mixed> */
    public static function checkpointStatus(string $checkpointDir, ?string $lib = null): array
    {
        $ffi = self::ffi($lib);
        $len = $ffi->new('size_t[1]');
        return self::readOwnedJson(
            $ffi->kouten_checkpoint_status_json($checkpointDir, FFI::addr($len[0])), $len,
        );
    }

    /** @return array<string,mixed> */
    public static function listCheckpoints(string $root, ?string $lib = null): array
    {
        $ffi = self::ffi($lib);
        $len = $ffi->new('size_t[1]');
        return self::readOwnedJson($ffi->kouten_checkpoint_list_json($root, FFI::addr($len[0])), $len);
    }

    /** @return array<string,mixed> */
    public static function cleanupCheckpoints(string $root, int $keep, ?string $lib = null): array
    {
        $ffi = self::ffi($lib);
        $len = $ffi->new('size_t[1]');
        return self::readOwnedJson(
            $ffi->kouten_checkpoint_cleanup_json($root, $keep, FFI::addr($len[0])), $len,
        );
    }

    /** @return array<string,mixed> */
    public static function restoreCheckpoint(
        string $checkpointDir,
        string $dataDir,
        bool $overwrite = false,
        ?string $lib = null,
    ): array {
        $ffi = self::ffi($lib);
        $len = $ffi->new('size_t[1]');
        return self::readOwnedJson($ffi->kouten_checkpoint_restore_json(
            $checkpointDir, $dataDir, $overwrite ? 1 : 0, FFI::addr($len[0]),
        ), $len);
    }

    public static function checkpointMetrics(
        string $root,
        string $format = 'key-value',
        ?string $lib = null,
    ): string {
        $ffi = self::ffi($lib);
        $len = $ffi->new('size_t[1]');
        return self::readOwnedText(
            $ffi->kouten_checkpoint_metrics_text(
                $root, self::metricsFormatCode($format), FFI::addr($len[0]),
            ),
            $len,
        );
    }

    public function now(): float
    {
        return (float)self::ffi()->kouten_now($this->requireHandle());
    }

    public function advance(float $dt): void
    {
        self::ffi()->kouten_advance($this->requireHandle(), $dt);
    }

    public function locate(KoutenId $id, float $at = -1.0): int
    {
        $node = self::ffi()->kouten_locate($this->requireHandle(), self::idToC($id), $at);
        if ($node < 0) {
            throw self::lastError();
        }
        return (int)$node;
    }

    public function nextVisit(KoutenId $id, int $node): float
    {
        $time = self::ffi()->kouten_next_visit($this->requireHandle(), self::idToC($id), $node);
        if ($time < 0) {
            throw self::lastError();
        }
        return (float)$time;
    }

    public function nextJoin(KoutenId $a, KoutenId $b): ?float
    {
        $time = self::ffi()->kouten_next_join($this->requireHandle(), self::idToC($a), self::idToC($b));
        return $time < 0 ? null : (float)$time;
    }

    /** @param array<string,int|float> $policy @return array<string,mixed> */
    private function segmentMaintenance(bool $run, array $policy): array
    {
        $ffi = self::ffi();
        $len = $ffi->new('size_t[1]');
        $arguments = [
            $this->requireHandle(),
            (float)($policy['staleRatio'] ?? 0.25),
            (int)($policy['minStaleRecords'] ?? 256),
            (int)($policy['maxRings'] ?? 0),
            (int)($policy['maxBytes'] ?? 0),
            (int)($policy['maxElapsedMs'] ?? 0),
            FFI::addr($len[0]),
        ];
        $ptr = $run
            ? $ffi->kouten_segment_maintenance_run_json(...$arguments)
            : $ffi->kouten_segment_maintenance_plan_json(...$arguments);
        return self::readOwnedJson($ptr, $len);
    }

    private static function readOwnedText(?CData $ptr, CData $len): string
    {
        if ($ptr === null) {
            throw self::lastError();
        }
        try {
            return FFI::string($ptr, (int)$len[0]);
        } finally {
            self::ffi()->kouten_free($ptr);
        }
    }

    /** @return array<string,mixed> */
    private static function readOwnedJson(?CData $ptr, CData $len): array
    {
        $decoded = self::decodeJson(self::readOwnedText($ptr, $len));
        if (!is_array($decoded)) {
            throw new KoutenDBException('KoutenDB operation returned non-object JSON');
        }
        return $decoded;
    }

    private function check(int $code): void
    {
        if ($code !== 0) {
            throw self::lastError();
        }
    }

    private function requireHandle(): CData
    {
        if ($this->handle === null) {
            throw new RuntimeException('KoutenDB handle is closed');
        }
        return $this->handle;
    }

    /** @param list<float|int> $values */
    private static function floatArray(array $values): CData
    {
        $ffi = self::ffi();
        $arr = $ffi->new('float[' . max(1, count($values)) . ']');
        foreach ($values as $i => $value) {
            $arr[$i] = (float)$value;
        }
        return $arr;
    }

    private static function idToC(KoutenId $id): CData
    {
        $c = self::ffi()->new('kouten_id');
        $c->parent = $id->parent;
        $c->epoch = $id->epoch;
        $c->seq = $id->seq;
        $c->t_write = $id->tWrite;
        return $c;
    }

    private static function idFromC(CData $id): KoutenId
    {
        return new KoutenId((string)$id->parent, (int)$id->epoch, (int)$id->seq, (float)$id->t_write);
    }

    private static function lastError(): RuntimeException
    {
        $ptr = self::ffi()->kouten_last_error();
        if ($ptr === null) {
            $message = 'KoutenDB C ABI error';
        } elseif (is_string($ptr)) {
            $message = $ptr;
        } else {
            $message = FFI::string($ptr);
        }
        return new KoutenDBException($message === '' ? 'KoutenDB C ABI error' : $message);
    }

    /** @param mixed $value */
    private static function encodeJson(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            throw new KoutenDBException('failed to encode JSON: ' . $e->getMessage(), previous: $e);
        }
    }

    /** @return mixed */
    private static function decodeJson(string $value): mixed
    {
        try {
            return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new KoutenDBException('failed to decode JSON: ' . $e->getMessage(), previous: $e);
        }
    }

    /** @param mixed $value */
    private static function encodeFilterJson(mixed $value): string
    {
        if (is_array($value) && count($value) === 0) {
            return '{}';
        }
        return self::encodeJson($value);
    }

    private static function codecCode(string $codec): int
    {
        return match ($codec) {
            'raw' => self::CODEC_RAW,
            'json' => self::CODEC_JSON,
            'nif' => self::CODEC_NIF,
            'bif' => self::CODEC_BIF,
            default => throw new KoutenDBException("unsupported payload codec: {$codec}"),
        };
    }

    private static function codecName(int $codec): string
    {
        return match ($codec) {
            self::CODEC_RAW => 'raw',
            self::CODEC_JSON => 'json',
            self::CODEC_NIF => 'nif',
            self::CODEC_BIF => 'bif',
            default => 'unknown',
        };
    }

    private static function metricsFormatCode(string $format): int
    {
        return match ($format) {
            'key-value' => 0,
            'prometheus' => 1,
            'openmetrics' => 2,
            default => throw new KoutenDBException("unsupported metrics format: {$format}"),
        };
    }

    private static function ffi(?string $lib = null): FFI
    {
        if (!class_exists(FFI::class)) {
            throw new RuntimeException('PHP FFI extension is not enabled');
        }
        if (self::$ffi === null) {
            $lib = self::resolveLibraryPath($lib);
            self::$ffi = FFI::cdef(self::CDEF, $lib);
            self::$ffi->kouten_init();
            if ((int)self::$ffi->kouten_abi_version() !== self::ABI_VERSION) {
                throw new RuntimeException('KoutenDB ABI version mismatch');
            }
        }
        return self::$ffi;
    }

    private static function resolveLibraryPath(?string $lib): string
    {
        if ($lib !== null && $lib !== '') {
            return $lib;
        }

        $envLib = getenv('KOUTENDB_LIB_PATH');
        if (is_string($envLib) && $envLib !== '') {
            return $envLib;
        }

        $envCore = getenv('KOUTENDB_CORE_DIR');
        if (is_string($envCore) && $envCore !== '') {
            return rtrim($envCore, DIRECTORY_SEPARATOR) . '/lib/libkoutendb.so';
        }

        $candidates = [
            __DIR__ . '/../../koutendb/lib/libkoutendb.so',
            __DIR__ . '/../../ceresdb/lib/libkoutendb.so',
            __DIR__ . '/../lib/libkoutendb.so',
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return 'libkoutendb.so';
    }

    private const CDEF = <<<'CDEF'
typedef unsigned long size_t;
typedef unsigned long uint64_t;
typedef unsigned int uint32_t;
typedef long long int64_t;

typedef struct kouten_id {
  uint64_t parent;
  uint32_t epoch;
  uint32_t seq;
  double   t_write;
} kouten_id;

typedef struct kouten_hit {
  kouten_id id;
  double   score;
  void    *payload;
  size_t   payload_len;
} kouten_hit;

typedef struct kouten_retrieve_result {
  size_t     len;
  kouten_hit *hits;
  int        total_vectors;
  int        scanned;
  int        skipped_vectors;
  int        returned;
  int        rings_touched;
  int        payload_bytes;
  int        estimated_tokens;
  int        fanout_nodes;
  double     candidate_reduction;
} kouten_retrieve_result;

typedef struct kouten_value {
  void  *data;
  size_t len;
} kouten_value;

typedef struct kouten_batch_result {
  size_t       len;
  kouten_value *values;
} kouten_batch_result;

int         kouten_abi_version(void);
const char *kouten_last_error(void);
void        kouten_init(void);
void       *kouten_open(int nodes);
void       *kouten_open_dir(int nodes, const char *dir);
void       *kouten_open_dir_options(int nodes, const char *dir, int durability_strong, int disk_backed);
void       *kouten_connect_auth(const char *peers, const char *username, const char *password, const char *auth_token, const char *secret_key, const char *galaxy);
void       *kouten_connect_auth_tls(const char *peers, const char *username, const char *password, const char *auth_token, const char *secret_key, const char *galaxy, int tls, const char *tls_ca_file, const char *tls_server_name, int tls_insecure_skip_verify);
void        kouten_close(void *db);
void       *kouten_metrics_text(void *db, int format, size_t *out_len);
void       *kouten_checkpoint_metrics_text(const char *root, int format, size_t *out_len);
double      kouten_now(void *db);
void        kouten_advance(void *db, double dt);
int         kouten_ring_configure(void *db, const char *ring, double period);
int         kouten_set_galaxy_description(void *db, const char *description);
int         kouten_set_ring_description(void *db, const char *ring, const char *description);
int         kouten_put(void *db, const char *ring, const void *data, size_t len, kouten_id *out_id);
int         kouten_put_codec(void *db, const char *ring, const void *data, size_t len, int codec, kouten_id *out_id);
int         kouten_put_vec(void *db, const char *ring, const void *data, size_t len, const float *vec, size_t vec_len, kouten_id *out_id);
int         kouten_put_vec_codec(void *db, const char *ring, const void *data, size_t len, int codec, const float *vec, size_t vec_len, kouten_id *out_id);
void       *kouten_get(void *db, kouten_id id, size_t *out_len);
void       *kouten_get_codec(void *db, kouten_id id, size_t *out_len, int *out_codec);
int         kouten_exists(void *db, kouten_id id);
int         kouten_update(void *db, kouten_id id, const void *data, size_t len);
int         kouten_update_codec(void *db, kouten_id id, const void *data, size_t len, int codec);
int         kouten_remove(void *db, kouten_id id);
void        kouten_free(void *p);
kouten_batch_result *kouten_batch_get(void *db, const kouten_id *ids, size_t ids_len);
void        kouten_batch_get_free(kouten_batch_result *r);
void       *kouten_query(void *db, kouten_id id, const char *selection, size_t *out_len);
void       *kouten_read_ring_json(void *db, const char *ring, const char *filter_json, const char *selection, int limit, const char *cursor, int pagination, int page, int page_limit, const char *sort_field, int sort_desc, size_t *out_len);
kouten_retrieve_result *kouten_retrieve(void *db, const float *vec, size_t vec_len, const char *ring, int budget, int top_rings, int focus);
void        kouten_retrieve_free(kouten_retrieve_result *r);
void       *kouten_atlas(void *db, const float *query_vec, size_t query_vec_len, int max_centroid_dims, size_t *out_len);
void       *kouten_segment_status_json(void *db, double stale_ratio, int min_stale_records, size_t *out_len);
void       *kouten_segment_maintenance_plan_json(void *db, double stale_ratio, int min_stale_records, int max_rings, int64_t max_bytes, int64_t max_elapsed_ms, size_t *out_len);
void       *kouten_segment_maintenance_run_json(void *db, double stale_ratio, int min_stale_records, int max_rings, int64_t max_bytes, int64_t max_elapsed_ms, size_t *out_len);
void       *kouten_segment_maintenance_status_json(void *db, size_t *out_len);
int         kouten_segment_maintenance_recover(void *db, int *out_recovered);
void       *kouten_checkpoint_create_json(void *db, const char *root, const char *checkpoint_id, size_t *out_len);
void       *kouten_checkpoint_status_json(const char *checkpoint_dir, size_t *out_len);
void       *kouten_checkpoint_list_json(const char *root, size_t *out_len);
void       *kouten_checkpoint_cleanup_json(const char *root, int keep, size_t *out_len);
void       *kouten_checkpoint_restore_json(const char *checkpoint_dir, const char *data_dir, int overwrite, size_t *out_len);
int         kouten_locate(void *db, kouten_id id, double at);
double      kouten_next_visit(void *db, kouten_id id, int node);
double      kouten_next_join(void *db, kouten_id a, kouten_id b);
CDEF;
}
