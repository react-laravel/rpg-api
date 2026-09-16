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
            $nonCritBonus = $context->nonCritBonus;
            $slowedDamageBonus = $context->slowedDamageBonus;
            $targetDamageRatios = $context->targetDamageRatios;
        } else {
            $monsters = $context['monsters'] ?? $context[0] ?? [];
            $targetMonsters = $context['targetMonsters'] ?? $context[1] ?? [];
            $charAttack = $context['charAttack'] ?? $context[2] ?? 0;
            $skillDamage = $context['skillDamage'] ?? $context[3] ?? 0;
            $isCrit = $context['isCrit'] ?? $context[4] ?? false;
            $charCritDamage = $context['charCritDamage'] ?? $context[5] ?? 1.5;
            $useAoe = $context['useAoe'] ?? $context[6] ?? false;
            $nonCritBonus = (float) ($context['nonCritBonus'] ?? 0);
            $slowedDamageBonus = (float) ($context['slowedDamageBonus'] ?? 0);
            $targetDamageRatios = $context['targetDamageRatios'] ?? null;
        }
        $totalDamageDealt = 0;
        $monstersUpdated = [];
        $ratioBySlot = $this->buildRatioBySlot($targetMonsters, $targetDamageRatios);

        foreach ($monsters as $idx => $m) {
            if (! is_array($m)) {
                $monstersUpdated[$idx] = $m;

                continue;
            }

            $m['damage_taken'] = -1;
            $m['was_attacked'] = false;

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
            $raw = (float) $this->hitAfterDefense($charAttack, $skillDamage, $mDefense, $defenseReduction);

            if ($isCrit) {
                $raw *= $charCritDamage;
            } elseif ($nonCritBonus > 0 && $skillDamage > 0) {
                $raw *= (1 + $nonCritBonus);
            }

            if ($slowedDamageBonus > 0 && (int) ($m['slow_ticks'] ?? 0) > 0) {
                $raw *= (1 + $slowedDamageBonus);
            }

            $slot = isset($m['position']) ? (int) $m['position'] : null;
            $falloff = $slot !== null && isset($ratioBySlot[$slot]) ? $ratioBySlot[$slot] : 1.0;
            $raw *= $falloff;

            $damage = (int) round($raw);
            $aoeMultiplier = config('game.combat.aoe_damage_multiplier', 0.7);
            $targetDamage = $useAoe ? (int) ($damage * $aoeMultiplier) : $damage;
            $actualDamage = min($targetDamage, (int) $m['hp']);

            $m['hp'] = max(0, $m['hp'] - $actualDamage);
            $m['damage_taken'] = $actualDamage;
            $m['was_attacked'] = true;
            $totalDamageDealt += $actualDamage;
            $monstersUpdated[$idx] = $m;
        }

        foreach ($monstersUpdated as $idx => $m) {
            if (is_array($m) && isset($m['is_new'])) {
                unset($monstersUpdated[$idx]['is_new']);
            }
        }

        return [$monstersUpdated, $totalDamageDealt];
    }

    /**
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
        if ($targetMonsters === []) {
            return [0, 0];
        }

        $firstTarget = reset($targetMonsters);
        $targetDefense = (int) ($firstTarget['defense'] ?? 0);
        $autoAttack = $this->hitAfterDefense($charAttack, 0, $targetDefense, $defenseReduction);
        $applied = $this->hitAfterDefense($charAttack, $skillDamage, $targetDefense, $defenseReduction);

        if (! $isCrit) {
            return [$autoAttack, 0];
        }

        $critted = (int) round($applied * $charCritDamage);

        return [$autoAttack, max(0, $critted - $applied)];
    }

    /**
     * 技能威力以攻击力百分之一为单位：180 = 攻击力的 180%。未施放技能时按 100% 普攻。
     */
    public function skillAttackMultiplier(int $skillPower): float
    {
        if ($skillPower <= 0) {
            return 1.0;
        }

        return $skillPower / 100.0;
    }

    public function hitAfterDefense(int $charAttack, int $skillPower, int $defense, float $defenseReduction): int
    {
        $multiplier = $this->skillAttackMultiplier($skillPower);

        return max(0, (int) round($charAttack * $multiplier - $defense * $defenseReduction));
    }

    /**
     * @param  array<int, array<string, mixed>|null>  $monstersUpdated
     */
    public function calculateMonsterCounterDamage(array $monstersUpdated, int $charDefense): int
    {
        return (new CombatEffectApplier)->calculateMonsterCounterDamage($monstersUpdated, $charDefense);
    }

    /**
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
     * @param  array<int, array<string, mixed>|null>  $monsters
     * @return array<int, array<string, mixed>>
     */
    public function selectRoundTargets(array $monsters, bool $isAoeSkill): array
    {
        $attackableMonsters = $this->listAttackableMonsters($monsters);

        if ($attackableMonsters === []) {
            return [];
        }

        if ($isAoeSkill) {
            return $attackableMonsters;
        }

        $sorted = $this->sortByLowestHp($attackableMonsters);

        return [$sorted[0]];
    }

    /**
     * @param  array<int, array<string, mixed>|null>  $monsters
     * @return array<int, array<string, mixed>>
     */
    public function selectLowestHpTargets(array $monsters, int $count): array
    {
        $attackable = $this->sortByLowestHp($this->listAttackableMonsters($monsters));
        if ($count <= 0) {
            return [];
        }

        return array_slice($attackable, 0, $count);
    }

    /**
     * 暴击后追加一个未命中目标（连锁）。
     *
     * @param  array<int, array<string, mixed>|null>  $monsters
     * @param  array<int, array<string, mixed>>  $alreadyTargeted
     * @return array<string, mixed>|null
     */
    public function selectChainTarget(array $monsters, array $alreadyTargeted): ?array
    {
        $usedSlots = [];
        foreach ($alreadyTargeted as $target) {
            if (isset($target['position'])) {
                $usedSlots[(int) $target['position']] = true;
            }
        }

        $candidates = [];
        foreach ($this->listAttackableMonsters($monsters) as $monster) {
            $slot = isset($monster['position']) ? (int) $monster['position'] : null;
            if ($slot === null || isset($usedSlots[$slot])) {
                continue;
            }
            $candidates[] = $monster;
        }

        if ($candidates === []) {
            return null;
        }

        $sorted = $this->sortByLowestHp($candidates);

        return $sorted[0];
    }

    /**
     * @param  array<int, array<string, mixed>>  $targetMonsters
     * @return array<int, int>
     */
    public function getSkillTargetPositions(array $targetMonsters): array
    {
        $positions = array_map(fn ($m) => $m['position'] ?? null, $targetMonsters);

        return array_values(array_filter($positions, fn ($p) => $p !== null));
    }

    public function rollChanceForProcessor(float $chance): bool
    {
        if ($chance <= 0) {
            return false;
        }
        if ($chance >= 1) {
            return true;
        }

        return mt_rand() / mt_getrandmax() < $chance;
    }

    /**
     * @param  array<int, array<string, mixed>|null>  $monsters
     * @return array<int, array<string, mixed>>
     */
    private function listAttackableMonsters(array $monsters): array
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

        return $attackableMonsters;
    }

    /**
     * @param  array<int, array<string, mixed>>  $monsters
     * @return array<int, array<string, mixed>>
     */
    private function sortByLowestHp(array $monsters): array
    {
        usort($monsters, function (array $first, array $second): int {
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

        return $monsters;
    }

    /**
     * @param  array<int, array<string, mixed>>  $targetMonsters
     * @param  array<int, float>|null  $targetDamageRatios
     * @return array<int, float>
     */
    private function buildRatioBySlot(array $targetMonsters, ?array $targetDamageRatios): array
    {
        if ($targetDamageRatios === null) {
            return [];
        }

        $map = [];
        foreach (array_values($targetMonsters) as $i => $target) {
            if (! isset($target['position'])) {
                continue;
            }
            $map[(int) $target['position']] = (float) ($targetDamageRatios[$i] ?? 1.0);
        }

        return $map;
    }
}
