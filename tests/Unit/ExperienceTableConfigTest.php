<?php

namespace Tests\Unit;

use Tests\TestCase;

class ExperienceTableConfigTest extends TestCase
{
    public function test_experience_table_reaches_level_200_with_quadratic_curve(): void
    {
        $table = config('game.experience_table');
        $multiplier = (int) config('game.experience_fallback_multiplier');
        $maxLevel = (int) config('game.max_character_level');

        $this->assertSame(200, $maxLevel);
        $this->assertSame(50, $multiplier);
        $this->assertSame(0, $table[1]);
        $this->assertArrayHasKey(200, $table);
        $this->assertArrayNotHasKey(201, $table);

        // Legacy checkpoints must stay identical so existing characters do not shift.
        $this->assertSame(50, $table[2]);
        $this->assertSame(250, $table[3]);
        $this->assertSame(16417500, $table[100]);

        $expected = 0;
        for ($level = 1; $level < $maxLevel; $level++) {
            $expected += $multiplier * ($level ** 2);
            $this->assertSame(
                $expected,
                $table[$level + 1],
                "Cumulative XP mismatch at level ".($level + 1)
            );
        }

        $this->assertSame(132335000, $table[200]);
    }
}
