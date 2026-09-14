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
                skillDamage: 20,
                isCrit: true,
                charCritDamage: 2.0,
            )
        );

        $this->assertSame(60, $dealt);
        $this->assertSame(440, $updated[0]['hp']);
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
}
