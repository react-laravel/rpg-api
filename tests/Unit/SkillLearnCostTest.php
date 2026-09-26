<?php

namespace Tests\Unit;

use App\Models\Game\GameSkillDefinition;
use App\Support\Game\SkillLearnCost;
use Tests\TestCase;

class SkillLearnCostTest extends TestCase
{
    public function test_cost_is_eighty_copper_times_unlock_level_and_weight(): void
    {
        $skill = new GameSkillDefinition;
        $skill->unlock_level = 5;
        $skill->skill_points_cost = 2;

        $this->assertSame(800, SkillLearnCost::copper($skill));
    }
}
