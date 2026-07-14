<?php

namespace App\Services\Game\Combat;

use App\Services\Game\DTOs\DamageContext;
use Illuminate\Support\Facades\Log;

/**
 * 战斗伤害计算器
 */
class CombatDamageCalculator
{
    /**
     * 对目标怪物施加角色伤害，返回更新后的怪物列表与总伤害
     *
     * @param  DamageContext|array  $context  DamageContext object or backwards-compatible array
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    public function applyCharacterDamageToMonsters(DamageContext|array $context): array
    {
        if ($context instanceof DamageContext) {
            $monsters = $context->monsters;
            $targetMonsters = $context->targetMonsters;
            $charAttack = $context->charAttack;
            $skillDamage = $context->skillDamage;
            $isCrit = $context->isCrit;
            $charCritDamage = $context->charCritDamage;
            $useAoe = $context->useAoe;
        } else {
            $monsters = $context['monsters'] ?? $context[0] ?? [];
            $targetMonsters = $context['targetMonsters'] ?? $context[1] ?? [];
            $charAttack = $context['charAttack'] ?? $context[2] ?? 0;
            $skillDamage = $context['skillDamage'] ?? $context[3] ?? 0;
            $isCrit = $context['isCrit'] ?? $context[4] ?? false;
            $charCritDamage = $context['charCritDamage'] ?? $context[5] ?? 1.5;
            $useAoe = $context['useAoe'] ?? $context[6] ?? false;
        }
        $totalDamageDealt = 0;
        $monstersUpdated = [];

        foreach ($monsters as $idx => $m) {
            $m['damage_taken'] = -1;
            $m['was_attacked'] = false;

            // 新出现的怪物不受攻击
            if (isset($m['is_new']) && $m['is_new'] === true) {
                Log::info('Skipping new monster attack', ['monster' => $m['name'], 'is_new' => true]);
                $monstersUpdated[$idx] = $m;

                continue;
            }

            if (($m['hp'] ?? 0) <= 0) {
                $monstersUpdated[$idx] = $m;

                continue;
            }

            $isTarget = $this->isMonsterInTargets($m, $targetMonsters);
            if (! $isTarget) {
                $monstersUpdated[$idx] = $m;

                continue;
            }

            $mDefense = (int) ($m['defense'] ?? 0);
            $defenseReduction = config('game.combat.defense_reduction', 0.5);
            $baseDamage = max(0, $charAttack - $mDefense * $defenseReduction);
            $damage = $skillDamage > 0
                ? (int) ($baseDamage + $skillDamage)
                : (int) ($baseDamage * ($isCrit ? $charCritDamage : 1));
            $aoeMultiplier = config('game.combat.aoe_damage_multiplier', 0.7);
            $targetDamage = $useAoe ? (int) ($damage * $aoeMultiplier) : $damage;
            $actualDamage = min($targetDamage, (int) $m['hp']);

            $m['hp'] = max(0, $m['hp'] - $actualDamage);
            $m['damage_taken'] = $actualDamage;
            $m['was_attacked'] = true;
            $totalDamageDealt += $actualDamage;
            $monstersUpdated[$idx] = $m;
        }

        // 清除所有新怪物标记
        foreach ($monstersUpdated as $idx => $m) {
            if (isset($m['is_new'])) {
                unset($monstersUpdated[$idx]['is_new']);
            }
        }

        return [$monstersUpdated, $totalDamageDealt];
    }

    /**
     * 计算基础攻击伤害与暴击额外伤害
     *
     * @param  array<int, array<string, mixed>>  $targetMonsters
     * @return array{0: int, 1: int}
     */
    public function computeBaseAttackDamage(
        array $targetMonsters,
        int $skillDamage,
        int $charAttack,
        float $charCritDamage,
        bool $isCrit,
        float $defenseReduction
    ): array {
        if (empty($targetMonsters)) {
            return [0, 0];
        }

        if ($skillDamage > 0) {
            return [$skillDamage, 0];
        }

        $firstTarget = reset($targetMonsters);
        $targetDefense = $firstTarget['defense'] ?? 0;
        $baseAttackDamage = max(0, (int) ($charAttack - $targetDefense * $defenseReduction));

        if (! $isCrit) {
            return [$baseAttackDamage, 0];
        }

        $critDamageAmount = (int) ($baseAttackDamage * ($charCritDamage - 1));
        $baseAttackDamage = (int) ($baseAttackDamage * $charCritDamage);

        return [$baseAttackDamage, $critDamageAmount];
    }

    /**
     * 计算所有存活怪物对角色造成的总反击伤害
     *
     * @param  array<int, array<string, mixed>>  $monstersUpdated
     */
    public function calculateMonsterCounterDamage(array $monstersUpdated, int $charDefense): int
    {
        $total = 0;
        foreach ($monstersUpdated as $m) {
            if (($m['hp'] ?? 0) <= 0) {
                continue;
            }
            $monsterAttack = $m['attack'] ?? 0;
            $monsterDefenseReduction = config('game.combat.monster_defense_reduction', 0.3);
            $monsterDamage = $monsterAttack - $charDefense * $monsterDefenseReduction;
            if ($monsterDamage > 0) {
                $total += (int) $monsterDamage;
            }
        }

        return $total;
    }

    /**
     * 按槽位判断是否为攻击目标
     *
     * @param  array<string, mixed>  $monster
     * @param  array<int, array<string, mixed>>  $targets
     */
    public function isMonsterInTargets(array $monster, array $targets): bool
    {
        $slot = $monster['position'] ?? null;
        if ($slot === null) {
            return false;
        }
        foreach ($targets as $tm) {
            if (($tm['position'] ?? null) === $slot) {
                return true;
            }
        }

        return false;
    }

    /**
     * 选择本回合攻击目标
     * 单体：优先攻击血量最低的怪物（同血量按槽位靠前）；跳过 is_new 怪物（首回合不可攻击）
     * 群体：攻击所有可攻击的存活怪物
     *
     * @param  array<int, array<string, mixed>|null>  $monsters
     * @return array<int, array<string, mixed>>
     */
    public function selectRoundTargets(array $monsters, bool $isAoeSkill): array
    {
        $attackableMonsters = [];
        foreach ($monsters as $monster) {
            if (! is_array($monster)) {
                continue;
            }
            if ((int) ($monster['hp'] ?? 0) <= 0) {
                continue;
            }
            if ((bool) ($monster['is_new'] ?? false)) {
                continue;
            }
            $attackableMonsters[] = $monster;
        }

        if ($attackableMonsters === []) {
            return [];
        }

        if ($isAoeSkill) {
            return $attackableMonsters;
        }

        usort($attackableMonsters, function (array $first, array $second): int {
            $firstHp = isset($first['hp']) && is_numeric($first['hp']) ? (int) $first['hp'] : 0;
            $secondHp = isset($second['hp']) && is_numeric($second['hp']) ? (int) $second['hp'] : 0;
            $hpCompare = $firstHp <=> $secondHp;
            if ($hpCompare !== 0) {
                return $hpCompare;
            }

            $firstPosition = isset($first['position']) && is_numeric($first['position'])
                ? (int) $first['position']
                : PHP_INT_MAX;
            $secondPosition = isset($second['position']) && is_numeric($second['position'])
                ? (int) $second['position']
                : PHP_INT_MAX;

            return $firstPosition <=> $secondPosition;
        });

        return [$attackableMonsters[0]];
    }

    /**
     * 收集技能命中的目标位置
     *
     * @param  array<int, array<string, mixed>>  $targetMonsters
     * @return array<int, int>
     */
    public function getSkillTargetPositions(array $targetMonsters): array
    {
        $positions = array_map(fn ($m) => $m['position'] ?? null, $targetMonsters);

        return array_values(array_filter($positions, fn ($p) => $p !== null));
    }

    /**
     * 概率判定
     */
    public function rollChanceForProcessor(float $chance): bool
    {
        // $chance 是 0~1，例如 0.12 就是 12%概率
        return mt_rand() / mt_getrandmax() < $chance;
    }
}
