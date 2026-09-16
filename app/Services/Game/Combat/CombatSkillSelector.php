<?php

namespace App\Services\Game\Combat;

use App\Models\Game\GameCharacter;
use App\Models\Game\GameCharacterSkill;
use Illuminate\Support\Collection;

/**
 * 战斗技能选择器：智能选择最佳技能
 */
class CombatSkillSelector
{
    /** @var list<string> */
    private const ADDITIVE_EFFECT_KEYS = [
        'damage_bonus',
        'spell_damage_bonus',
        'crit_bonus',
        'non_crit_bonus',
        'slowed_damage_bonus',
        'crit_damage_bonus',
    ];

    /**
     * 冷却存剩余战斗推进次数。旧数据曾存到期回合号，用 combat_rounds 游标换算。
     *
     * @param  array<int|string, mixed>|null  $stored
     * @return array<int, int>
     */
    public function remainingCooldowns(?array $stored, int $legacyRoundCursor = 0): array
    {
        $stored ??= [];
        $remaining = [];
        foreach ($stored as $skillId => $value) {
            $left = $legacyRoundCursor > 0
                ? max(0, (int) $value - $legacyRoundCursor)
                : max(0, (int) $value);
            if ($left > 0) {
                $remaining[(int) $skillId] = $left;
            }
        }

        return $remaining;
    }

    /**
     * 把未就绪技能的剩余冷却减 1；归零后不再出现在结果里。
     *
     * @param  array<int|string, mixed>|null  $skillCooldowns
     * @return array<int, int>
     */
    public function tickRemainingCooldowns(?array $skillCooldowns): array
    {
        $skillCooldowns ??= [];
        $remaining = [];
        foreach ($skillCooldowns as $skillId => $value) {
            $left = (int) $value - 1;
            if ($left > 0) {
                $remaining[(int) $skillId] = $left;
            }
        }

        return $remaining;
    }

    /**
     * 本拍能否施放看拍前剩余；拍末其它技能减 1，刚施放的写入完整冷却且本拍不再减。
     *
     * @param  array<int|string, mixed>|null  $remainingAtStart
     * @return array<int, int>
     */
    public function cooldownsAfterPulse(?array $remainingAtStart, ?int $usedSkillId, int $appliedCooldown): array
    {
        $newCooldowns = $this->tickRemainingCooldowns($remainingAtStart);
        if ($usedSkillId === null) {
            return $newCooldowns;
        }
        if ($appliedCooldown > 0) {
            $newCooldowns[$usedSkillId] = $appliedCooldown;
        } else {
            unset($newCooldowns[$usedSkillId]);
        }

        return $newCooldowns;
    }

    /**
     * @return array{
     *   mana: int,
     *   is_aoe: bool,
     *   skill_damage: int,
     *   skills_used_this_round: array,
     *   new_cooldowns: array,
     *   cast_effects: array<string, mixed>,
     *   is_defensive: bool
     * }
     */
    public function resolveRoundSkill(
        GameCharacter $character,
        ?array $requestedSkillIds,
        int $currentMana,
        array $skillCooldowns
    ): array {
        $remainingAtStart = $this->remainingCooldowns($skillCooldowns);

        $learnedSkills = $character->skills()
            ->with('skill')
            ->get()
            ->filter(fn ($cs) => $cs->skill !== null);

        $activeSkills = $learnedSkills->filter(fn ($cs) => $cs->skill->type === 'active');
        $passiveSkills = $learnedSkills->filter(fn ($cs) => $cs->skill->type === 'passive');
        $activeSkills = $this->restrictActiveSkills($activeSkills, $requestedSkillIds);

        $monsters = $character->combat_monsters ?? [];
        $aliveMonsters = array_filter($monsters, fn ($m) => is_array($m) && ($m['hp'] ?? 0) > 0);
        $aliveMonsterCount = count($aliveMonsters);
        $lowHpMonsters = array_filter($aliveMonsters, fn ($m) => $m['hp'] > 0 && $m['hp'] <= ($m['max_hp'] ?? 100) * 0.3);
        $lowHpMonsterCount = count($lowHpMonsters);
        $totalMonsterHp = array_sum(array_column($aliveMonsters, 'hp'));

        $charStats = $character->getCombatStats();
        $charAttack = $charStats['attack'];
        $buffs = is_array($character->combat_buffs ?? null) ? $character->combat_buffs : [];
        $shieldSpellBonus = (float) ($buffs['spell_damage_bonus'] ?? 0);

        $availableSkills = [];
        foreach ($activeSkills as $charSkill) {
            /** @var GameCharacterSkill $charSkill */
            $skill = $charSkill->skill;
            $remainingCooldown = $remainingAtStart[$skill->id] ?? 0;

            if ($currentMana < (int) $skill->mana_cost || $remainingCooldown > 0) {
                continue;
            }

            $built = $this->buildSkillCandidate($skill, $passiveSkills, $shieldSpellBonus);
            $availableSkills[] = $built;
        }

        if ($availableSkills === []) {
            return $this->buildNoSkillRoundResult(
                $currentMana,
                $this->cooldownsAfterPulse($remainingAtStart, null, 0)
            );
        }

        $selectedSkill = $this->selectOptimalSkill(
            $availableSkills,
            $aliveMonsterCount,
            $lowHpMonsterCount,
            $totalMonsterHp,
            $charAttack
        );

        if ($selectedSkill === null) {
            return $this->buildNoSkillRoundResult(
                $currentMana,
                $this->cooldownsAfterPulse($remainingAtStart, null, 0)
            );
        }

        $skill = $selectedSkill['skill'];
        $currentMana -= (int) $selectedSkill['mana_cost'];
        $newCooldowns = $this->cooldownsAfterPulse(
            $remainingAtStart,
            (int) $skill->id,
            (int) $selectedSkill['cooldown']
        );
        $isAoeSkill = (bool) $selectedSkill['is_aoe'];
        $effectKey = $skill->effect_key ?? null;
        if ((int) ($selectedSkill['cast_effects']['extra_meteors'] ?? 0) > 0) {
            $effectKey = 'meteor-storm';
        }

        return [
            'mana' => $currentMana,
            'is_aoe' => $isAoeSkill,
            'skill_damage' => (int) $selectedSkill['damage'],
            'skills_used_this_round' => [[
                'skill_id' => $skill->id,
                'name' => $skill->name,
                'icon' => $skill->icon,
                'effect_key' => $effectKey,
                'target_type' => $isAoeSkill ? 'all' : 'single',
                'passive_effects' => $selectedSkill['passive_effects'] ?? [],
                'passive_names' => $selectedSkill['passive_names'] ?? [],
            ]],
            'new_cooldowns' => $newCooldowns,
            'cast_effects' => $selectedSkill['cast_effects'] ?? [],
            'is_defensive' => (bool) ($selectedSkill['is_defensive'] ?? false),
        ];
    }

    /**
     * @param  Collection<int, GameCharacterSkill>  $passiveSkills
     * @return array<string, mixed>
     */
    public function buildSkillCandidate(object $skill, $passiveSkills, float $shieldSpellBonus = 0.0): array
    {
        $passiveEffects = $this->getPassiveEffectsForSkill($skill, $passiveSkills);
        $activeEffects = is_array($skill->effects ?? null) ? $skill->effects : [];
        $mergedEffects = $this->mergeEffectMaps($activeEffects, $passiveEffects);

        $isAoe = ($skill->target_type ?? 'single') === 'all';
        $damage = (int) ($skill->damage ?? $skill->base_damage ?? 0);

        $damageBonus = (float) ($mergedEffects['damage_bonus'] ?? 0) + (float) ($mergedEffects['spell_damage_bonus'] ?? 0) + $shieldSpellBonus;
        if ($damageBonus > 0 && $damage > 0) {
            $damage = (int) round($damage * (1 + $damageBonus));
        }

        $singleRatio = (float) ($mergedEffects['single_target_ratio'] ?? 0);

        $cooldown = (int) $skill->cooldown;
        if (isset($mergedEffects['cooldown_override'])) {
            $cooldown = max(0, (int) $mergedEffects['cooldown_override']);
        } else {
            $cooldown = max(0, $cooldown - (int) ($mergedEffects['cooldown_reduction'] ?? 0));
        }

        $shieldAmount = (int) ($mergedEffects['shield_amount'] ?? 0);
        $shieldDuration = (int) ($mergedEffects['duration'] ?? $mergedEffects['shield_duration'] ?? 0);
        $isDefensive = $shieldAmount > 0 && $damage <= 0;

        $castEffects = [
            'crit_bonus' => (float) ($mergedEffects['crit_bonus'] ?? 0),
            'non_crit_bonus' => (float) ($mergedEffects['non_crit_bonus'] ?? 0),
            'crit_damage_bonus' => (float) ($mergedEffects['crit_damage_bonus'] ?? 0),
            'slowed_damage_bonus' => (float) ($mergedEffects['slowed_damage_bonus'] ?? 0),
            'pierce_count' => (int) ($mergedEffects['pierce_count'] ?? 0),
            'pierce_falloff' => (float) ($mergedEffects['pierce_falloff'] ?? 0.2),
            'bounce_count' => (int) ($mergedEffects['bounce_count'] ?? 0),
            'bounce_ratio' => (float) ($mergedEffects['bounce_ratio'] ?? 0.7),
            'chain_on_crit' => (bool) ($mergedEffects['chain_on_crit'] ?? false),
            'chain_ratio' => (float) ($mergedEffects['chain_ratio'] ?? 0.5),
            'shield_amount' => $shieldAmount,
            'shield_duration' => $shieldDuration,
            'reflect_on_break' => (float) ($mergedEffects['reflect_on_break'] ?? 0),
            'mana_restore_on_break' => (float) ($mergedEffects['mana_restore_on_break'] ?? 0),
            'spell_damage_bonus' => (float) ($mergedEffects['spell_damage_bonus'] ?? 0),
            'burn_duration' => (int) ($mergedEffects['burn_duration'] ?? 0),
            'freeze_duration' => (int) ceil((float) ($mergedEffects['freeze_duration'] ?? 0)),
            'boss_freeze_duration' => (float) ($mergedEffects['boss_freeze_duration'] ?? 0),
            'slow_chance' => (float) ($mergedEffects['slow_chance'] ?? 0),
            'slow_duration' => (int) ($mergedEffects['slow_duration'] ?? 0),
            'ground_slow_duration' => (int) ($mergedEffects['ground_slow_duration'] ?? 0),
            'extra_meteors' => (int) ($mergedEffects['extra_meteors'] ?? 0),
            'single_target_ratio' => $singleRatio,
            'apply_burn' => (bool) ($mergedEffects['apply_burn'] ?? false),
            'apply_freeze' => (bool) ($mergedEffects['apply_freeze'] ?? false),
            'apply_shock' => (bool) ($mergedEffects['apply_shock'] ?? false),
            'ailment_duration' => (int) ($mergedEffects['ailment_duration'] ?? 0),
        ];

        // 连锁闪电默认弹跳 3 个目标，不当全体陨石用
        if (($skill->effect_key ?? '') === 'chain-lightning' && $castEffects['bounce_count'] <= 0) {
            $castEffects['bounce_count'] = 3;
        }
        if ($castEffects['bounce_count'] > 0 || $castEffects['pierce_count'] > 0) {
            $isAoe = false;
        }

        return [
            'char_skill' => null,
            'skill' => $skill,
            'damage' => $isDefensive ? 0 : $damage,
            'mana_cost' => (int) $skill->mana_cost,
            'cooldown' => $cooldown,
            'is_aoe' => $isAoe && ! $isDefensive,
            'is_defensive' => $isDefensive,
            'passive_effects' => $passiveEffects,
            'passive_names' => $this->getPassiveNamesForSkill($skill, $passiveSkills),
            'cast_effects' => $castEffects,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $availableSkills
     */
    public function selectOptimalSkill(
        array $availableSkills,
        int $aliveMonsterCount,
        int $lowHpMonsterCount,
        int $totalMonsterHp,
        int $charAttack
    ): ?array {
        if ($availableSkills === []) {
            return null;
        }

        $offensive = array_values(array_filter($availableSkills, fn ($s) => ! ($s['is_defensive'] ?? false)));
        $pool = $offensive !== [] ? $offensive : $availableSkills;

        if (count($pool) === 1) {
            return $pool[0];
        }

        $baseAttackDamage = (int) ($charAttack * 0.5);

        if ($aliveMonsterCount >= 2) {
            $aoeSkills = array_values(array_filter($pool, fn ($s) => $s['is_aoe'] || ($s['cast_effects']['bounce_count'] ?? 0) > 0 || ($s['cast_effects']['pierce_count'] ?? 0) > 0));
            if ($aoeSkills !== []) {
                usort($aoeSkills, fn (array $a, array $b) => $this->compareSkillsByCombatScore($a, $b, $aliveMonsterCount, $totalMonsterHp, $charAttack));

                return $aoeSkills[0];
            }
        }

        if ($totalMonsterHp <= $charAttack * 2) {
            usort($pool, function (array $firstSkill, array $secondSkill) use ($totalMonsterHp, $charAttack) {
                if ($firstSkill['mana_cost'] === 0 && $secondSkill['mana_cost'] > 0) {
                    return -1;
                }
                if ($secondSkill['mana_cost'] === 0 && $firstSkill['mana_cost'] > 0) {
                    return 1;
                }
                $effectiveDamageA = min($this->estimatedSkillHit($firstSkill, $charAttack), $totalMonsterHp);
                $effectiveDamageB = min($this->estimatedSkillHit($secondSkill, $charAttack), $totalMonsterHp);
                $efficiencyA = $firstSkill['mana_cost'] > 0 ? $effectiveDamageA / $firstSkill['mana_cost'] : $effectiveDamageA * 10;
                $efficiencyB = $secondSkill['mana_cost'] > 0 ? $effectiveDamageB / $secondSkill['mana_cost'] : $effectiveDamageB * 10;

                if (abs($efficiencyA - $efficiencyB) > 0.1) {
                    return $efficiencyB <=> $efficiencyA;
                }

                return $firstSkill['mana_cost'] <=> $secondSkill['mana_cost'];
            });

            return $pool[0];
        }

        $skillsWithDamage = array_values(array_filter($pool, fn ($s) => $s['damage'] > 0));
        if ($skillsWithDamage !== []) {
            usort($skillsWithDamage, fn (array $a, array $b) => $this->compareSkillsByCombatScore($a, $b, $aliveMonsterCount, $totalMonsterHp, $charAttack));

            $bestSkill = $skillsWithDamage[0];
            $bestHit = $this->estimatedSkillHit($bestSkill, $charAttack);
            $bestEfficiency = $bestSkill['mana_cost'] > 0 ? $bestHit / $bestSkill['mana_cost'] : $bestHit;

            if ($bestEfficiency >= $baseAttackDamage * 0.5 || $bestHit > $totalMonsterHp * 0.5) {
                return $bestSkill;
            }
        }

        usort($pool, function ($a, $b) {
            if ($a['mana_cost'] === 0 && $b['mana_cost'] > 0) {
                return -1;
            }
            if ($b['mana_cost'] === 0 && $a['mana_cost'] > 0) {
                return 1;
            }

            return $a['mana_cost'] <=> $b['mana_cost'];
        });

        return $pool[0];
    }

    /**
     * @param  Collection<int, GameCharacterSkill>  $activeSkills
     * @param  int[]|null  $requestedSkillIds
     * @return Collection<int, GameCharacterSkill>
     */
    public function restrictActiveSkills($activeSkills, ?array $requestedSkillIds)
    {
        if ($requestedSkillIds === null) {
            return $activeSkills;
        }

        $allowedIds = array_flip($requestedSkillIds);

        return $activeSkills->filter(fn ($cs) => isset($allowedIds[$cs->skill->id]));
    }

    /**
     * @param  array<int, int>  $cooldowns
     * @return array{mana: int, is_aoe: bool, skill_damage: int, skills_used_this_round: array, new_cooldowns: array, cast_effects: array, is_defensive: bool}
     */
    public function buildNoSkillRoundResult(int $mana, array $cooldowns): array
    {
        return [
            'mana' => $mana,
            'is_aoe' => false,
            'skill_damage' => 0,
            'skills_used_this_round' => [],
            'new_cooldowns' => $cooldowns,
            'cast_effects' => [],
            'is_defensive' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $first
     * @param  array<string, mixed>  $second
     * @return array<string, mixed>
     */
    public function mergeEffectMaps(array $first, array $second): array
    {
        $effects = $first;
        foreach ($second as $key => $value) {
            if (in_array($key, self::ADDITIVE_EFFECT_KEYS, true) && is_numeric($value)) {
                $effects[$key] = (float) ($effects[$key] ?? 0) + (float) $value;

                continue;
            }
            $effects[$key] = $value;
        }

        return $effects;
    }

    /**
     * @param  Collection<int, GameCharacterSkill>  $passiveSkills
     * @return array<string, mixed>
     */
    public function getPassiveEffectsForSkill(object $activeSkill, $passiveSkills): array
    {
        $effects = [];
        foreach ($passiveSkills as $charSkill) {
            $passive = $charSkill->skill;
            if (! $this->isPassiveForActiveSkill($passive, $activeSkill)) {
                continue;
            }

            $effects = $this->mergeEffectMaps($effects, is_array($passive->effects ?? null) ? $passive->effects : []);
        }

        return $effects;
    }

    private function getPassiveNamesForSkill(object $activeSkill, $passiveSkills): array
    {
        $names = [];
        foreach ($passiveSkills as $charSkill) {
            $passive = $charSkill->skill;
            if ($this->isPassiveForActiveSkill($passive, $activeSkill)) {
                $names[] = $passive->name;
            }
        }

        return $names;
    }

    private function isPassiveForActiveSkill(object $passive, object $activeSkill): bool
    {
        if (($passive->skill_line ?? null) && ($activeSkill->skill_line ?? null)) {
            return $passive->skill_line === $activeSkill->skill_line;
        }

        return ($passive->effect_key ?? null) !== null
            && $passive->effect_key === ($activeSkill->effect_key ?? null);
    }

    /**
     * @param  array{damage: int, mana_cost: int}  $firstSkill
     * @param  array{damage: int, mana_cost: int}  $secondSkill
     */
    private function compareSkillsByEfficiency(array $firstSkill, array $secondSkill): int
    {
        $firstEfficiency = $firstSkill['mana_cost'] > 0 ? $firstSkill['damage'] / $firstSkill['mana_cost'] : $firstSkill['damage'];
        $secondEfficiency = $secondSkill['mana_cost'] > 0 ? $secondSkill['damage'] / $secondSkill['mana_cost'] : $secondSkill['damage'];

        if (abs($firstEfficiency - $secondEfficiency) > 0.1) {
            return $secondEfficiency <=> $firstEfficiency;
        }

        return $secondSkill['damage'] <=> $firstSkill['damage'];
    }

    /**
     * @param  array{damage: int, mana_cost: int, cooldown?: int, is_aoe?: bool, cast_effects?: array}  $firstSkill
     * @param  array{damage: int, mana_cost: int, cooldown?: int, is_aoe?: bool, cast_effects?: array}  $secondSkill
     */
    private function compareSkillsByCombatScore(array $firstSkill, array $secondSkill, int $aliveMonsterCount, int $totalMonsterHp, int $charAttack = 0): int
    {
        $firstScore = $this->calculateCombatScore($firstSkill, $aliveMonsterCount, $totalMonsterHp, $charAttack);
        $secondScore = $this->calculateCombatScore($secondSkill, $aliveMonsterCount, $totalMonsterHp, $charAttack);

        if (abs($firstScore - $secondScore) > 0.1) {
            return $secondScore <=> $firstScore;
        }

        return $this->compareSkillsByEfficiency($firstSkill, $secondSkill);
    }

    /**
     * @param  array{damage: int, mana_cost: int, cooldown?: int, is_aoe?: bool, cast_effects?: array}  $skill
     */
    private function estimatedSkillHit(array $skill, int $charAttack): float
    {
        $power = (int) ($skill['damage'] ?? 0);
        if ($power <= 0) {
            return 0.0;
        }

        return $charAttack * ($power / 100.0);
    }

    /**
     * @param  array{damage: int, mana_cost: int, cooldown?: int, is_aoe?: bool, cast_effects?: array}  $skill
     */
    private function calculateCombatScore(array $skill, int $aliveMonsterCount, int $totalMonsterHp, int $charAttack = 0): float
    {
        $effects = $skill['cast_effects'] ?? [];
        $multiTarget = ($skill['is_aoe'] ?? false)
            || ((int) ($effects['bounce_count'] ?? 0) > 0)
            || ((int) ($effects['pierce_count'] ?? 0) > 0);
        $targetCount = $multiTarget ? max(1, $aliveMonsterCount) : 1;
        if ((int) ($effects['bounce_count'] ?? 0) > 0) {
            $targetCount = min($aliveMonsterCount, max(1, (int) $effects['bounce_count']));
        }
        if ((int) ($effects['pierce_count'] ?? 0) > 0) {
            $targetCount = min($aliveMonsterCount, max(1, (int) $effects['pierce_count']));
        }

        $expectedDamage = $this->estimatedSkillHit($skill, $charAttack) * $targetCount;
        if ($totalMonsterHp > 0) {
            $expectedDamage = min($expectedDamage, (float) $totalMonsterHp);
        }

        $manaPenalty = max(0, (int) $skill['mana_cost']) * 0.35;
        $cooldownPenalty = max(0, (int) ($skill['cooldown'] ?? 0)) * 1.25;

        return $expectedDamage - $manaPenalty - $cooldownPenalty;
    }
}
