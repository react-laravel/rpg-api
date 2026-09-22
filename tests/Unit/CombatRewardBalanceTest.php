<?php

namespace Tests\Unit;

use App\Services\Game\Combat\CombatEffectApplier;
use App\Services\Game\Combat\CombatRewardCalculator;
use Tests\TestCase;

class CombatRewardBalanceTest extends TestCase
{
    public function test_difficulty_scales_experience_only_once_and_ignores_old_corpses(): void
    {
        config(['game.copper_drop.chance' => 1.0]);
        [$xp, $copper] = (new CombatRewardCalculator)->calculateRoundDeathRewards([
            ['hp' => 0, 'experience' => 134 * 14, 'reward_layer' => 41],
            ['hp' => 0, 'experience' => 99999, 'reward_layer' => 41],
            ['hp' => 10, 'experience' => 99999, 'reward_layer' => 41],
        ], [10, 0, 20], ['reward' => 14]);
        $this->assertSame(1876, $xp);
        $this->assertSame(574, $copper);
    }

    public function test_small_attacks_chip_low_defense_and_slow_can_zero_that_chip(): void
    {
        $effects = new CombatEffectApplier;
        $this->assertSame(0, $effects->calculateMonsterCounterDamage([['hp' => 10, 'attack' => 0]], 5));
        $this->assertSame(1, $effects->calculateMonsterCounterDamage([['hp' => 10, 'attack' => 1]], 5));
        $this->assertSame(1, $effects->calculateMonsterCounterDamage([['hp' => 10, 'attack' => 2]], 5));
        $this->assertSame(0, $effects->calculateMonsterCounterDamage([['hp' => 10, 'attack' => 2, 'slow_ticks' => 2]], 5));
        $this->assertSame(1, $effects->calculateMonsterCounterDamage([['hp' => 10, 'attack' => 1]], 100));
        $this->assertSame(1, $effects->calculateMonsterCounterDamage([['hp' => 10, 'attack' => 24]], 1000));
        $this->assertSame(0, $effects->calculateMonsterCounterDamage([['hp' => 10, 'attack' => 24, 'freeze_ticks' => 1]], 1000));
    }
}
