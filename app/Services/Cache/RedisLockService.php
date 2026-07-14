<?php

namespace App\Services\Cache;

use App\Services\BaseService;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Redis distributed lock service for critical section protection.
 *
 * Uses the Redis SETNX (SET if Not eXists) pattern with TTL for safe
 * lock acquisition and release, preventing deadlocks.
 */
class RedisLockService extends BaseService
{
    private const DEFAULT_LOCK_TTL = 10; // seconds

    private const LOCK_PREFIX = 'lock:';

    /**
     * Attempt to acquire a distributed lock.
     *
     * @param  string  $key  The lock key (without prefix)
     * @param  int  $ttlSeconds  Lock TTL in seconds (auto-release)
     * @return string|false Lock token on success, false on failure
     */
    public function lock(string $key, int $ttlSeconds = self::DEFAULT_LOCK_TTL): string|false
    {
        $lockKey = self::LOCK_PREFIX . $key;
        $token = Str::random(32);

        /** @var \Illuminate\Redis\Connections\Connection $redis */
        $redis = Redis::connection();

        // SETNX pattern: only set if key doesn't exist
        $acquired = $redis->setnx($lockKey, $token);

        if (! $acquired) {
            return false;
        }

        // Set TTL to prevent deadlocks if release() is never called
        $redis->expire($lockKey, $ttlSeconds);

        return $token;
    }

    /**
     * Release a distributed lock.
     *
     * Only releases if the token matches (prevents releasing another process's lock).
     *
     * @param  string  $key  The lock key (without prefix)
     * @param  string  $token  The token returned from lock()
     * @return bool True if released, false otherwise
     */
    public function release(string $key, string $token): bool
    {
        $lockKey = self::LOCK_PREFIX . $key;

        /** @var \Illuminate\Redis\Connections\Connection $redis */
        $redis = Redis::connection();

        $currentToken = $redis->get($lockKey);

        // Only release if we own the lock
        if ($currentToken === $token) {
            $redis->del($lockKey);

            return true;
        }

        return false;
    }

    /**
     * Extend an existing lock's TTL.
     *
     * @param  string  $key  The lock key (without prefix)
     * @param  string  $token  The token returned from lock()
     * @param  int  $ttlSeconds  New TTL in seconds
     * @return bool True if extended, false otherwise
     */
    public function extend(string $key, string $token, int $ttlSeconds = self::DEFAULT_LOCK_TTL): bool
    {
        $lockKey = self::LOCK_PREFIX . $key;

        /** @var \Illuminate\Redis\Connections\Connection $redis */
        $redis = Redis::connection();

        $currentToken = $redis->get($lockKey);

        if ($currentToken !== $token) {
            return false;
        }

        return (bool) $redis->expire($lockKey, $ttlSeconds);
    }

    /**
     * Check if a lock is currently held.
     *
     * @param  string  $key  The lock key (without prefix)
     */
    public function isLocked(string $key): bool
    {
        $lockKey = self::LOCK_PREFIX . $key;

        /** @var \Illuminate\Redis\Connections\Connection $redis */
        $redis = Redis::connection();

        return $redis->exists($lockKey) > 0;
    }

    /**
     * Wait and acquire a lock with retries.
     *
     * @param  string  $key  The lock key (without prefix)
     * @param  int  $ttlSeconds  Lock TTL in seconds
     * @param  int  $maxRetries  Maximum number of retries
     * @param  int  $retryDelayMs  Delay between retries in milliseconds
     * @return string|false Lock token on success, false on failure
     */
    public function waitAndLock(
        string $key,
        int $ttlSeconds = self::DEFAULT_LOCK_TTL,
        int $maxRetries = 3,
        int $retryDelayMs = 100
    ): string|false {
        for ($i = 0; $i <= $maxRetries; $i++) {
            $token = $this->lock($key, $ttlSeconds);

            if ($token !== false) {
                return $token;
            }

            if ($i < $maxRetries) {
                usleep($retryDelayMs * 1000);
            }
        }

        return false;
    }
}
