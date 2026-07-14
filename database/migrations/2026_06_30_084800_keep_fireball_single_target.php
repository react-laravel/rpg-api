<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('game_skill_definitions')
            ->where('skill_line', 'mage_fireball')
            ->where('node_tier', 0)
            ->update([
                'description' => '中耗单体火焰弹，伤害高于冰箭；强化后保持单体爆发定位',
                'target_type' => 'single',
                'updated_at' => now(),
            ]);

        DB::table('game_skill_definitions')
            ->where('skill_line', 'mage_fireball')
            ->where('node_tier', 1)
            ->update([
                'description' => '单体伤害 +30%',
                'effects' => json_encode(['damage_bonus' => 0.3], JSON_UNESCAPED_UNICODE),
                'target_type' => 'single',
                'updated_at' => now(),
            ]);

        DB::table('game_skill_definitions')
            ->where('skill_line', 'mage_fireball')
            ->where('node_tier', 2)
            ->where('spec_branch', 'a')
            ->update([
                'name' => '灼烧火球',
                'description' => '命中主目标后附加 3 秒灼烧（单体持续）',
                'effects' => json_encode(['burn_duration' => 3], JSON_UNESCAPED_UNICODE),
                'target_type' => 'single',
                'updated_at' => now(),
            ]);

        DB::table('game_skill_definitions')
            ->where('skill_line', 'mage_fireball')
            ->where('node_tier', 2)
            ->where('spec_branch', 'b')
            ->update([
                'description' => '单体伤害 +40%（单体爆发）',
                'effects' => json_encode(['damage_bonus' => 0.4], JSON_UNESCAPED_UNICODE),
                'target_type' => 'single',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('game_skill_definitions')
            ->where('skill_line', 'mage_fireball')
            ->where('node_tier', 0)
            ->update([
                'description' => '中耗单体火焰弹，伤害高于冰箭；强化后可转溅射/AOE',
                'target_type' => 'single',
                'updated_at' => now(),
            ]);

        DB::table('game_skill_definitions')
            ->where('skill_line', 'mage_fireball')
            ->where('node_tier', 1)
            ->update([
                'description' => '爆炸范围 +30%',
                'effects' => json_encode(['explosion_radius_bonus' => 0.3], JSON_UNESCAPED_UNICODE),
                'target_type' => 'single',
                'updated_at' => now(),
            ]);

        DB::table('game_skill_definitions')
            ->where('skill_line', 'mage_fireball')
            ->where('node_tier', 2)
            ->where('spec_branch', 'a')
            ->update([
                'name' => '烈焰蔓延',
                'description' => '爆炸留下 3 秒火池 AOE（持续/群体）',
                'effects' => json_encode(['fire_pool_duration' => 3], JSON_UNESCAPED_UNICODE),
                'target_type' => 'all',
                'updated_at' => now(),
            ]);

        DB::table('game_skill_definitions')
            ->where('skill_line', 'mage_fireball')
            ->where('node_tier', 2)
            ->where('spec_branch', 'b')
            ->update([
                'description' => '单体伤害 +40%，无火池（单体爆发）',
                'effects' => json_encode(['damage_bonus' => 0.4, 'no_fire_pool' => true], JSON_UNESCAPED_UNICODE),
                'target_type' => 'single',
                'updated_at' => now(),
            ]);
    }
};
