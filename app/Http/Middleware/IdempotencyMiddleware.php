<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency middleware to prevent duplicate form submissions.
 *
 * Clients should send a unique X-Idempotency-Key header with POST/PUT/PATCH/DELETE requests.
 * If the same key is received again within 24 hours, the cached response is returned
 * instead of re-processing the request.
 *
 * Idempotency keys are stored in Redis with a 24-hour TTL.
 */
class IdempotencyMiddleware
{
    private const IDEMPOTENCY_HEADER = 'X-Idempotency-Key';

    private const IDEMPOTENCY_PREFIX = 'idempotency:';

    private const IDEMPOTENCY_TTL = 86400; // 24 hours in seconds

    /**
     * HTTP methods considered idempotent (can be safely cached).
     */
    private const CACHEABLE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only cache unsafe methods
        if (! in_array($request->method(), self::CACHEABLE_METHODS, true)) {
            return $next($request);
        }

        $idempotencyKey = $request->header(self::IDEMPOTENCY_HEADER);

        // If no idempotency key provided, pass through (idempotency is optional)
        if (empty($idempotencyKey)) {
            return $next($request);
        }

        // Validate key format (max 128 chars, alphanumeric + dash/underscore)
        if (! $this->isValidKey($idempotencyKey)) {
            return $this->invalidKeyResponse();
        }

        $cacheKey = $this->buildCacheKey($request, $idempotencyKey);

        // Check if this request was already processed
        $cached = $this->getCachedResponse($cacheKey);

        if ($cached !== null) {
            return $this->buildCachedResponse($cached);
        }

        // Process the request and cache the response
        /** @var Response $response */
        $response = $next($request);

        // Only cache successful responses (2xx)
        if ($response->isSuccessful() || $response->isRedirect()) {
            $this->cacheResponse($cacheKey, $response);
        }

        return $response;
    }

    /**
     * Check if an idempotency key is valid.
     */
    private function isValidKey(string $key): bool
    {
        if (strlen($key) > 128) {
            return false;
        }

        return preg_match('/^[a-zA-Z0-9_-]+$/', $key) === 1;
    }

    /**
     * Build the Redis cache key for an idempotency key.
     */
    private function buildCacheKey(Request $request, string $idempotencyKey): string
    {
        // 用户身份必须参与缓存键，避免不同账号复用相同客户端 key 时互相读取响应。
        $userId = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');
        $requestIdentifier = hash('sha256', $userId.':'.$request->path().':'.$request->method());

        return self::IDEMPOTENCY_PREFIX.$requestIdentifier.':'.$idempotencyKey;
    }

    /**
     * Get a cached response for an idempotency key.
     */
    private function getCachedResponse(string $cacheKey): ?array
    {
        /** @var Connection $redis */
        $redis = Redis::connection();

        $cached = $redis->get($cacheKey);

        if ($cached === null || $cached === false) {
            return null;
        }

        $decoded = json_decode($cached, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Cache a response for an idempotency key.
     */
    private function cacheResponse(string $cacheKey, Response $response): void
    {
        /** @var Connection $redis */
        $redis = Redis::connection();

        $payload = json_encode([
            'status' => $response->getStatusCode(),
            'headers' => $response->headers->all(),
            'content' => $response->getContent(),
        ]);

        $redis->setex($cacheKey, self::IDEMPOTENCY_TTL, $payload);
    }

    /**
     * Build a Response from cached data.
     */
    private function buildCachedResponse(array $cached): Response
    {
        $response = new Response(
            $cached['content'] ?? '',
            $cached['status'] ?? 200,
            $cached['headers'] ?? []
        );

        // Mark as a cached (idempotent) response
        $response->headers->set('X-Idempotent-Replay', 'true');

        return $response;
    }

    /**
     * Return an error response for invalid idempotency keys.
     */
    private function invalidKeyResponse(): Response
    {
        return response()->json([
            'success' => false,
            'message' => 'Invalid X-Idempotency-Key: must be 1-128 alphanumeric characters, dashes, or underscores',
        ], 400);
    }
}
