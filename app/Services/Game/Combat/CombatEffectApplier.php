<?php

namespace App\Services\Game\Combat;

/**
 * 拍制战斗状态：灼烧/冻结/减速、护盾吸收与破盾回报。
 */
class CombatEffectApplier
{
    private const SLOW_COUNTER_MULTIPLIER = 0.5;

    private const BURN_DAMAGE_RATIO = 0.2;

    /**
     * @param  array<int, array<string, mixed>|null>  $monsters
     * @return array{0: array<int, array<string, mixed>|null>, 1: int}
     */
    public function tickMonsterStatuses(array $monsters): array
    {
        $burnDamageTotal = 0;
        $updated = [];

        foreach ($monsters as $idx => $monster) {
            if (! is_array($monster)) {
                $updated[$idx] = $monster;

                continue;
            }

            $hp = (int) ($monster['hp'] ?? 0);
            if ($hp <= 0) {
                $updated[$idx] = $monster;

                continue;
            }

            $burnTicks = (int) ($monster['burn_ticks'] ?? 0);
            $burnDamage = (int) ($monster['burn_damage'] ?? 0);
            if ($burnTicks > 0 && $burnDamage > 0) {
                $actual = min($burnDamage, $hp);
                $monster['hp'] = $hp - $actual;
                $monster['damage_taken'] = $actual;
                $monster['was_attacked'] = true;
                $burnDamageTotal += $actual;
                $burnTicks--;
            }

            if ($burnTicks > 0) {
                $monster['burn_ticks'] = $burnTicks;
            } else {
                unset($monster['burn_ticks'], $monster['burn_damage']);
            }

            $freezeTicks = (int) ($monster['freeze_ticks'] ?? 0);
            if ($freezeTicks > 0) {
                $monster['freeze_ticks'] = $freezeTicks - 1;
                if ($monster['freeze_ticks'] <= 0) {
                    unset($monster['freeze_ticks']);
                }
            }

            $slowTicks = (int) ($monster['slow_ticks'] ?? 0);
            if ($slowTicks > 0) {
                $monster['slow_ticks'] = $slowTicks - 1;
                if ($monster['slow_ticks'] <= 0) {
                    unset($monster['slow_ticks']);
                }
            }

            $updated[$idx] = $monster;
        }

        return [$updated, $burnDamageTotal];
    }

    /**
     * @param  array<string, mixed>|null  $buffs
     * @return array<string, mixed>
     */
    public function tickCharacterBuffs(?array $buffs): array
    {
        $buffs = is_array($buffs) ? $buffs : [];
        $shieldTicks = (int) ($buffs['shield_ticks'] ?? 0);
        if ($shieldTicks > 0) {
            $buffs['shield_ticks'] = $shieldTicks - 1;
            if ($buffs['shield_ticks'] <= 0 || (int) ($buffs['shield_hp'] ?? 0) <= 0) {
                unset($buffs['shield_hp'], $buffs['shield_max_hp'], $buffs['shield_ticks'], $buffs['reflect_on_break'], $buffs['mana_restore_on_break'], $buffs['spell_damage_bonus']);
            }
        }

        return $buffs;
    }

    /**
     * @param  array<string, mixed>|null  $buffs
     * @param  array<string, mixed>  $castEffects
     * @return array<string, mixed>
     */
    public function applyShieldBuff(?array $buffs, array $castEffects): array
    {
        $buffs = is_array($buffs) ? $buffs : [];
        $amount = (int) ($castEffects['shield_amount'] ?? 0);
        $duration = (int) ($castEffects['shield_duration'] ?? 0);
        if ($amount <= 0 || $duration <= 0) {
            return $buffs;
        }

        $buffs['shield_hp'] = max((int) ($buffs['shield_hp'] ?? 0), $amount);
        $buffs['shield_max_hp'] = max((int) ($buffs['shield_max_hp'] ?? 0), $buffs['shield_hp']);
        $buffs['shield_ticks'] = max((int) ($buffs['shield_ticks'] ?? 0), $duration);
        if (isset($castEffects['reflect_on_break'])) {
            $buffs['reflect_on_break'] = (float) $castEffects['reflect_on_break'];
        }
        if (isset($castEffects['mana_restore_on_break'])) {
            $buffs['mana_restore_on_break'] = (float) $castEffects['mana_restore_on_break'];
        }
        if (isset($castEffects['spell_damage_bonus'])) {
            $buffs['spell_damage_bonus'] = (float) $castEffects['spell_damage_bonus'];
        }

        return $buffs;
    }

    /**
     * @param  array<string, mixed>|null  $buffs
     * @return array{0: int, 1: array<string, mixed>, 2: int, 3: int}
     *                                                                remaining damage, buffs, reflected damage, mana restored percent of max (0-100 scale via float stored)
     */
    public function absorbWithShield(int $incomingDamage, ?array $buffs, int $maxMana): array
    {
        $buffs = is_array($buffs) ? $buffs : [];
        $shieldHp = (int) ($buffs['shield_hp'] ?? 0);
        if ($incomingDamage <= 0 || $shieldHp <= 0) {
            return [$incomingDamage, $buffs, 0, 0];
        }

        $absorbed = min($shieldHp, $incomingDamage);
        $remaining = $incomingDamage - $absorbed;
        $buffs['shield_hp'] = $shieldHp - $absorbed;
        $reflected = 0;
        $manaRestored = 0;

        if ($buffs['shield_hp'] <= 0) {
            $reflectRatio = (float) ($buffs['reflect_on_break'] ?? 0);
            $manaRatio = (float) ($buffs['mana_restore_on_break'] ?? 0);
            if ($reflectRatio > 0) {
                $reflected = (int) round($absorbed * $reflectRatio);
            }
            if ($manaRatio > 0 && $maxMana > 0) {
                $manaRestored = (int) round($maxMana * $manaRatio);
            }
            unset($buffs['shield_hp'], $buffs['shield_max_hp'], $buffs['shield_ticks'], $buffs['reflect_on_break'], $buffs['mana_restore_on_break'], $buffs['spell_damage_bonus']);
        }

        return [$remaining, $buffs, $reflected, $manaRestored];
    }

    /**
     * @param  array<string, mixed>|null  $buffs
     * @return array{hp: int, max_hp: int, ticks: int, broke: bool, absorbed: int}|null
     */
    public function summarizeShield(?array $buffs, int $absorbed = 0, bool $broke = false, int $fallbackMaxHp = 0): ?array
    {
        $buffs = is_array($buffs) ? $buffs : [];
        $hp = (int) ($buffs['shield_hp'] ?? 0);
        $ticks = (int) ($buffs['shield_ticks'] ?? 0);
        $maxHp = max($hp, (int) ($buffs['shield_max_hp'] ?? 0), $fallbackMaxHp);
        if ($hp <= 0 && $ticks <= 0 && ! $broke && $absorbed <= 0) {
            return null;
        }

        return [
            'hp' => max(0, $hp),
            'max_hp' => max(0, $maxHp),
            'ticks' => max(0, $ticks),
            'broke' => $broke,
            'absorbed' => max(0, $absorbed),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $targetMonsters
     * @param  array<int, array<string, mixed>|null>  $monsters
     * @param  array<string, mixed>  $castEffects
     * @return array<int, array<string, mixed>|null>
     */
    public function applyHitStatuses(array $monsters, array $targetMonsters, array $castEffects, int $skillDamage): array
    {
        $targetSlots = [];
        foreach ($targetMonsters as $target) {
            if (isset($target['position'])) {
                $targetSlots[(int) $target['position']] = true;
            }
        }

        $burnTicks = (int) ($castEffects['burn_duration'] ?? 0);
        if (! empty($castEffects['apply_burn'])) {
            $burnTicks = max($burnTicks, (int) ($castEffects['ailment_duration'] ?? 3));
        }
        $freezeTicks = (int) ($castEffects['freeze_duration'] ?? 0);
        if (! empty($castEffects['apply_freeze'])) {
            $freezeTicks = max($freezeTicks, (int) ($castEffects['ailment_duration'] ?? 3));
        }
        $slowTicks = (int) ($castEffects['slow_duration'] ?? 0);
        if ((int) ($castEffects['ground_slow_duration'] ?? 0) > 0) {
            $slowTicks = max($slowTicks, (int) $castEffects['ground_slow_duration']);
        }
        $explicitSlowChance = (float) ($castEffects['slow_chance'] ?? 0);
        $slowChance = $explicitSlowChance > 0 ? $explicitSlowChance : 1.0;
        if (! empty($castEffects['apply_shock'])) {
            $slowTicks = max($slowTicks, (int) ($castEffects['ailment_duration'] ?? 3));
            $slowChance = 1.0;
        }

        $burnDamage = $burnTicks > 0
            ? max(1, (int) round($skillDamage * self::BURN_DAMAGE_RATIO))
            : 0;

        foreach ($monsters as $idx => $monster) {
            if (! is_array($monster) || ($monster['hp'] ?? 0) <= 0) {
                continue;
            }
            $slot = isset($monster['position']) ? (int) $monster['position'] : null;
            if ($slot === null || ! isset($targetSlots[$slot])) {
                continue;
            }

            if ($burnTicks > 0) {
                $monster['burn_ticks'] = max((int) ($monster['burn_ticks'] ?? 0), $burnTicks);
                $monster['burn_damage'] = max((int) ($monster['burn_damage'] ?? 0), $burnDamage);
            }

            $isBoss = (($monster['type'] ?? '') === 'boss') || ! empty($monster['is_boss']);
            $appliedFreeze = $freezeTicks;
            if ($isBoss && isset($castEffects['boss_freeze_duration'])) {
                $appliedFreeze = max(1, (int) ceil((float) $castEffects['boss_freeze_duration']));
            }
            if ($appliedFreeze > 0) {
                $monster['freeze_ticks'] = max((int) ($monster['freeze_ticks'] ?? 0), $appliedFreeze);
            }

            if ($slowTicks > 0 && (new CombatDamageCalculator)->rollChanceForProcessor($slowChance)) {
                $monster['slow_ticks'] = max((int) ($monster['slow_ticks'] ?? 0), $slowTicks);
            }

            $monsters[$idx] = $monster;
        }

        return $monsters;
    }

    /**
     * @param  array<int, array<string, mixed>|null>  $monstersUpdated
     */
    public function calculateMonsterCounterDamage(array $monstersUpdated, int $charDefense): int
    {
        $total = 0;
        foreach ($monstersUpdated as $m) {
            if (! is_array($m) || ($m['hp'] ?? 0) <= 0) {
                continue;
            }
            if ((int) ($m['freeze_ticks'] ?? 0) > 0) {
                continue;
            }

            $monsterAttack = (int) ($m['attack'] ?? 0);
            $monsterDefenseReduction = config('game.combat.monster_defense_reduction', 0.3);
            $monsterDamage = $monsterAttack - $charDefense * $monsterDefenseReduction;
            if ($monsterDamage <= 0) {
                continue;
            }
            if ((int) ($m['slow_ticks'] ?? 0) > 0) {
                $monsterDamage *= self::SLOW_COUNTER_MULTIPLIER;
            }
            $total += (int) $monsterDamage;
        }

        return $total;
    }

    /**
     * @param  array<int, array<string, mixed>>  $targetMonsters
     * @param  array<int, array<string, mixed>|null>  $allMonsters
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, float>}
     */
    public function resolveTargetsWithFalloff(
        array $allMonsters,
        bool $isAoe,
        array $castEffects,
        CombatDamageCalculator $damageCalculator
    ): array {
        $pierceCount = (int) ($castEffects['pierce_count'] ?? 0);
        $bounceCount = (int) ($castEffects['bounce_count'] ?? 0);
        $pierceFalloff = (float) ($castEffects['pierce_falloff'] ?? 0.2);
        $bounceRatio = (float) ($castEffects['bounce_ratio'] ?? 0.7);

        if ($pierceCount > 0) {
            $targets = $damageCalculator->selectLowestHpTargets($allMonsters, $pierceCount);
            $ratios = [];
            foreach (array_values($targets) as $i => $target) {
                $ratios[] = max(0.1, 1 - $pierceFalloff * $i);
            }

            return [$targets, $ratios];
        }

        if ($bounceCount > 0) {
            $targets = $damageCalculator->selectLowestHpTargets($allMonsters, $bounceCount);
            $ratios = [];
            foreach (array_values($targets) as $i => $target) {
                $ratios[] = $i === 0 ? 1.0 : max(0.1, $bounceRatio);
            }

            return [$targets, $ratios];
        }

        $targets = $damageCalculator->selectRoundTargets($allMonsters, $isAoe);
        $ratios = array_fill(0, count($targets), 1.0);
        $singleRatio = (float) ($castEffects['single_target_ratio'] ?? 0);
        if ($singleRatio > 0 && $targets !== []) {
            $aoeMultiplier = (float) config('game.combat.aoe_damage_multiplier', 0.7);
            $primary = $damageCalculator->selectLowestHpTargets($targets, 1)[0] ?? null;
            $primarySlot = isset($primary['position']) ? (int) $primary['position'] : null;
            foreach (array_values($targets) as $i => $target) {
                $slot = isset($target['position']) ? (int) $target['position'] : null;
                $ratios[$i] = $slot !== null && $slot === $primarySlot ? $singleRatio : $aoeMultiplier;
            }
        }

        return [$targets, $ratios];
    }

    public function burnDamageRatio(): float
    {
        return self::BURN_DAMAGE_RATIO;
    }
}
