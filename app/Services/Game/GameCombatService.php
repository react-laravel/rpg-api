<?php

namespace App\Services\Game;

use App\Exceptions\GameException;
use App\Jobs\Game\AutoCombatRoundJob;
use App\Models\Game\GameCharacter;
use App\Models\Game\GameMapDefinition;
use App\Services\Game\DTOs\DefeatContext;
use App\Support\Game\RpgAssetIconNormalizer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * 战斗服务类
 *
 * 负责战斗相关的业务逻辑，包括执行战斗回合、处理胜负、发放奖励等
 */
class GameCombatService
{
    /**
     * 构造函数
     *
     * @param  CombatRoundProcessor  $roundProcessor  回合处理器
     * @param  GameMonsterService  $monsterService  怪物服务
     * @param  GameCombatLootService  $lootService  战利品服务
     * @param  GameCombatLogService  $combatLogService  战斗日志服务
     * @param  GameInventoryService  $inventoryService  背包服务
     */
    public function __construct(
        private readonly CombatRoundProcessor $roundProcessor,
        private readonly GameMonsterService $monsterService,
        private readonly GameCombatLootService $lootService,
        private readonly GameCombatLogService $combatLogService,
        private readonly GameInventoryService $inventoryService
    ) {}

    /**
     * 检查怪物是否需要刷新
     */
    public function shouldRefreshMonsters(GameCharacter $character): bool
    {
        return $this->monsterService->shouldRefreshMonsters($character);
    }

    /**
     * 广播怪物出现(不处理攻击)
     */
    public function broadcastMonstersAppear(GameCharacter $character, GameMapDefinition $map): void
    {
        // 刷新怪物
        $this->monsterService->generateNewMonsters($character, $map, $character->combat_monsters ?? [], true);

        // 广播怪物出现
        $monsterData = $this->monsterService->formatMonstersForResponse($character);
        $monstersAppear = [
            'type' => 'monsters_appear',
            'monsters' => $monsterData['monsters'],
            'character' => [
                'current_hp' => $character->getCurrentHp(),
                'current_mana' => $character->getCurrentMana(),
            ],
        ];
        $this->broadcaster()->broadcastCombatUpdate($character->id, $monstersAppear);
    }

    /**
     * Synchronize combat status between Redis and database
     *
     * Auto-combat state is the source of truth from Redis key.
     * DB is_fighting field is synced bidirectionally to prevent:
     * 1. DB=true but Redis key expired → frontend thinks fighting
     * 2. Redis key/job still running but DB=false → frontend thinks not fighting
     */
    public function syncCombatStatusWithRedis(GameCharacter $character): void
    {
        $autoCombatRunning = AutoCombatRoundJob::hasAutoCombatPayload(
            Redis::get(AutoCombatRoundJob::redisKey($character->id))
        );

        if ($character->is_fighting !== $autoCombatRunning) {
            $character->update(['is_fighting' => $autoCombatRunning]);
            $character->refresh();
        }
    }

    /**
     * 死亡复活：清除战斗状态、恢复创建 HP、传送至新手村，不自动开战
     */
    public function reviveCharacter(GameCharacter $character): GameCharacter
    {
        if ($character->getCurrentHp() > 0) {
            throw GameException::invalidOperation('角色未处于死亡状态');
        }

        Redis::del(AutoCombatRoundJob::redisKey($character->id));
        $character->clearCombatState();
        $character->applyReviveResources();
        $character->current_map_id = 1;
        $character->is_fighting = false;
        $character->save();

        return $character->fresh('currentMap');
    }

    /**
     * 获取战斗状态
     *
     * @param  GameCharacter  $character  角色实例
     * @return array 战斗状态数据
     */
    public function getCombatStatus(GameCharacter $character): array
    {
        $character->initializeHpMana();

        $result = [
            'is_fighting' => $character->is_fighting,
            'current_map' => $character->currentMap,
            'combat_stats' => $character->getCombatStats(),
            'current_hp' => $character->getCurrentHp(),
            'current_mana' => $character->getCurrentMana(),
            'last_combat_at' => $character->last_combat_at,
            'skill_cooldowns' => $character->combat_skill_cooldowns ?? [],
        ];

        if ($character->is_fighting) {
            $monsters = $this->resolveCombatMonstersForStatus($character);
            $result['current_combat_monsters'] = $monsters;
            $firstAliveOrAny = $this->pickPrimaryCombatMonster($monsters);
            if ($firstAliveOrAny !== null) {
                $result['current_combat_monster'] = [
                    'id' => $firstAliveOrAny['id'],
                    'name' => $firstAliveOrAny['name'] ?? '',
                    'icon' => $firstAliveOrAny['icon'] ?? null,
                    'type' => $firstAliveOrAny['type'] ?? 'normal',
                    'level' => (int) ($firstAliveOrAny['level'] ?? 1),
                    'hp' => (int) ($firstAliveOrAny['hp'] ?? 0),
                    'max_hp' => (int) ($firstAliveOrAny['max_hp'] ?? 0),
                ];
            }
        }

        return $result;
    }

    /**
     * 执行一轮战斗(支持多怪物连续战斗)
     *
     * @param  GameCharacter  $character  角色实例
     * @param  int[]|null  $skillIds  使用的技能 ID 数组；null 表示不限技能，[] 表示禁用所有技能
     *
     * @throws \InvalidArgumentException 地图不存在或没有怪物
     * @throws \RuntimeException 血量不足或战斗结束
     */
    public function executeRound(GameCharacter $character, ?array $skillIds = null): array
    {
        // 检查是否选择了地图
        if (! $character->current_map_id) {
            throw new \InvalidArgumentException('请先选择一个地图');
        }

        // 如果不在战斗中，自动开始战斗
        if (! $character->is_fighting) {
            $character->fill([
                'is_fighting' => true,
                'last_combat_at' => now(),
            ])->save();
        }

        // 初始化 HP 和 Mana
        $character->initializeHpMana();
        $currentHp = (int) $character->getCurrentHp();
        $currentMana = (int) $character->getCurrentMana();

        // 检查血量是否不足
        if ($currentHp <= 0) {
            $character->clearCombatState();
            $character->update(['is_fighting' => false]);
            throw new \RuntimeException('角色血量不足，已自动停止战斗', previous: new \Exception(json_encode([
                'auto_stopped' => true,
                'current_hp' => $currentHp,
            ])));
        }

        $map = $character->currentMap;
        if (! $map instanceof GameMapDefinition) {
            throw new \InvalidArgumentException('地图不存在');
        }

        // 准备怪物信息
        $monsterInfo = $this->monsterService->prepareMonsterInfo($character, $map);
        $monster = $monsterInfo[0] ?? null;
        $monsterLevel = isset($monsterInfo[1]) ? (int) $monsterInfo[1] : null;
        $monsterHp = (int) $monsterInfo[3];
        $monsterMaxHp = (int) $monsterInfo[4];

        // 检查怪物是否存在
        if (! $monster) {
            throw new \InvalidArgumentException('当前战斗怪物不存在，已清除状态');
        }

        // 处理回合
        $currentRound = (int) $character->combat_rounds + 1;
        $skillCooldowns = is_array($character->combat_skill_cooldowns ?? []) ? $character->combat_skill_cooldowns : [];
        $skillsUsedAggregated = is_array($character->combat_skills_used ?? []) ? $character->combat_skills_used : [];
        $requestedSkillIds = $skillIds === null
            ? null
            : array_map(fn ($v) => (int) $v, array_values($skillIds));

        $roundResult = $this->roundProcessor->processOneRound(
            $character,
            $currentRound,
            (array) $skillCooldowns,
            (array) $skillsUsedAggregated,
            $requestedSkillIds
        );

        // 回合后按体力/能量自动恢复 HP/MP
        $roundRegen = $this->applyRoundResourceRegeneration(
            $character,
            (int) $roundResult['new_char_hp'],
            (int) $roundResult['new_char_mana']
        );
        if ($roundRegen !== []) {
            $roundResult['new_char_hp'] = $character->getCurrentHp();
            $roundResult['new_char_mana'] = $character->getCurrentMana();
        }

        // 持久化战斗状态
        $this->persistCombatState($character, $roundResult, $currentRound);

        // 处理失败
        if (! empty($roundResult['defeat'])) {
            $defeatContext = DefeatContext::fromParams(
                $monster,
                $monsterLevel ?? 0,
                $monsterMaxHp,
                $monsterHp
            );

            return $this->handleDefeat($character, $map, $defeatContext, $currentRound, $roundResult);
        }

        // 检查是否所有怪物都死亡
        $isVictory = ! $roundResult['has_alive_monster'];
        if ($isVictory) {
            // 所有怪物死亡，不立即重生，保持死亡怪物可见直到下一回合
            $roundResult['new_monster_max_hp'] = $roundResult['new_monster_hp']; // 保持总 HP 不变
            $roundResult['victory'] = true;
        }

        // 每回合按概率尝试补充新怪物(30% 不生成，70% 按权重生成 1～5 只)，不要求全部死亡
        $roundResult = $this->monsterService->tryAddNewMonsters($character, $map, $roundResult, $currentRound);

        // 为本回合死亡的怪物发放经验和铜币
        $expGained = $roundResult['experience_gained'] ?? 0;
        $copperGained = $roundResult['copper_gained'] ?? 0;
        if ($expGained > 0 || $copperGained > 0) {
            $rewards = $this->lootService->distributeRewards($character, $roundResult);
            $roundResult['loot'] = array_merge($roundResult['loot'] ?? [], ['copper' => $copperGained]);
        }

        // 处理死亡战利品
        $deathLoot = $this->lootService->processDeathLoot($character, $roundResult);
        if (! empty($deathLoot)) {
            $roundResult['loot'] = array_merge($roundResult['loot'] ?? [], $deathLoot);
        }

        // 保存更新的怪物状态
        $character->combat_monster_hp = max(0, $roundResult['new_monster_hp']);
        $character->combat_monster_max_hp = max(0, $roundResult['new_monster_max_hp'] ?? $roundResult['new_monster_hp']);
        $character->save();

        // 获取当前怪物列表用于响应(固定 5 个槽位)
        $monsterData = $this->monsterService->formatMonstersForResponse($character);
        $fixedMonsters = $monsterData['monsters'];
        $firstAliveMonster = $monsterData['first_alive_monster'];

        // 创建战斗日志
        $combatLog = $this->combatLogService->createRoundLog(
            $character,
            $map,
            isset($firstAliveMonster['id']) ? (int) $firstAliveMonster['id'] : ($monster->id ?? 0),
            $roundResult,
            $roundRegen
        );

        $result = [
            'victory' => $roundResult['victory'] ?? false,
            'defeat' => false,
            'monster_id' => $firstAliveMonster['id'] ?? $monster->id,
            'monsters' => $fixedMonsters,
            'monster' => [
                'name' => $firstAliveMonster['name'] ?? $monster->name,
                'icon' => $firstAliveMonster['icon'] ?? $monster->icon,
                'type' => $firstAliveMonster['type'] ?? $monster->type,
                'level' => $firstAliveMonster['level'] ?? $monsterLevel,
                'hp' => max(0, $roundResult['new_monster_hp'] ?? $monsterHp),
                'max_hp' => max(0, $roundResult['new_monster_max_hp'] ?? $monsterMaxHp),
            ],
            'monster_hp_before_round' => $monsterHp,
            'damage_dealt' => $roundResult['round_damage_dealt'],
            'damage_taken' => $roundResult['round_damage_taken'],
            'rounds' => $currentRound,
            'experience_gained' => $roundResult['experience_gained'] ?? 0,
            'copper_gained' => $roundResult['copper_gained'] ?? 0,
            'loot' => $roundResult['loot'] ?? [],
            'skills_used' => $roundResult['skills_used_this_round'],
            'skill_target_positions' => $roundResult['skill_target_positions'] ?? [],
            'skill_cooldowns' => $character->combat_skill_cooldowns ?? [], // 技能冷却(回合数)
            'round_regen' => $roundRegen !== [] ? $roundRegen : null,
            'character' => ($character->fresh() ?? $character)->toArray(),
            'combat_log_id' => $combatLog->id,
        ];

        // 广播战斗更新
        $this->broadcaster()->broadcastCombatUpdate($character->id, $result);
        $character->refresh();

        // 广播背包更新
        $inventoryPayload = $this->inventoryService->getInventoryForBroadcast($character);
        $this->broadcaster()->broadcastInventoryUpdate($character->id, $inventoryPayload);

        return $result;
    }

    /**
     * 回合结束后按体力/能量恢复 HP/MP
     *
     * @return array<string, array{name: string, restored: int}>
     */
    private function applyRoundResourceRegeneration(GameCharacter $character, int $currentHp, int $currentMana): array
    {
        if ($currentHp <= 0) {
            return [];
        }

        $charStats = $character->getCombatStats();
        $maxHp = (int) ($charStats['max_hp'] ?? 0);
        $maxMana = (int) ($charStats['max_mana'] ?? 0);
        $hpRegen = $character->getHpRegenPerRound();
        $mpRegen = $character->getManaRegenPerRound();

        $newHp = $currentHp;
        $newMana = $currentMana;
        $regen = [];

        if ($hpRegen > 0 && $maxHp > 0 && $currentHp < $maxHp) {
            $newHp = min($maxHp, $currentHp + $hpRegen);
            $restored = $newHp - $currentHp;
            if ($restored > 0) {
                $regen['hp'] = [
                    'name' => '体力恢复',
                    'restored' => $restored,
                ];
            }
        }

        if ($mpRegen > 0 && $maxMana > 0 && $currentMana < $maxMana) {
            $newMana = min($maxMana, $currentMana + $mpRegen);
            $restored = $newMana - $currentMana;
            if ($restored > 0) {
                $regen['mp'] = [
                    'name' => '能量恢复',
                    'restored' => $restored,
                ];
            }
        }

        if ($regen === []) {
            return [];
        }

        $character->current_hp = $newHp;
        $character->current_mana = $newMana;
        $character->save();

        return $regen;
    }

    /**
     * 持久化战斗状态
     *
     * @param  GameCharacter  $character  角色实例
     * @param  array<string,mixed>  $roundResult  回合结果
     * @param  int  $currentRound  当前回合数
     */
    private function persistCombatState(GameCharacter $character, array $roundResult, int $currentRound): void
    {
        $character->current_hp = max(0, (int) ($roundResult['new_char_hp'] ?? 0));
        $character->current_mana = max(0, (int) ($roundResult['new_char_mana'] ?? 0));
        $character->combat_total_damage_dealt += (int) ($roundResult['round_damage_dealt'] ?? 0);
        $character->combat_total_damage_taken += (int) ($roundResult['round_damage_taken'] ?? 0);
        $character->combat_rounds = $currentRound;
        $character->combat_skills_used = is_array($roundResult['new_skills_aggregated'] ?? []) ? $roundResult['new_skills_aggregated'] : [];
        $character->combat_skill_cooldowns = is_array($roundResult['new_cooldowns'] ?? []) ? $roundResult['new_cooldowns'] : [];

        // 保存更新的怪物数组(如果有)
        if (isset($roundResult['monsters_updated']) && is_array($roundResult['monsters_updated'])) {
            $character->combat_monsters = $roundResult['monsters_updated'];
        }
    }

    /**
     * 处理失败情况
     *
     * @param  GameCharacter  $character  角色实例
     * @param  GameMapDefinition  $map  地图实例
     * @param  DefeatContext  $defeatContext  失败上下文 DTO
     * @param  int  $currentRound  当前回合数
     * @param  array<string,mixed>  $roundResult  回合结果
     * @return array 失败结果
     */
    private function handleDefeat(
        GameCharacter $character,
        GameMapDefinition $map,
        DefeatContext $defeatContext,
        int $currentRound,
        array $roundResult
    ): array {
        // 失败时(显式转换为 int，避免 mixed 导致的静态分析问题)
        $character->current_hp = max(0, (int) ($roundResult['new_char_hp'] ?? 0));
        $character->is_fighting = false;

        // 创建失败日志
        $combatLog = $this->combatLogService->createDefeatLog($character, $map, $defeatContext->monster, $roundResult);

        // 清除战斗状态并保存
        $character->clearCombatState();
        $character->save();

        // 调试：保存后检查值(fresh 可能为 null，使用 ?? 回退)
        $freshCharacter = $character->fresh() ?? $character;
        Log::info('[handleDefeat] 保存后数据库中的 current_hp:', [
            'character_id' => $character->id,
            'current_hp' => $character->current_hp,
            'fresh_current_hp' => $freshCharacter->current_hp,
        ]);

        Log::info('[handleDefeat] 刷新后的角色:', ['current_hp' => $freshCharacter->current_hp]);
        $charArray = $freshCharacter->toArray();
        $charArray['current_hp'] = 0;
        $charArray['current_mana'] = 0;
        Log::info('[handleDefeat] 覆盖后的 charArray:', ['current_hp' => $charArray['current_hp']]);

        $result = [
            'victory' => false,
            'defeat' => true,
            'auto_stopped' => true,
            'monster_id' => $defeatContext->monster->id,
            'monsters' => [],
            'monster' => [
                'name' => $defeatContext->monster->name,
                'type' => $defeatContext->monster->type,
                'level' => $defeatContext->monsterLevel,
                'hp' => max(0, $roundResult['new_monster_hp'] ?? $defeatContext->monsterHpBeforeRound),
                'max_hp' => $defeatContext->monsterMaxHp,
            ],
            'monster_hp_before_round' => $defeatContext->monsterHpBeforeRound,
            'damage_dealt' => (int) $character->combat_total_damage_dealt,
            'damage_taken' => (int) $character->combat_total_damage_taken,
            'rounds' => $currentRound,
            'experience_gained' => 0,
            'copper_gained' => 0,
            'loot' => [],
            'skills_used' => is_array($roundResult['new_skills_aggregated'] ?? []) ? $roundResult['new_skills_aggregated'] : [],
            'character' => $charArray,
            'current_hp' => 0,
            'current_mana' => 0,
            'combat_log_id' => $combatLog->id,
        ];

        // 广播战斗更新
        $this->broadcaster()->broadcastCombatUpdate($character->id, $result);
        $character->refresh();

        // 广播背包更新
        $inventoryPayload = $this->inventoryService->getInventoryForBroadcast($character);
        $this->broadcaster()->broadcastInventoryUpdate($character->id, $inventoryPayload);

        return $result;
    }

    private function broadcaster(): GameCombatBroadcaster
    {
        return app(GameCombatBroadcaster::class);
    }

    /**
     * 获取战斗日志
     *
     * @param  GameCharacter  $character  角色实例
     * @return array 战斗日志列表
     */
    public function getCombatLogs(GameCharacter $character): array
    {
        return $this->combatLogService->getCombatLogs($character);
    }

    /**
     * 获取单条战斗日志详情
     *
     * @param  GameCharacter  $character  角色实例
     * @param  int  $logId  日志 ID
     * @return array 日志详情
     */
    public function getCombatLogDetail(GameCharacter $character, int $logId): array
    {
        return $this->combatLogService->getCombatLogDetail($character, $logId);
    }

    /**
     * 获取战斗统计
     *
     * @param  GameCharacter  $character  角色实例
     * @return array 战斗统计数据
     */
    public function getCombatStats(GameCharacter $character): array
    {
        return $this->combatLogService->getCombatStats($character);
    }

    /**
     * @return array<int, array<string, mixed>|null>
     */
    private function resolveCombatMonstersForStatus(GameCharacter $character): array
    {
        $monsters = $character->combat_monsters ?? [];
        $hasMonster = false;
        foreach ($monsters as $monster) {
            if (is_array($monster) && isset($monster['id'])) {
                $hasMonster = true;
                break;
            }
        }

        if (! $hasMonster && $character->combat_monster_id !== null) {
            $definition = $character->currentCombatMonster;
            if ($definition !== null) {
                $stats = $definition->getCombatStats();
                $monsters = array_fill(0, 5, null);
                $monsters[0] = [
                    'id' => $definition->id,
                    'instance_id' => 'legacy',
                    'name' => $definition->name,
                    'icon' => $definition->icon,
                    'type' => $definition->type,
                    'level' => (int) $definition->level,
                    'hp' => (int) ($character->combat_monster_hp ?? $stats['hp']),
                    'max_hp' => (int) ($character->combat_monster_max_hp ?? $stats['hp']),
                    'attack' => $stats['attack'],
                    'defense' => $stats['defense'],
                    'experience' => $stats['experience'],
                    'position' => 0,
                    'damage_taken' => -1,
                ];
            }
        }

        return RpgAssetIconNormalizer::normalizeMonsterCombatList($monsters);
    }

    /**
     * @param  array<int, array<string, mixed>|null>  $monsters
     * @return array<string, mixed>|null
     */
    private function pickPrimaryCombatMonster(array $monsters): ?array
    {
        $firstAny = null;
        foreach ($monsters as $monster) {
            if (! is_array($monster) || ! isset($monster['id'])) {
                continue;
            }

            if ($firstAny === null) {
                $firstAny = $monster;
            }

            if (($monster['hp'] ?? 0) > 0) {
                return $monster;
            }
        }

        return $firstAny;
    }
}
