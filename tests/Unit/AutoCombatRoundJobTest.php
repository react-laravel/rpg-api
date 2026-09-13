<?php

namespace Tests\Unit;

use App\Jobs\Game\AutoCombatRoundJob;
use PHPUnit\Framework\TestCase;

class AutoCombatRoundJobTest extends TestCase
{
    public function test_wait_seconds_are_zero_without_next_tick_timestamp(): void
    {
        $this->assertSame(0, AutoCombatRoundJob::waitSecondsBeforeNextTick([], 1_000));
    }

    public function test_wait_seconds_are_zero_when_next_tick_is_due(): void
    {
        $this->assertSame(
            0,
            AutoCombatRoundJob::waitSecondsBeforeNextTick(['next_round_at' => 999], 1_000)
        );
    }

    public function test_wait_seconds_keep_short_delays_inside_the_combat_interval(): void
    {
        $this->assertSame(
            2,
            AutoCombatRoundJob::waitSecondsBeforeNextTick(['next_round_at' => 1_002], 1_000)
        );
    }

    public function test_stale_far_future_timestamp_is_treated_as_stuck_and_runs_now(): void
    {
        $this->assertSame(
            0,
            AutoCombatRoundJob::waitSecondsBeforeNextTick(['next_round_at' => 1_060], 1_000)
        );
    }
}
