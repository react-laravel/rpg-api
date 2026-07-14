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
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use RuntimeException;

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
            // 防止历史遗留或重复排队的 job 在 3 秒窗口内连续执行回合。
            if (self::shouldWaitForNextRound($data)) {
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

            // 执行回合前再次从 Redis 读取技能列表，确保用户中途取消/启用技能能立即生效
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

            // 检查 Redis key 是否仍然存在
            if (self::hasAutoCombatPayload(Redis::get($key))) {
                $nextRoundAt = now()->addSeconds(self::ROUND_INTERVAL_SECONDS);
                $latestPayloadData[self::NEXT_ROUND_AT_KEY] = $nextRoundAt->getTimestamp();
                self::writePayload($key, $latestPayloadData);
                // 延迟 3 秒后调度下一个 job（不阻塞 Worker）
                self::dispatch($this->characterId, [])->delay($nextRoundAt);
            }
        } catch (RuntimeException|InvalidArgumentException $e) {
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
        $payload = json_encode(['skill_ids' => $skillIds]);

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
     * @param  array<string, mixed>  $payload
     */
    private static function shouldWaitForNextRound(array $payload): bool
    {
        $nextRoundAt = $payload[self::NEXT_ROUND_AT_KEY] ?? null;

        return is_numeric($nextRoundAt) && (int) $nextRoundAt > now()->getTimestamp();
    }

    private function broadcaster(): GameCombatBroadcaster
    {
        return app(GameCombatBroadcaster::class);
    }
}
