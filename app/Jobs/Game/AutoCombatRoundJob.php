<?php

namespace App\Jobs\Game;

use App\Models\Game\GameCharacter;
use App\Models\Game\GameMapDefinition;
use App\Services\Game\GameCombatBroadcaster;
use App\Services\Game\GameCombatService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class AutoCombatRoundJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 35;

    private const REDIS_KEY_PREFIX = 'rpg:combat:auto:';

    private const AUTO_COMBAT_TTL = 900;

    private const LOCK_TIMEOUT = 35;

    private const ROUND_INTERVAL_SECONDS = 3;

    private const NEXT_ROUND_AT_KEY = 'next_round_at';

    public function __construct(
        public int $characterId,
        public ?array $skillIds = null
    ) {
        $this->onQueue('rpg-combat');
    }

    public function handle(GameCombatService $combatService): void
    {
        $key = self::REDIS_KEY_PREFIX.$this->characterId;
        $payload = Redis::get($key);

        if (! self::hasAutoCombatPayload($payload)) {
            return;
        }

        // 使用 Laravel Cache 锁来确保原子性
        $lockKey = 'rpg:combat:lock:'.$this->characterId;
        $lock = Cache::lock($lockKey, self::LOCK_TIMEOUT);
        $lockAcquired = $lock->get();

        if (! $lockAcquired) {
            self::dispatch($this->characterId, [])->delay(now()->addSeconds(self::ROUND_INTERVAL_SECONDS));

            return;
        }

        $character = null;

        // 解析初始 payload（仅读取一次 Redis）
        $data = self::decodePayload($payload);
        $latestPayloadData = $data;

        $skillIds = $data['skill_ids'] ?? null;
        if ($skillIds !== null && ! is_array($skillIds)) {
            $skillIds = [];
        }
        if (is_array($skillIds)) {
            $skillIds = array_values(array_map('intval', $skillIds));
        }

        try {
            // 防止历史遗留或重复排队的 job 在间隔窗口内连续推进战斗。
            $waitSeconds = self::waitSecondsBeforeNextTick($data);
            if ($waitSeconds > 0) {
                self::dispatch($this->characterId, [])->delay(now()->addSeconds($waitSeconds));

                return;
            }

            // 检查是否有被取消的技能，如果有则从列表中移除
            $cancelledSkillIds = $data['cancelled_skill_ids'] ?? [];
            if (is_array($skillIds) && is_array($cancelledSkillIds) && ! empty($cancelledSkillIds)) {
                $cancelledSkillIds = array_values(array_map('intval', $cancelledSkillIds));
                $skillIds = array_values(array_diff($skillIds, $cancelledSkillIds));
                $data['skill_ids'] = $skillIds;
                $latestPayloadData = $data;
                self::writePayload($key, $data);
            }

            $character = GameCharacter::query()->find($this->characterId);
            if (! $character) {
                Redis::del($key);

                return;
            }

            // 先检查是否需要刷新怪物，如果需要则广播怪物出现
            if ($combatService->shouldRefreshMonsters($character)) {
                $map = $character->currentMap;
                if ($map instanceof GameMapDefinition) {
                    $combatService->broadcastMonstersAppear($character, $map);
                }
            }

            // 执行战斗推进前再次从 Redis 读取技能列表，确保用户中途取消/启用技能能立即生效
            $freshPayload = Redis::get($key);
            if (self::hasAutoCombatPayload($freshPayload)) {
                $freshData = self::decodePayload($freshPayload);
                if ($freshData !== []) {
                    $latestPayloadData = $freshData;
                    $freshSkillIds = array_key_exists('skill_ids', $freshData) ? $freshData['skill_ids'] : null;
                    if (is_array($freshSkillIds)) {
                        $skillIds = array_values(array_map('intval', $freshSkillIds));
                    } else {
                        $skillIds = null;
                    }
                }
            }

            $result = $combatService->executeRound($character, $skillIds);

            if (! empty($result['defeat']) || ! empty($result['auto_stopped'])) {
                Redis::del($key);

                return;
            }

            self::scheduleNextTick($this->characterId);
        } catch (RuntimeException|InvalidArgumentException $e) {
            $this->broadcastAutoStoppedAndCleanup($character, $e, $key);
        } catch (Throwable $e) {
            Log::error('自动战斗推进失败', [
                'character_id' => $this->characterId,
                'exception' => $e,
            ]);
            $this->broadcastAutoStoppedAndCleanup($character, $e, $key);
        } finally {
            $lock->release();
        }
    }

    /**
     * 获取下一个 job 应该执行的时间(秒)
     */
    public function withExponentialBackoff(int $attempt): int
    {
        return pow(2, $attempt);
    }

    private function broadcastAutoStoppedAndCleanup(?GameCharacter $character, \Throwable $e, string $redisKey): void
    {
        if ($character) {
            Redis::del($redisKey);

            // 重置战斗状态
            $character->is_fighting = false;
            $character->save();

            $payload = null;
            if ($e->getPrevious() instanceof \Throwable) {
                $decoded = json_decode($e->getPrevious()->getMessage(), true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }

            $character->refresh();
            $charArray = $character->toArray();
            $charArray['current_hp'] = $payload['current_hp'] ?? $character->getCurrentHp();
            $charArray['current_mana'] = $character->getCurrentMana();

            $result = [
                'victory' => false,
                'defeat' => false,
                'auto_stopped' => true,
                'monster' => ['name' => '', 'type' => 'normal', 'level' => 1],
                'damage_dealt' => 0,
                'damage_taken' => 0,
                'rounds' => 0,
                'experience_gained' => 0,
                'copper_gained' => 0,
                'loot' => [],
                'character' => $charArray,
                'current_hp' => $charArray['current_hp'],
                'current_mana' => $charArray['current_mana'],
                'combat_log_id' => 0,
            ];

            $this->broadcaster()->broadcastCombatUpdate($character->id, $result);
        }
    }

    public static function redisKey(int $characterId): string
    {
        return self::REDIS_KEY_PREFIX.$characterId;
    }

    public static function ttl(): int
    {
        return self::AUTO_COMBAT_TTL;
    }

    public static function hasAutoCombatPayload(mixed $payload): bool
    {
        return $payload !== null && $payload !== false;
    }

    /**
     * 原子占用自动战斗 Redis key（phpredis 需 EX/NX 五参数形式）
     */
    public static function tryAcquireAutoCombat(int $characterId, ?array $skillIds): bool
    {
        $payload = json_encode([
            'skill_ids' => $skillIds,
            self::NEXT_ROUND_AT_KEY => time() + self::ROUND_INTERVAL_SECONDS,
        ]);

        return (bool) Redis::set(
            self::redisKey($characterId),
            $payload,
            'EX',
            self::ttl(),
            'NX',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function writePayload(string $key, array $payload): void
    {
        Redis::setex($key, self::AUTO_COMBAT_TTL, json_encode($payload));
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodePayload(mixed $payload): array
    {
        if (! is_string($payload)) {
            return [];
        }

        $data = json_decode($payload, true);

        return is_array($data) ? $data : [];
    }

    /**
     * 已有自动战斗 key 时补派任务，避免部署重启后 Redis 锁还在、队列却丢了下一拍。
     */
    public static function resume(int $characterId, ?array $skillIds): void
    {
        $key = self::redisKey($characterId);
        $payload = Redis::get($key);
        if (self::hasAutoCombatPayload($payload)) {
            $data = self::decodePayload($payload);
            $data['skill_ids'] = $skillIds;
            self::writePayload($key, $data);
        }

        self::dispatch($characterId, $skillIds);
    }

    /**
     * 标记正在推进，避免并发开战请求同时打出两下。
     */
    public static function markTickInProgress(int $characterId, ?array $skillIds): void
    {
        $key = self::redisKey($characterId);
        $payload = Redis::get($key);
        $data = self::hasAutoCombatPayload($payload) ? self::decodePayload($payload) : [];
        $data['skill_ids'] = $skillIds;
        $data[self::NEXT_ROUND_AT_KEY] = time() + self::ROUND_INTERVAL_SECONDS;
        self::writePayload($key, $data);
    }

    /**
     * 写入下次推进时间并延迟派发，避免开战接口同步打完第一下后又立刻再打一次。
     */
    public static function scheduleNextTick(int $characterId, ?array $skillIds = null, bool $replaceSkillIds = false): void
    {
        $key = self::redisKey($characterId);
        $payload = Redis::get($key);
        if (! self::hasAutoCombatPayload($payload)) {
            return;
        }

        $data = self::decodePayload($payload);
        if ($replaceSkillIds) {
            $data['skill_ids'] = $skillIds;
        }

        $nextRoundAt = now()->addSeconds(self::ROUND_INTERVAL_SECONDS);
        $data[self::NEXT_ROUND_AT_KEY] = $nextRoundAt->getTimestamp();
        self::writePayload($key, $data);
        self::dispatch($characterId, [])->delay($nextRoundAt);
    }

    /**
     * 距下次推进的等待秒数。过期或间隔异常偏大视为卡死，立即执行。
     *
     * @param  array<string, mixed>  $payload
     */
    public static function waitSecondsBeforeNextTick(array $payload, ?int $nowTimestamp = null): int
    {
        $nextRoundAt = $payload[self::NEXT_ROUND_AT_KEY] ?? null;
        if (! is_numeric($nextRoundAt)) {
            return 0;
        }

        $wait = (int) $nextRoundAt - ($nowTimestamp ?? time());
        if ($wait <= 0 || $wait > self::ROUND_INTERVAL_SECONDS + 2) {
            return 0;
        }

        return $wait;
    }

    private function broadcaster(): GameCombatBroadcaster
    {
        return app(GameCombatBroadcaster::class);
    }
}
