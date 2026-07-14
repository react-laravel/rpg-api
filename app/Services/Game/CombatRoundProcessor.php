<?php

namespace App\Services\Game;

use App\Models\Game\GameCharacter;
use App\Services\Game\Combat\CombatDamageCalculator;
use App\Services\Game\Combat\CombatRewardCalculator;
use App\Services\Game\Combat\CombatSkillSelector;
use App\Services\Game\DTOs\DamageContext;
use App\Services\Game\DTOs\RoundDetailsContext;
use App\Support\Game\RpgAssetIconNormalizer;

/**
 * 单回合战斗处理器：技能选择、目标选择、伤害计算、反击、奖励结算
 */
class CombatRoundProcessor
{
    public function __construct(
        private CombatSkillSelector $skillSelector = new CombatSkillSelector,
        private CombatDamageCalculator $damageCalculator = new CombatDamageCalculator,
        private CombatRewardCalculator $rewardCalculator = new CombatRewardCalculator
    ) {}

    /**
     * 处理一回合战斗(支持多怪物)
     *
     * @return array{round_damage_dealt: int, round_damage_taken: int, new_monster_hp: int, new_char_hp: int, new_char_mana: int, defeat: bool, has_alive_monster: bool, skills_used_this_round: array, new_cooldowns: array, new_skills_aggregated: array, monsters_updated: array, slots_where_monster_died_this_round: array<int>, experience_gained: int, copper_gained: int, round_details: array}
     */
    public function processOneRound(
        GameCharacter $character,
        int $currentRound,
        array $skillCooldowns,
        array $skillsUsedAggregated,
        ?array $requestedSkillIds = null
    ): array {
        $character->initializeHpMana();

        $charStats = $character->getCombatStats();
        $charHp = $character->getCurrentHp();
        $currentMana = $character->getCurrentMana();
        $charAttack = $charStats['attack'];
        $charDefense = $charStats['defense'];
        $charCritRate = $charStats['crit_rate'];
        $charCritDamage = $charStats['crit_damage'];

        $monsters = $character->combat_monsters ?? [];
        $difficulty = $character->getDifficultyMultipliers();

        // 统计本回合开始时的怪物信息
        $aliveMonstersAtStart = $this->getAliveMonsters($monsters);
        $monstersKilledThisRound = 0;
        $hpAtRoundStart = $this->getMonsterHpSnapshot($monsters);

        // 使用技能选择器
        $skillResult = $this->skillSelector->resolveRoundSkill(
            $character,
            $requestedSkillIds,
            $currentRound,
            $currentMana,
            $skillCooldowns
        );
        $currentMana = $skillResult['mana'];
        $isAoeSkill = $skillResult['is_aoe'];
        $skillDamage = $skillResult['skill_damage'];
        $skillsUsedThisRound = $skillResult['skills_used_this_round'];
        $newCooldowns = $skillResult['new_cooldowns'];

        $isCrit = (rand(1, 100) / 100) <= $charCritRate;

        // 使用伤害计算器选择目标
        $targetMonsters = $this->damageCalculator->selectRoundTargets($monsters, $isAoeSkill);
        $useAoe = $isAoeSkill && ! empty($targetMonsters);

        // 收集技能命中的目标位置
        $skillTargetPositions = $this->damageCalculator->getSkillTargetPositions($targetMonsters);

        // 伤害构成详情
        $baseAttackDamage = 0;
        $critDamageAmount = 0;
        $aoeDamageAmount = 0;

        // 计算基础攻击伤害
        $defenseReduction = config('game.combat.defense_reduction', 0.5);
        [$baseAttackDamage, $critDamageAmount] = $this->damageCalculator->computeBaseAttackDamage(
            $targetMonsters,
            $skillDamage,
            $charAttack,
            $charCritDamage,
            $isCrit,
            $defenseReduction
        );

        // AOE 伤害计算
        if ($useAoe) {
            $aoeMultiplier = config('game.combat.aoe_damage_multiplier', 0.7);
            $targetCount = count($targetMonsters);
            if ($targetCount > 1) {
                $aoeDamageAmount = (int) ($baseAttackDamage * (1 - $aoeMultiplier) * $targetCount);
            }
        }

        // 使用伤害计算器处理伤害
        [$monstersUpdated, $totalDamageDealt] = $this->damageCalculator->applyCharacterDamageToMonsters(
            DamageContext::fromParams(
                monsters: $monsters,
                targetMonsters: $targetMonsters,
                charAttack: $charAttack,
                skillDamage: $skillDamage,
                isCrit: $isCrit,
                charCritDamage: $charCritDamage,
                useAoe: $useAoe,
            )
        );

        // 统计本回合杀死的怪物数量
        $slotsWhereMonsterDiedThisRound = [];
        foreach ($monstersUpdated as $idx => $m) {
            if (($hpAtRoundStart[$idx] ?? 0) > 0 && ($m['hp'] ?? 0) <= 0) {
                $monstersKilledThisRound++;
                $slotsWhereMonsterDiedThisRound[] = $idx;
            }
        }

        // 计算怪物反击伤害
        $totalMonsterDamage = $this->damageCalculator->calculateMonsterCounterDamage($monstersUpdated, $charDefense);
        $charHp -= $totalMonsterDamage;

        $character->combat_monsters = $monstersUpdated;
        $newTotalHp = array_sum(array_column($monstersUpdated, 'hp'));

        $newSkillsAggregated = $this->aggregateSkillsUsed($skillsUsedThisRound, $skillsUsedAggregated);
        $hasAliveMonster = $this->hasAliveMonster($monstersUpdated);

        // 使用奖励计算器
        [$totalExperience, $totalCopper] = $this->rewardCalculator->calculateRoundDeathRewards(
            $monstersUpdated,
            $hpAtRoundStart,
            $difficulty
        );

        // 获取第一个存活怪物的详细信息
        $firstAliveMonster = $this->getFirstAliveMonster($monstersUpdated);
        $roundDetails = $this->buildRoundDetails(
            RoundDetailsContext::fromParams(
                character: $character,
                firstAliveMonster: $firstAliveMonster,
                charAttack: $charAttack,
                charDefense: $charDefense,
                charCritRate: $charCritRate,
                charCritDamage: $charCritDamage,
                baseAttackDamage: $baseAttackDamage,
                skillDamage: $skillDamage,
                critDamageAmount: $critDamageAmount,
                aoeDamageAmount: $aoeDamageAmount,
                totalDamageDealt: $totalDamageDealt,
                defenseReduction: $defenseReduction,
                totalMonsterDamage: $totalMonsterDamage,
                currentRound: $currentRound,
                aliveMonsterCount: count($aliveMonstersAtStart),
                monstersKilledThisRound: $monstersKilledThisRound,
                isCrit: $isCrit,
                useAoe: $useAoe,
                difficulty: $difficulty
            )
        );

        return [
            'round_damage_dealt' => $totalDamageDealt,
            'round_damage_taken' => $totalMonsterDamage,
            'new_monster_hp' => $newTotalHp,
            'new_char_hp' => $charHp,
            'new_char_mana' => $currentMana,
            'defeat' => $charHp <= 0,
            'has_alive_monster' => $hasAliveMonster,
            'skills_used_this_round' => $skillsUsedThisRound,
            'skill_target_positions' => array_values($skillTargetPositions),
            'new_cooldowns' => $newCooldowns,
            'new_skills_aggregated' => $newSkillsAggregated,
            'monsters_updated' => $monstersUpdated,
            'slots_where_monster_died_this_round' => $slotsWhereMonsterDiedThisRound,
            'experience_gained' => $totalExperience,
            'copper_gained' => $totalCopper,
            'round_details' => $roundDetails,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $monsters
     * @return array<int, array<string, mixed>>
     */
    private function getAliveMonsters(array $monsters): array
    {
        return array_filter($monsters, fn ($m) => ($m['hp'] ?? 0) > 0);
    }

    /**
     * @param  array<int, array<string, mixed>>  $monsters
     * @return array<int, int>
     */
    private function getMonsterHpSnapshot(array $monsters): array
    {
        $hpAtRoundStart = [];
        foreach ($monsters as $idx => $m) {
            $hpAtRoundStart[$idx] = $m['hp'] ?? 0;
        }

        return $hpAtRoundStart;
    }

    /**
     * @param  array<int, array<string, mixed>>  $monstersUpdated
     * @return array<string, mixed>|null
     */
    private function getFirstAliveMonster(array $monstersUpdated): ?array
    {
        foreach ($monstersUpdated as $m) {
            if (($m['hp'] ?? 0) > 0) {
                return $m;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRoundDetails(RoundDetailsContext $context): array
    {
        return [
            'character' => [
                'level' => $context->character->level,
                'class' => $context->character->class,
                'attack' => $context->charAttack,
                'defense' => $context->charDefense,
                'crit_rate' => $context->charCritRate,
                'crit_damage' => $context->charCritDamage,
            ],
            'monster' => $context->firstAliveMonster ? [
                'level' => $context->firstAliveMonster['level'] ?? 1,
                'hp' => $context->firstAliveMonster['hp'] ?? 0,
                'max_hp' => $context->firstAliveMonster['max_hp'] ?? 0,
                'attack' => $context->firstAliveMonster['attack'] ?? 0,
                'defense' => $context->firstAliveMonster['defense'] ?? 0,
                'experience' => $context->firstAliveMonster['experience'] ?? 0,
            ] : null,
            'damage' => [
                'base_attack' => $context->baseAttackDamage,
                'skill_damage' => $context->skillDamage,
                'crit_damage' => $context->critDamageAmount,
                'aoe_damage' => $context->aoeDamageAmount,
                'total' => $context->totalDamageDealt,
                'defense_reduction' => $context->defenseReduction,
                'monster_counter' => $context->totalMonsterDamage,
            ],
            'battle' => [
                'round' => $context->currentRound,
                'alive_count' => $context->aliveMonsterCount,
                'killed_count' => $context->monstersKilledThisRound,
                'is_crit' => $context->isCrit,
                'is_aoe' => $context->useAoe,
            ],
            'difficulty' => [
                'tier' => $context->character->difficulty_tier ?? 0,
                'multiplier' => $context->difficulty['reward'] ?? 1,
            ],
        ];
    }

    /**
     * @param  array<int, array{skill_id: int, name: string, icon: string|null}>  $skillsUsedThisRound
     * @param  array<int|string, array{skill_id: int, name: string, icon: string|null, use_count: int}>  $skillsUsedAggregated
     * @return array<int, array{skill_id: int, name: string, icon: string|null, use_count: int}>
     */
    private function aggregateSkillsUsed(array $skillsUsedThisRound, array $skillsUsedAggregated): array
    {
        $aggregated = $skillsUsedAggregated;
        foreach ($skillsUsedThisRound as $entry) {
            $id = $entry['skill_id'];
            if (! isset($aggregated[$id])) {
                $aggregated[$id] = [
                    'skill_id' => $entry['skill_id'],
                    'name' => $entry['name'],
                    'icon' => RpgAssetIconNormalizer::normalizeSkill($entry['icon'] ?? null),
                    'use_count' => 0,
                ];
            }
            $aggregated[$id]['use_count']++;
        }

        return array_values($aggregated);
    }

    /**
     * @param  array<int, array<string, mixed>>  $monstersUpdated
     */
    private function hasAliveMonster(array $monstersUpdated): bool
    {
        foreach ($monstersUpdated as $m) {
            if (($m['hp'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }
}
