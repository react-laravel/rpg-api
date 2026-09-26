<?php

namespace App\Support\Game;

use App\Models\Game\GameSkillDefinition;

/**
 * 学习技能花铜币，不再花技能点。专精对换由调用方把费用改成 0。
 */
final class SkillLearnCost
{
    public static function copper(GameSkillDefinition $skill): int
    {
        $level = max(1, (int) ($skill->unlock_level ?? 1));
        $weight = max(1, (int) ($skill->skill_points_cost ?? 1));
        $perLevel = max(1, (int) config('game.skill_learn_copper_per_level', 80));

        return $perLevel * $level * $weight;
    }
}
