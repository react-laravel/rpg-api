<?php

namespace Tests\Unit;

use App\Services\Game\Combat\CombatSkillSelector;
use PHPUnit\Framework\TestCase;

class CombatSkillSelectorTest extends TestCase
{
    public function test_empty_request_disables_all_active_skills(): void
    {
        $skills = collect([
            (object) ['skill' => (object) ['id' => 1]],
            (object) ['skill' => (object) ['id' => 2]],
        ]);

        $filtered = (new CombatSkillSelector())->restrictActiveSkills($skills, []);

        $this->assertCount(0, $filtered);
    }

    public function test_requested_ids_keep_only_allowed_skills(): void
    {
        $skills = collect([
            (object) ['skill' => (object) ['id' => 1]],
            (object) ['skill' => (object) ['id' => 2]],
        ]);

        $filtered = (new CombatSkillSelector())->restrictActiveSkills($skills, [2]);

        $this->assertSame([2], $filtered->pluck('skill.id')->all());
    }

    public function test_null_request_keeps_all_active_skills(): void
    {
        $skills = collect([
            (object) ['skill' => (object) ['id' => 1]],
            (object) ['skill' => (object) ['id' => 2]],
        ]);

        $filtered = (new CombatSkillSelector())->restrictActiveSkills($skills, null);

        $this->assertCount(2, $filtered);
    }

    public function test_remaining_cooldowns_keep_ticks_when_no_legacy_cursor(): void
    {
        $selector = new CombatSkillSelector;

        $this->assertSame(
            [7 => 3, 9 => 1],
            $selector->remainingCooldowns([7 => 3, 8 => 0, 9 => 1], 0)
        );
    }

    public function test_remaining_cooldowns_convert_legacy_end_round_values(): void
    {
        $selector = new CombatSkillSelector;

        $this->assertSame(
            [7 => 3],
            $selector->remainingCooldowns([7 => 8, 8 => 5], 5)
        );
    }

    public function test_tick_remaining_cooldowns_decrements_and_drops_ready_skills(): void
    {
        $selector = new CombatSkillSelector;

        $this->assertSame(
            [7 => 2],
            $selector->tickRemainingCooldowns([7 => 3, 8 => 1])
        );
    }

    public function test_remaining_cooldowns_treat_null_as_empty(): void
    {
        $selector = new CombatSkillSelector;

        $this->assertSame([], $selector->remainingCooldowns(null));
        $this->assertSame([], $selector->tickRemainingCooldowns(null));
    }
}
