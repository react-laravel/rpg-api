<?php

namespace App\Services\Game\Combat;

use App\Models\Game\GameCharacter;
use App\Models\Game\GameCharacterSkill;

/**
 * 战斗技能选择器：智能选择最佳技能
 */
class CombatSkillSelector
{
    /**
     * 解析本回合使用的技能(蓝量、冷却、单体/群体)
     * 智能选择：根据怪物血量和数量、技能伤害和消耗来决定使用最佳技能
     *
     * @return array{mana: int, is_aoe: bool, skill_damage: int, skills_used_this_round: array, new_cooldowns: array}
     */
    public function resolveRoundSkill(
        GameCharacter $character,
        ?array $requestedSkillIds,
        int $currentRound,
        int $currentMana,
        array $skillCooldowns
    ): array {
        $isAoeSkill = false;
        $skillDamage = 0;
        $skillsUsedThisRound = [];
        $newCooldowns = $skillCooldowns;

        $learnedSkills = $character->skills()
            ->with('skill')
            ->get()
            ->filter(fn ($cs) => $cs->skill !== null);

        $activeSkills = $learnedSkills->filter(fn ($cs) => $cs->skill->type === 'active');
        $passiveSkills = $learnedSkills->filter(fn ($cs) => $cs->skill->type === 'passive');

        // 若前端指定了自动施法技能列表，只从该列表中选技能；被动强化仍按已学习技能自动生效
        if ($requestedSkillIds !== null && $requestedSkillIds !== []) {
            $allowedIds = array_flip($requestedSkillIds);
            $activeSkills = $activeSkills->filter(fn ($cs) => isset($allowedIds[$cs->skill->id]));
        }

        // 获取当前怪物信息用于智能选择
        $monsters = $character->combat_monsters ?? [];
        $aliveMonsters = array_filter($monsters, fn ($m) => ($m['hp'] ?? 0) > 0);
        $aliveMonsterCount = count($aliveMonsters);
        $lowHpMonsters = array_filter($aliveMonsters, fn ($m) => $m['hp'] > 0 && $m['hp'] <= ($m['max_hp'] ?? 100) * 0.3);
        $lowHpMonsterCount = count($lowHpMonsters);
        $totalMonsterHp = array_sum(array_column($aliveMonsters, 'hp'));

        // 角色基础攻击力
        $charStats = $character->getCombatStats();
        $charAttack = $charStats['attack'];

        // 过滤出可用的技能
        $availableSkills = [];
        foreach ($activeSkills as $charSkill) {
            /** @var GameCharacterSkill $charSkill */
            $skill = $charSkill->skill;
            $cooldownEnd = $newCooldowns[$skill->id] ?? 0;

            if ($currentMana >= $skill->mana_cost && $cooldownEnd <= $currentRound) {
                $passiveEffects = $this->getPassiveEffectsForSkill($skill, $passiveSkills);
                $isAoe = ($skill->target_type ?? 'single') === 'all';
                $damage = (int) ($skill->damage ?? $skill->base_damage ?? 0);
                if (($passiveEffects['damage_bonus'] ?? 0) > 0) {
                    $damage = (int) round($damage * (1 + (float) $passiveEffects['damage_bonus']));
                }

                $availableSkills[] = [
                    'char_skill' => $charSkill,
                    'skill' => $skill,
                    'damage' => $damage,
                    'mana_cost' => (int) $skill->mana_cost,
                    'cooldown' => (int) $skill->cooldown,
                    'is_aoe' => $isAoe,
                    'passive_effects' => $passiveEffects,
                    'passive_names' => $this->getPassiveNamesForSkill($skill, $passiveSkills),
                ];
            }
        }

        if (empty($availableSkills)) {
            return $this->buildNoSkillRoundResult($currentMana, $newCooldowns);
        }

        // 智能选择最佳技能
        $selectedSkill = $this->selectOptimalSkill(
            $availableSkills,
            $aliveMonsterCount,
            $lowHpMonsterCount,
            $totalMonsterHp,
            $charAttack
        );

        if ($selectedSkill !== null) {
            $skill = $selectedSkill['skill'];
            $skillDamage = $selectedSkill['damage'];
            $currentMana -= $selectedSkill['mana_cost'];
            $newCooldowns[$skill->id] = $currentRound + $selectedSkill['cooldown'];
            $isAoeSkill = $selectedSkill['is_aoe'];
            $skillsUsedThisRound[] = [
                'skill_id' => $skill->id,
                'name' => $skill->name,
                'icon' => $skill->icon,
                'effect_key' => $skill->effect_key ?? null,
                'target_type' => $isAoeSkill ? 'all' : ($skill->target_type ?? 'single'),
                'passive_effects' => $selectedSkill['passive_effects'] ?? [],
                'passive_names' => $selectedSkill['passive_names'] ?? [],
            ];

            return [
                'mana' => $currentMana,
                'is_aoe' => $isAoeSkill,
                'skill_damage' => $skillDamage,
                'skills_used_this_round' => $skillsUsedThisRound,
                'new_cooldowns' => $newCooldowns,
            ];
        }

        return $this->buildNoSkillRoundResult($currentMana, $newCooldowns);
    }

    /**
     * 智能选择最佳技能
     */
    public function selectOptimalSkill(
        array $availableSkills,
        int $aliveMonsterCount,
        int $lowHpMonsterCount,
        int $totalMonsterHp,
        int $charAttack
    ): ?array {
        if (empty($availableSkills)) {
            return null;
        }

        if (count($availableSkills) === 1) {
            return $availableSkills[0];
        }

        $baseAttackDamage = (int) ($charAttack * 0.5);

        // 策略 1: 多目标战斗优先考虑群体技能。
        // 旧逻辑只有“3 只怪且 2 只低血量”才看 AOE，导致冰箭这类 0CD 低耗单体在多数多怪回合被反复选择，
        // 陨石术、连锁闪电、冰霜新星即使可用也很少出手。这里按“单次总期望伤害/耗蓝/冷却”综合评分。
        if ($aliveMonsterCount >= 2) {
            $aoeSkills = array_filter($availableSkills, fn ($s) => $s['is_aoe']);
            if (! empty($aoeSkills)) {
                usort($aoeSkills, fn (array $a, array $b) => $this->compareSkillsByCombatScore($a, $b, $aliveMonsterCount, $totalMonsterHp));

                return $aoeSkills[0];
            }
        }

        // 策略 2: 总血量很低时省蓝，避免在有零耗技能时浪费。
        if ($totalMonsterHp <= $charAttack * 2) {
            usort($availableSkills, function (array $firstSkill, array $secondSkill) use ($totalMonsterHp) {
                if ($firstSkill['mana_cost'] === 0 && $secondSkill['mana_cost'] > 0) {
                    return -1;
                }
                if ($secondSkill['mana_cost'] === 0 && $firstSkill['mana_cost'] > 0) {
                    return 1;
                }
                $effectiveDamageA = min((int) $firstSkill['damage'], $totalMonsterHp);
                $effectiveDamageB = min((int) $secondSkill['damage'], $totalMonsterHp);
                $efficiencyA = $firstSkill['mana_cost'] > 0 ? $effectiveDamageA / $firstSkill['mana_cost'] : $effectiveDamageA * 10;
                $efficiencyB = $secondSkill['mana_cost'] > 0 ? $effectiveDamageB / $secondSkill['mana_cost'] : $effectiveDamageB * 10;

                if (abs($efficiencyA - $efficiencyB) > 0.1) {
                    return $efficiencyB <=> $efficiencyA;
                }

                return $firstSkill['mana_cost'] <=> $secondSkill['mana_cost'];
            });

            return $availableSkills[0];
        }

        // 策略 3: 正常战斗，选择伤害最高的技能
        $skillsWithDamage = array_filter($availableSkills, fn ($s) => $s['damage'] > 0);
        if (! empty($skillsWithDamage)) {
            usort($skillsWithDamage, fn (array $a, array $b) => $this->compareSkillsByCombatScore($a, $b, $aliveMonsterCount, $totalMonsterHp));

            $bestSkill = $skillsWithDamage[0];
            $bestEfficiency = $bestSkill['mana_cost'] > 0 ? $bestSkill['damage'] / $bestSkill['mana_cost'] : $bestSkill['damage'];
            $baseEfficiency = $baseAttackDamage;

            if ($bestEfficiency >= $baseEfficiency * 0.5 || $bestSkill['damage'] > $totalMonsterHp * 0.5) {
                return $bestSkill;
            }
        }

        // 默认：使用最经济的技能
        usort($availableSkills, function ($a, $b) {
            if ($a['mana_cost'] === 0 && $b['mana_cost'] > 0) {
                return -1;
            }
            if ($b['mana_cost'] === 0 && $a['mana_cost'] > 0) {
                return 1;
            }

            return $a['mana_cost'] <=> $b['mana_cost'];
        });

        return $availableSkills[0];
    }

    /**
     * @param  array<int, int>  $cooldowns
     * @return array{mana: int, is_aoe: bool, skill_damage: int, skills_used_this_round: array, new_cooldowns: array}
     */
    public function buildNoSkillRoundResult(int $mana, array $cooldowns): array
    {
        return [
            'mana' => $mana,
            'is_aoe' => false,
            'skill_damage' => 0,
            'skills_used_this_round' => [],
            'new_cooldowns' => $cooldowns,
        ];
    }

    /**
     * 已学习的同技能线被动强化会改变主动技能数值。
     * 小火球等单体技能不能因为被动强化变成 AOE；是否群体只由主动技能 target_type 决定。
     */
    private function getPassiveEffectsForSkill(object $activeSkill, $passiveSkills): array
    {
        $effects = [];
        foreach ($passiveSkills as $charSkill) {
            $passive = $charSkill->skill;
            if (! $this->isPassiveForActiveSkill($passive, $activeSkill)) {
                continue;
            }

            foreach (($passive->effects ?? []) as $key => $value) {
                $effects[$key] = $value;
            }
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
     * 按战斗收益排序：多目标看总期望伤害，伤害接近时再看耗蓝/冷却。
     *
     * @param  array{damage: int, mana_cost: int, cooldown?: int, is_aoe?: bool}  $firstSkill
     * @param  array{damage: int, mana_cost: int, cooldown?: int, is_aoe?: bool}  $secondSkill
     */
    private function compareSkillsByCombatScore(array $firstSkill, array $secondSkill, int $aliveMonsterCount, int $totalMonsterHp): int
    {
        $firstScore = $this->calculateCombatScore($firstSkill, $aliveMonsterCount, $totalMonsterHp);
        $secondScore = $this->calculateCombatScore($secondSkill, $aliveMonsterCount, $totalMonsterHp);

        if (abs($firstScore - $secondScore) > 0.1) {
            return $secondScore <=> $firstScore;
        }

        return $this->compareSkillsByEfficiency($firstSkill, $secondSkill);
    }

    /**
     * @param  array{damage: int, mana_cost: int, cooldown?: int, is_aoe?: bool}  $skill
     */
    private function calculateCombatScore(array $skill, int $aliveMonsterCount, int $totalMonsterHp): float
    {
        $targetCount = ($skill['is_aoe'] ?? false) ? max(1, $aliveMonsterCount) : 1;
        $expectedDamage = (float) $skill['damage'] * $targetCount;
        if ($totalMonsterHp > 0) {
            $expectedDamage = min($expectedDamage, (float) $totalMonsterHp);
        }

        $manaPenalty = max(0, (int) $skill['mana_cost']) * 0.35;
        $cooldownPenalty = max(0, (int) ($skill['cooldown'] ?? 0)) * 1.25;

        return $expectedDamage - $manaPenalty - $cooldownPenalty;
    }
}
