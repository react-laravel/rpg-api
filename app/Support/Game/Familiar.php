<?php

namespace App\Support\Game;

/**
 * 一只跟班：形态跟角色等级走，自己的等级靠击杀经验涨到上限。
 */
final class Familiar
{
    public const BASE_CAP = 7;

    /**
     * @param  iterable<int, object>  $characterSkills
     * @return array{has_charm: bool, min_level: int, cap: int, attack_bonus: float, hp_bonus: float}
     */
    public static function bonusesFromSkills(iterable $characterSkills): array
    {
        $hasCharm = false;
        $minLevel = 1;
        $cap = self::BASE_CAP;
        $attackBonus = 0.0;
        $hpBonus = 0.0;

        foreach ($characterSkills as $characterSkill) {
            $skill = $characterSkill->skill ?? null;
            if ($skill === null) {
                continue;
            }
            if (($skill->effect_key ?? '') === 'charm-light' && ($skill->type ?? '') === 'active') {
                $hasCharm = true;
            }
            if (($skill->skill_line ?? '') !== 'mage_charm') {
                continue;
            }
            $effects = is_array($skill->effects ?? null) ? $skill->effects : [];
            $minLevel = max($minLevel, (int) ($effects['summon_level'] ?? 1));
            if (isset($effects['pet_level_cap'])) {
                $cap = max($cap, (int) $effects['pet_level_cap']);
            }
            $attackBonus += (float) ($effects['pet_attack_bonus'] ?? 0);
            $hpBonus += (float) ($effects['pet_hp_bonus'] ?? 0);
        }

        return [
            'has_charm' => $hasCharm,
            'min_level' => $minLevel,
            'cap' => $cap,
            'attack_bonus' => $attackBonus,
            'hp_bonus' => $hpBonus,
        ];
    }

    /**
     * @return array{form: string, name: string, attack: int, hp: int}
     */
    public static function profile(int $characterLevel): array
    {
        if ($characterLevel >= 80) {
            return ['form' => 'wolf', 'name' => '狼妖', 'attack' => 6, 'hp' => 24];
        }
        if ($characterLevel >= 40) {
            return ['form' => 'statue', 'name' => '石像', 'attack' => 4, 'hp' => 16];
        }

        return ['form' => 'whelp', 'name' => '小兽', 'attack' => 2, 'hp' => 10];
    }

    public static function xpToAdvance(int $level): int
    {
        return max(1, 8 * $level);
    }

    /**
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>
     */
    public static function summon(?array $existing, int $characterLevel, int $minLevel, int $cap, float $attackBonus, float $hpBonus): array
    {
        $existing ??= [];
        $alive = (int) ($existing['hp'] ?? 0) > 0;
        $level = max(1, (int) ($existing['level'] ?? 1));
        if (! $alive) {
            $level = max($level, $minLevel);
        }
        $level = min($cap, $level);
        $pet = [
            'level' => $level,
            'experience' => (int) ($existing['experience'] ?? 0),
            'hp' => $alive ? (int) $existing['hp'] : 0,
            'max_hp' => (int) ($existing['max_hp'] ?? 0),
        ];
        $pet = self::syncStats($pet, $characterLevel, $cap, $attackBonus, $hpBonus);
        if (! $alive) {
            $pet['hp'] = $pet['max_hp'];
        }

        return $pet;
    }

    /**
     * @param  array<string, mixed>  $pet
     * @return array<string, mixed>
     */
    public static function syncStats(array $pet, int $characterLevel, int $cap, float $attackBonus, float $hpBonus): array
    {
        $level = min($cap, max(1, (int) ($pet['level'] ?? 1)));
        $profile = self::profile($characterLevel);
        $maxHp = max(1, (int) round(($profile['hp'] + ($level - 1) * 4) * (1 + $hpBonus)));
        $attack = max(1, (int) round(($profile['attack'] + ($level - 1)) * (1 + $attackBonus)));
        $oldMax = (int) ($pet['max_hp'] ?? $maxHp);
        $hp = (int) ($pet['hp'] ?? 0);
        if ($hp > 0 && $maxHp > $oldMax) {
            $hp += $maxHp - $oldMax;
        }
        $hp = min($maxHp, max(0, $hp));

        return [
            'name' => $profile['name'],
            'form' => $profile['form'],
            'level' => $level,
            'experience' => (int) ($pet['experience'] ?? 0),
            'hp' => $hp,
            'max_hp' => $maxHp,
            'attack' => $attack,
        ];
    }

    /**
     * @param  array<string, mixed>  $pet
     * @return array<string, mixed>
     */
    public static function grantXp(array $pet, int $amount, int $characterLevel, int $cap, float $attackBonus, float $hpBonus): array
    {
        if ($amount <= 0 || (int) ($pet['hp'] ?? 0) <= 0) {
            return $pet;
        }

        $level = (int) ($pet['level'] ?? 1);
        $xp = (int) ($pet['experience'] ?? 0) + $amount;
        while ($level < $cap && $xp >= self::xpToAdvance($level)) {
            $xp -= self::xpToAdvance($level);
            $level++;
        }
        if ($level >= $cap) {
            $xp = 0;
        }
        $pet['level'] = $level;
        $pet['experience'] = $xp;

        return self::syncStats($pet, $characterLevel, $cap, $attackBonus, $hpBonus);
    }
}
