<?php

declare(strict_types=1);

namespace App\Infrastructure\ErrorInjection;

use Predis\Client;

final class RedisErrorFlagStore implements ErrorFlagStoreInterface
{
    private const TTL = 300; // 5 minutos

    public function __construct(private readonly Client $redis) {}

    public function setFlag(string $correlationId, string $errorType, array $config = []): void
    {
        $key = $this->flagKey($correlationId);
        $data = json_encode(['type' => $errorType, 'config' => $config, 'correlation_id' => $correlationId]);
        $this->redis->setex($key, self::TTL, $data);
    }

    public function getFlag(string $correlationId): ?ErrorFlag
    {
        $data = $this->redis->get($this->flagKey($correlationId));
        if ($data === null) {
            return null;
        }
        $decoded = json_decode($data, true);
        return new ErrorFlag(
            type: $decoded['type'],
            correlationId: $decoded['correlation_id'],
            config: $decoded['config'] ?? [],
        );
    }

    public function clearFlag(string $correlationId): void
    {
        $this->redis->del($this->flagKey($correlationId));
        $this->redis->del($this->counterKey($correlationId));
    }

    public function incrementFailureCount(string $correlationId): int
    {
        $key = $this->counterKey($correlationId);
        $count = $this->redis->incr($key);
        $this->redis->expire($key, self::TTL);
        return $count;
    }

    public function getFailureCount(string $correlationId): int
    {
        return (int) ($this->redis->get($this->counterKey($correlationId)) ?: 0);
    }

    private function flagKey(string $correlationId): string
    {
        return "es_lab:error_flag:{$correlationId}";
    }

    private function counterKey(string $correlationId): string
    {
        return "es_lab:failure_count:{$correlationId}";
    }
}
