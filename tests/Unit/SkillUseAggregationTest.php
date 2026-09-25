<?php

namespace Tests\Unit;

use App\Services\Game\CombatRoundProcessor;
use ReflectionMethod;
use Tests\TestCase;

class SkillUseAggregationTest extends TestCase
{
    public function test_repeated_casts_stay_on_one_entry(): void
    {
        $processor = new CombatRoundProcessor;
        $method = new ReflectionMethod($processor, 'aggregateSkillsUsed');

        $once = $method->invoke($processor, [
            ['skill_id' => 5, 'name' => '小火球', 'icon' => null, 'effect_key' => 'fireball'],
        ], []);
        $twice = $method->invoke($processor, [
            ['skill_id' => 5, 'name' => '小火球', 'icon' => null, 'effect_key' => 'fireball'],
        ], $once);

        $this->assertCount(1, $twice);
        $this->assertSame(5, $twice[0]['skill_id']);
        $this->assertSame(2, $twice[0]['use_count']);
    }

    public function test_a_list_of_duplicate_casts_collapses_by_skill_id(): void
    {
        $processor = new CombatRoundProcessor;
        $method = new ReflectionMethod($processor, 'aggregateSkillsUsed');

        $fixed = $method->invoke($processor, [
            ['skill_id' => 5, 'name' => '小火球', 'icon' => null],
        ], [
            ['skill_id' => 5, 'name' => '小火球', 'icon' => null, 'use_count' => 1],
            ['skill_id' => 5, 'name' => '小火球', 'icon' => null, 'use_count' => 1],
            ['skill_id' => 7, 'name' => '冰箭', 'icon' => null, 'use_count' => 1],
        ]);

        $this->assertCount(2, $fixed);
        $this->assertSame(3, $fixed[0]['use_count']);
        $this->assertSame(7, $fixed[1]['skill_id']);
        $this->assertSame(1, $fixed[1]['use_count']);
    }
}
