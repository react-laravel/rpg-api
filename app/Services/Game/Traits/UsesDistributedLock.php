<?php

namespace App\Services\Game\Traits;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/**
 * Trait for distributed lock operations to prevent race conditions
 */
trait UsesDistributedLock
{
    /** Default lock timeout in seconds */
    protected const DEFAULT_LOCK_TIMEOUT_SECONDS = 10;

    /**
     * Execute a callback with a distributed lock
     *
     * @template T
     *
     * @param  string  $lockKey  The unique lock key
     * @param  callable(): T  $callback  The operation to perform while holding the lock
     * @param  int  $timeoutSeconds  Lock timeout in seconds
     * @return T
     *
     * @throws \RuntimeException If lock cannot be acquired
     */
    protected function executeWithDistributedLock(
        string $lockKey,
        callable $callback,
        int $timeoutSeconds = self::DEFAULT_LOCK_TIMEOUT_SECONDS
    ): mixed {
        $lock = Cache::lock($lockKey, $timeoutSeconds);

        if (! $lock->get()) {
            throw new \RuntimeException('操作正在进行中，请稍后重试');
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * Execute a callback with distributed lock and Redis-based idempotency
     *
     * @template T
     *
     * @param  int  $characterId  Character ID for the idempotency key
     * @param  string  $operation  Operation name (e.g., 'buy', 'sell')
     * @param  string  $idempotencyKey  Unique key for this request
     * @param  callable(): T  $callback  The operation to perform
     * @param  int  $lockTimeoutSeconds  Lock timeout in seconds
     * @param  int  $idempotencyTtlSeconds  TTL for idempotency cache
     * @return T
     *
     * @throws \RuntimeException If lock cannot be acquired or request is already processing
     */
    protected function executeWithIdempotency(
        int $characterId,
        string $operation,
        string $idempotencyKey,
        callable $callback,
        int $lockTimeoutSeconds = self::DEFAULT_LOCK_TIMEOUT_SECONDS,
        int $idempotencyTtlSeconds = 86400
    ): mixed {
        $idempotencyCacheKey = $this->getIdempotencyCacheKey($characterId, $operation, $idempotencyKey);

        // Use SET NX atomic operation - if key doesn't exist, set it and return true (got lock)
        $acquired = Redis::set($idempotencyCacheKey, 'processing', 'EX', $idempotencyTtlSeconds, 'NX');

        if (! $acquired) {
            // Check if there's a cached result (completed request)
            $cachedResult = $this->getCachedIdempotencyResult($characterId, $operation, $idempotencyKey);
            if ($cachedResult !== null) {
                return $cachedResult;
            }

            // Request is being processed
            throw new \RuntimeException('请求正在处理中，请稍后重试');
        }

        try {
            // Double-check idempotency cache (race condition protection)
            $cachedResult = $this->getCachedIdempotencyResult($characterId, $operation, $idempotencyKey);
            if ($cachedResult !== null) {
                return $cachedResult;
            }

            $result = $callback();

            // Cache the result for idempotency
            $this->cacheIdempotencyResult($characterId, $operation, $idempotencyKey, $result, $idempotencyTtlSeconds);

            return $result;
        } finally {
            // Clean up processing marker
            Redis::del($idempotencyCacheKey);
        }
    }

    /**
     * Get idempotency cache key
     */
    protected function getIdempotencyCacheKey(int $characterId, string $operation, string $idempotencyKey): string
    {
        return 'shop:idem:' . $characterId . ':' . $operation . ':' . $idempotencyKey;
    }

    /**
     * Get cached idempotency result
     *
     * @return array<string, mixed>|null
     */
    protected function getCachedIdempotencyResult(int $characterId, string $operation, string $idempotencyKey): ?array
    {
        $cacheKey = $this->getIdempotencyCacheKey($characterId, $operation, $idempotencyKey);
        $cached = Cache::get($cacheKey);

        if (! is_array($cached)) {
            return null;
        }

        return $cached;
    }

    /**
     * Cache idempotency result
     *
     * @param  array<string, mixed>  $result
     */
    protected function cacheIdempotencyResult(
        int $characterId,
        string $operation,
        string $idempotencyKey,
        array $result,
        int $ttlSeconds = 86400
    ): void {
        $cacheKey = $this->getIdempotencyCacheKey($characterId, $operation, $idempotencyKey);
        Cache::put($cacheKey, $result, $ttlSeconds);
    }
}
