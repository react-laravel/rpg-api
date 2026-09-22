<?php

namespace App\Services\Game;

use App\Models\Game\GameCharacter;
use App\Services\Game\Combat\CombatDamageCalculator;
use App\Services\Game\Combat\CombatEffectApplier;
use App\Services\Game\Combat\CombatRewardCalculator;
use App\Services\Game\Combat\CombatSkillSelector;
use App\Services\Game\Combat\FamiliarCombat;
use App\Services\Game\DTOs\DamageContext;
use App\Services\Game\DTOs\RoundDetailsContext;
use App\Support\Game\Familiar;
use App\Support\Game\RpgAssetIconNormalizer;

/**
 * 单次战斗推进处理器：技能选择、目标选择、伤害计算、反击、奖励结算
 */
class CombatRoundProcessor
{
    public function __construct(
        private CombatSkillSelector $skillSelector = new CombatSkillSelector,
        private CombatDamageCalculator $damageCalculator = new CombatDamageCalculator,
        private CombatRewardCalculator $rewardCalculator = new CombatRewardCalculator,
        private CombatEffectApplier $effectApplier = new CombatEffectApplier,
        private FamiliarCombat $familiarCombat = new FamiliarCombat
    ) {}

    /**
     * 处理一次战斗推进(支持多怪物)
     *
     * @return array{round_damage_dealt: int, round_damage_taken: int, new_monster_hp: int, new_char_hp: int, new_char_mana: int, defeat: bool, has_alive_monster: bool, skills_used_this_round: array, new_cooldowns: array, new_skills_aggregated: array, monsters_updated: array, slots_where_monster_died_this_round: array<int>, experience_gained: int, copper_gained: int, round_details: array}
     */
    public function processOneRound(
        GameCharacter $character,
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
        $buffs = is_array($character->combat_buffs ?? null) ? $character->combat_buffs : [];

        [$monsters, $burnDamageDealt] = $this->effectApplier->tickMonsterStatuses($monsters);
        $buffs = $this->effectApplier->tickCharacterBuffs($buffs);
        $character->combat_monsters = $monsters;
        $character->combat_buffs = $buffs === [] ? null : $buffs;

        $aliveMonstersAtStart = $this->getAliveMonsters($monsters);
        $monstersKilledThisRound = 0;
        $hpAtRoundStart = $this->getMonsterHpSnapshot($monsters);

        $skillResult = $this->skillSelector->resolveRoundSkill(
            $character,
            $requestedSkillIds,
            $currentMana,
            $skillCooldowns
        );
        $currentMana = $skillResult['mana'];
        $isAoeSkill = $skillResult['is_aoe'];
        $skillDamage = $skillResult['skill_damage'];
        $skillsUsedThisRound = $skillResult['skills_used_this_round'];
        $newCooldowns = $skillResult['new_cooldowns'];
        $castEffects = is_array($skillResult['cast_effects'] ?? null) ? $skillResult['cast_effects'] : [];
        $isDefensive = (bool) ($skillResult['is_defensive'] ?? false);

        if ($isDefensive) {
            $buffs = $this->effectApplier->applyShieldBuff($buffs, $castEffects);
            $skillDamage = 0;
        }

        $critRate = min(0.95, $charCritRate + (float) ($castEffects['crit_bonus'] ?? 0));
        $critDamage = $charCritDamage + (float) ($castEffects['crit_damage_bonus'] ?? 0);
        $isCrit = (rand(1, 100) / 100) <= $critRate;

        [$targetMonsters, $damageRatios] = $this->effectApplier->resolveTargetsWithFalloff(
            $monsters,
            $isAoeSkill,
            $castEffects,
            $this->damageCalculator
        );

        if (! empty($castEffects['chain_on_crit']) && $isCrit && $targetMonsters !== []) {
            $chainTarget = $this->damageCalculator->selectChainTarget($monsters, $targetMonsters);
            if ($chainTarget !== null) {
                $targetMonsters[] = $chainTarget;
                $damageRatios[] = (float) ($castEffects['chain_ratio'] ?? 0.5);
            }
        }

        $useAoe = $isAoeSkill && count($targetMonsters) > 1
            && (int) ($castEffects['bounce_count'] ?? 0) <= 0
            && (int) ($castEffects['pierce_count'] ?? 0) <= 0
            && (float) ($castEffects['single_target_ratio'] ?? 0) <= 0;

        $skillTargetPositions = $this->damageCalculator->getSkillTargetPositions($targetMonsters);

        $defenseReduction = config('game.combat.defense_reduction', 0.5);
        [$baseAttackDamage, $critDamageAmount] = $this->damageCalculator->computeBaseAttackDamage(
            $targetMonsters,
            $isDefensive ? 0 : $skillDamage,
            $charAttack,
            $critDamage,
            $isCrit && ! $isDefensive,
            $defenseReduction
        );

        $firstTarget = $targetMonsters === [] ? null : reset($targetMonsters);
        $firstTargetDefense = is_array($firstTarget) ? (int) ($firstTarget['defense'] ?? 0) : 0;
        $skillHitForLog = (! $isDefensive && $skillDamage > 0)
            ? $this->damageCalculator->hitAfterDefense($charAttack, $skillDamage, $firstTargetDefense, $defenseReduction)
            : 0;

        $aoeDamageAmount = 0;
        if ($useAoe) {
            $aoeMultiplier = config('game.combat.aoe_damage_multiplier', 0.7);
            $targetCount = count($targetMonsters);
            $referenceHit = $skillHitForLog > 0 ? $skillHitForLog : $baseAttackDamage;
            if ($targetCount > 1) {
                $aoeDamageAmount = (int) ($referenceHit * (1 - $aoeMultiplier) * $targetCount);
            }
        }

        $totalDamageDealt = $burnDamageDealt;
        $monstersUpdated = $monsters;

        if (! $isDefensive) {
            $attackSkillDamage = $skillsUsedThisRound === [] ? 0 : $skillDamage;
            [$monstersUpdated, $hitDamage] = $this->damageCalculator->applyCharacterDamageToMonsters(
                DamageContext::fromParams(
                    monsters: $monsters,
                    targetMonsters: $targetMonsters,
                    charAttack: $charAttack,
                    skillDamage: $attackSkillDamage,
                    isCrit: $isCrit,
                    charCritDamage: $critDamage,
                    useAoe: $useAoe,
                    nonCritBonus: (float) ($castEffects['non_crit_bonus'] ?? 0),
                    slowedDamageBonus: (float) ($castEffects['slowed_damage_bonus'] ?? 0),
                    targetDamageRatios: $damageRatios,
                )
            );
            $totalDamageDealt += $hitDamage;

            if ($skillsUsedThisRound !== [] && $attackSkillDamage > 0) {
                $monstersUpdated = $this->effectApplier->applyHitStatuses(
                    $monstersUpdated,
                    $targetMonsters,
                    $castEffects,
                    $attackSkillDamage,
                    $charAttack
                );
            }
        } else {
            foreach ($monstersUpdated as $idx => $m) {
                if (is_array($m)) {
                    $monstersUpdated[$idx]['damage_taken'] = -1;
                    $monstersUpdated[$idx]['was_attacked'] = false;
                }
            }
        }

        $pet = $this->advanceFamiliar($character, $skillsUsedThisRound, $monstersUpdated, $hpAtRoundStart);
        $monstersUpdated = $pet['monsters'];
        $totalDamageDealt += $pet['damage'];

        $slotsWhereMonsterDiedThisRound = [];
        foreach ($monstersUpdated as $idx => $m) {
            if (! is_array($m)) {
                continue;
            }
            if (($hpAtRoundStart[$idx] ?? 0) > 0 && ($m['hp'] ?? 0) <= 0) {
                $monstersKilledThisRound++;
                $slotsWhereMonsterDiedThisRound[] = $idx;
            }
        }

        $counter = $this->familiarCombat->applyCounterstrikes(
            $monstersUpdated,
            $charDefense,
            $pet['bonuses']['has_charm'] ? $pet['pet'] : null
        );
        $petState = $counter['pet'];
        if ($pet['bonuses']['has_charm']) {
            $character->pet = $petState;
        }
        $incoming = $counter['player'];
        $reflected = 0;
        $manaRestored = 0;
        $shieldAbsorbed = 0;
        $shieldBroke = false;
        $shieldMaxHp = (int) ($buffs['shield_max_hp'] ?? $buffs['shield_hp'] ?? 0);
        if ($incoming > 0) {
            $incomingBefore = $incoming;
            $shieldHpBefore = (int) ($buffs['shield_hp'] ?? 0);
            [$incoming, $buffs, $reflected, $manaRestored] = $this->effectApplier->absorbWithShield(
                $incoming,
                $buffs,
                (int) ($charStats['max_mana'] ?? 0)
            );
            $shieldAbsorbed = max(0, $incomingBefore - $incoming);
            $shieldBroke = $shieldHpBefore > 0 && (int) ($buffs['shield_hp'] ?? 0) <= 0 && $shieldAbsorbed > 0;
        }
        if ($reflected > 0) {
            [$monstersUpdated, $reflectDealt] = $this->applyFlatDamageToAlive($monstersUpdated, $reflected);
            $totalDamageDealt += $reflectDealt;
        }
        if ($manaRestored > 0) {
            $currentMana = min((int) ($charStats['max_mana'] ?? $currentMana), $currentMana + $manaRestored);
        }

        $charHp -= $incoming;

        $character->combat_monsters = $monstersUpdated;
        $character->combat_buffs = $buffs === [] ? null : $buffs;
        $newTotalHp = array_sum(array_column(array_filter($monstersUpdated, 'is_array'), 'hp'));

        $newSkillsAggregated = $this->aggregateSkillsUsed($skillsUsedThisRound, $skillsUsedAggregated);
        $hasAliveMonster = $this->hasAliveMonster($monstersUpdated);

        [$totalExperience, $totalCopper] = $this->rewardCalculator->calculateRoundDeathRewards(
            $monstersUpdated,
            $hpAtRoundStart,
            $difficulty
        );

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
                skillDamage: $skillHitForLog,
                critDamageAmount: $critDamageAmount,
                aoeDamageAmount: $aoeDamageAmount,
                totalDamageDealt: $totalDamageDealt,
                defenseReduction: $defenseReduction,
                totalMonsterDamage: $incoming,
                aliveMonsterCount: count($aliveMonstersAtStart),
                monstersKilledThisRound: $monstersKilledThisRound,
                isCrit: $isCrit,
                useAoe: $useAoe,
                difficulty: $difficulty
            )
        );

        return [
            'round_damage_dealt' => $totalDamageDealt,
            'round_damage_taken' => $incoming,
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
            'shield' => $this->effectApplier->summarizeShield($buffs, $shieldAbsorbed, $shieldBroke, $shieldMaxHp),
            'pet' => $character->pet,
        ];
    }

    /**
     * 学会诱惑之光后，宝宝跟着打架。召唤只在它倒下时把血补满，击杀经验记在它自己身上。
     *
     * @param  array<int, array<string, mixed>>  $skillsUsedThisRound
     * @param  array<int, array<string, mixed>|null>  $monstersUpdated
     * @param  array<int, int>  $hpAtRoundStart
     * @return array{monsters: array<int, array<string, mixed>|null>, damage: int, pet: array<string, mixed>|null, bonuses: array{has_charm: bool, min_level: int, cap: int, attack_bonus: float, hp_bonus: float}}
     */
    private function advanceFamiliar(GameCharacter $character, array $skillsUsedThisRound, array $monstersUpdated, array $hpAtRoundStart): array
    {
        $bonuses = Familiar::bonusesFromSkills($character->skills()->with('skill')->get());
        $empty = ['monsters' => $monstersUpdated, 'damage' => 0, 'pet' => is_array($character->pet) ? $character->pet : null, 'bonuses' => $bonuses];
        if (! $bonuses['has_charm']) {
            return $empty;
        }

        $pet = is_array($character->pet) ? $character->pet : null;
        $castCharm = ($skillsUsedThisRound[0]['effect_key'] ?? '') === 'charm-light';
        if ($castCharm || ($pet !== null && (int) ($pet['hp'] ?? 0) > 0)) {
            $pet = Familiar::summon(
                $pet,
                (int) $character->level,
                $bonuses['min_level'],
                $bonuses['cap'],
                $bonuses['attack_bonus'],
                $bonuses['hp_bonus']
            );
        }
        if ($pet === null || (int) ($pet['hp'] ?? 0) <= 0) {
            $empty['pet'] = $pet;

            return $empty;
        }

        $before = $monstersUpdated;
        [$monstersUpdated, $dealt] = $this->familiarCombat->attack($monstersUpdated, $pet);
        if ($dealt > 0) {
            $xp = 0;
            foreach ($monstersUpdated as $idx => $monster) {
                if (! is_array($monster)) {
                    continue;
                }
                if (($hpAtRoundStart[$idx] ?? 0) > 0 && (int) ($monster['hp'] ?? 0) <= 0 && (int) ($before[$idx]['hp'] ?? 0) > 0) {
                    $xp += max(1, (int) ($monster['experience'] ?? 1));
                }
            }
            $pet = Familiar::grantXp(
                $pet,
                $xp,
                (int) $character->level,
                $bonuses['cap'],
                $bonuses['attack_bonus'],
                $bonuses['hp_bonus']
            );
        }

        return ['monsters' => $monstersUpdated, 'damage' => $dealt, 'pet' => $pet, 'bonuses' => $bonuses];
    }

    /**
     * @param  array<int, array<string, mixed>|null>  $monsters
     * @return array{0: array<int, array<string, mixed>|null>, 1: int}
     */
    private function applyFlatDamageToAlive(array $monsters, int $damage): array
    {
        if ($damage <= 0) {
            return [$monsters, 0];
        }

        $dealt = 0;
        foreach ($monsters as $idx => $m) {
            if (! is_array($m) || ($m['hp'] ?? 0) <= 0) {
                continue;
            }
            $actual = min($damage, (int) $m['hp']);
            $monsters[$idx]['hp'] = (int) $m['hp'] - $actual;
            $monsters[$idx]['damage_taken'] = max(0, (int) ($monsters[$idx]['damage_taken'] ?? 0)) + $actual;
            $monsters[$idx]['was_attacked'] = true;
            $dealt += $actual;
            break;
        }

        return [$monsters, $dealt];
    }

    /**
     * @param  array<int, array<string, mixed>>  $monsters
     * @return array<int, array<string, mixed>>
     */
    private function getAliveMonsters(array $monsters): array
    {
        return array_filter($monsters, fn ($m) => is_array($m) && ($m['hp'] ?? 0) > 0);
    }

    /**
     * @param  array<int, array<string, mixed>>  $monsters
     * @return array<int, int>
     */
    private function getMonsterHpSnapshot(array $monsters): array
    {
        $hpAtRoundStart = [];
        foreach ($monsters as $idx => $m) {
            $hpAtRoundStart[$idx] = is_array($m) ? ($m['hp'] ?? 0) : 0;
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
            if (is_array($m) && ($m['hp'] ?? 0) > 0) {
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
            if (is_array($m) && ($m['hp'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }
}
