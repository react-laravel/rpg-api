<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class CombatRateLimit
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $characterId = $request->input('character_id') ?? $request->query('character_id');

        if (! $characterId || ! $request->user()) {
            return $next($request);
        }

        // 为每个角色创建独立的速率限制器
        // 每 4 秒最多 1 次请求
        $key = 'combat:' . $request->user()->id . ':' . $characterId;

        // 检查是否在 4 秒窗口期内已经有过请求
        if (RateLimiter::tooManyAttempts($key, 1)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'message' => '战斗请求过于频繁，请稍后再试',
                'retry_after' => $seconds,
            ], 429);
        }

        // 记录这次请求，设置 4 秒衰减时间
        RateLimiter::hit($key, 4);

        return $next($request);
    }
}
