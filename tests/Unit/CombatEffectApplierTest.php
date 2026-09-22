<?php

namespace Tests\Unit;

use App\Services\Game\Combat\CombatDamageCalculator;
use App\Services\Game\Combat\CombatEffectApplier;
use App\Services\Game\DTOs\DamageContext;
use Tests\TestCase;

class CombatEffectApplierTest extends TestCase
{
    public function test_skill_damage_can_crit(): void
    {
        $calculator = new CombatDamageCalculator;
        $monsters = [[
            'position' => 0,
            'hp' => 500,
            'max_hp' => 500,
            'defense' => 0,
            'name' => 'Slime',
        ]];

        [$updated, $dealt] = $calculator->applyCharacterDamageToMonsters(
            DamageContext::fromParams(
                monsters: $monsters,
                targetMonsters: $monsters,
                charAttack: 10,
                skillDamage: 300,
                isCrit: true,
                charCritDamage: 2.0,
            )
        );

        $this->assertSame(60, $dealt);
        $this->assertSame(440, $updated[0]['hp']);
    }

    public function test_damage_breakdown_keeps_auto_attack_and_skill_separate(): void
    {
        $calculator = new CombatDamageCalculator;
        [$autoAttack, $critExtra] = $calculator->computeBaseAttackDamage(
            [['defense' => 8]],
            150,
            12,
            1.5,
            false,
            0.5
        );

        $this->assertSame(8, $autoAttack);
        $this->assertSame(0, $critExtra);

        $fireballHit = $calculator->hitAfterDefense(10, 160, 0, 0.5);
        $this->assertSame(16, $fireballHit);
        $this->assertSame(10, $calculator->hitAfterDefense(10, 0, 0, 0.5));
    }

    public function test_burn_ticks_deal_damage_each_pulse(): void
    {
        $applier = new CombatEffectApplier;
        $monsters = [[
            'position' => 0,
            'hp' => 100,
            'burn_ticks' => 2,
            'burn_damage' => 15,
        ]];

        [$afterFirst, $dealt] = $applier->tickMonsterStatuses($monsters);
        $this->assertSame(15, $dealt);
        $this->assertSame(85, $afterFirst[0]['hp']);
        $this->assertSame(1, $afterFirst[0]['burn_ticks']);

        [$afterSecond, $dealt2] = $applier->tickMonsterStatuses($afterFirst);
        $this->assertSame(15, $dealt2);
        $this->assertSame(70, $afterSecond[0]['hp']);
        $this->assertArrayNotHasKey('burn_ticks', $afterSecond[0]);
    }

    public function test_frozen_monsters_do_not_counter(): void
    {
        $applier = new CombatEffectApplier;
        $damage = $applier->calculateMonsterCounterDamage([
            ['hp' => 50, 'attack' => 20, 'freeze_ticks' => 1],
            ['hp' => 50, 'attack' => 10],
        ], 0);

        $this->assertSame(10, $damage);
    }

    public function test_shield_absorbs_and_can_restore_mana_on_break(): void
    {
        $applier = new CombatEffectApplier;
        $buffs = [
            'shield_hp' => 30,
            'shield_ticks' => 3,
            'mana_restore_on_break' => 0.1,
            'reflect_on_break' => 0.5,
        ];

        [$remaining, $nextBuffs, $reflected, $mana] = $applier->absorbWithShield(50, $buffs, 200);

        $this->assertSame(20, $remaining);
        $this->assertSame([], $nextBuffs);
        $this->assertSame(15, $reflected);
        $this->assertSame(20, $mana);

        $summary = $applier->summarizeShield([], 30, true, 30);
        $this->assertSame(0, $summary['hp']);
        $this->assertSame(30, $summary['max_hp']);
        $this->assertTrue($summary['broke']);
        $this->assertSame(30, $summary['absorbed']);
    }

    public function test_summarize_shield_keeps_active_barrier(): void
    {
        $applier = new CombatEffectApplier;
        $summary = $applier->summarizeShield([
            'shield_hp' => 40,
            'shield_max_hp' => 100,
            'shield_ticks' => 5,
        ]);

        $this->assertSame(40, $summary['hp']);
        $this->assertSame(100, $summary['max_hp']);
        $this->assertSame(5, $summary['ticks']);
        $this->assertFalse($summary['broke']);
    }

    public function test_pierce_targets_apply_falloff_ratios(): void
    {
        $applier = new CombatEffectApplier;
        $calculator = new CombatDamageCalculator;
        $monsters = [
            ['position' => 0, 'hp' => 40, 'defense' => 0],
            ['position' => 1, 'hp' => 80, 'defense' => 0],
            ['position' => 2, 'hp' => 60, 'defense' => 0],
        ];

        [$targets, $ratios] = $applier->resolveTargetsWithFalloff(
            $monsters,
            false,
            ['pierce_count' => 2, 'pierce_falloff' => 0.2],
            $calculator
        );

        $this->assertCount(2, $targets);
        $this->assertSame(0, $targets[0]['position']);
        $this->assertEqualsWithDelta(1.0, $ratios[0], 0.001);
        $this->assertEqualsWithDelta(0.8, $ratios[1], 0.001);
    }

    public function test_ice_arrow_aims_at_the_uncontrolled_attacker(): void
    {
        $applier = new CombatEffectApplier;
        $calculator = new CombatDamageCalculator;
        $monsters = [
            ['position' => 0, 'hp' => 4, 'attack' => 0],
            ['position' => 1, 'hp' => 30, 'attack' => 2, 'slow_ticks' => 2],
            ['position' => 2, 'hp' => 80, 'attack' => 2],
        ];

        [$targets] = $applier->resolveTargetsWithFalloff(
            $monsters,
            false,
            ['slow_duration' => 2, 'slow_chance' => 1],
            $calculator
        );

        $this->assertCount(1, $targets);
        $this->assertSame(2, $targets[0]['position']);
    }

    public function test_aoe_single_target_ratio_still_hits_every_monster(): void
    {
        $applier = new CombatEffectApplier;
        $calculator = new CombatDamageCalculator;
        $monsters = [
            ['position' => 0, 'hp' => 800, 'defense' => 0],
            ['position' => 1, 'hp' => 400, 'defense' => 0],
            ['position' => 2, 'hp' => 600, 'defense' => 0],
        ];

        [$targets, $ratios] = $applier->resolveTargetsWithFalloff(
            $monsters,
            true,
            ['single_target_ratio' => 3.5],
            $calculator
        );

        $this->assertCount(3, $targets);
        $ratioBySlot = [];
        foreach (array_values($targets) as $i => $target) {
            $ratioBySlot[(int) $target['position']] = $ratios[$i];
        }
        $this->assertEqualsWithDelta(3.5, $ratioBySlot[1], 0.001);
        $this->assertEqualsWithDelta(0.7, $ratioBySlot[0], 0.001);
        $this->assertEqualsWithDelta(0.7, $ratioBySlot[2], 0.001);

        [$updated, $dealt] = $calculator->applyCharacterDamageToMonsters(
            DamageContext::fromParams(
                monsters: $monsters,
                targetMonsters: $targets,
                charAttack: 100,
                skillDamage: 100,
                targetDamageRatios: $ratios,
            )
        );

        $this->assertSame(350, $updated[1]['damage_taken']);
        $this->assertSame(70, $updated[0]['damage_taken']);
        $this->assertSame(70, $updated[2]['damage_taken']);
        $this->assertSame(490, $dealt);
    }
}
