<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_characters', function (Blueprint $table) {
            if (! Schema::hasColumn('game_characters', 'combat_buffs')) {
                $table->json('combat_buffs')
                    ->nullable()
                    ->after('combat_skill_cooldowns')
                    ->comment('战斗临时增益(护盾等JSON)');
            }
        });

        // 冷却单位：战斗推进次数（非墙钟秒）
        DB::table('game_skill_definitions')
            ->where('skill_line', 'mage_fireball')
            ->where('node_tier', 0)
            ->update([
                'description' => '中耗单体火焰弹，无冷却，可每拍释放；伤害高于冰箭',
                'cooldown' => 0,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('game_characters', function (Blueprint $table) {
            if (Schema::hasColumn('game_characters', 'combat_buffs')) {
                $table->dropColumn('combat_buffs');
            }
        });
    }
};
