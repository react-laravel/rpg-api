<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('game_skill_definitions')
            ->where('effect_key', 'charm-light')
            ->update([
                'description' => '召一只宝宝。它每拍随机打一只怪，击杀给它自己经验，最高 7 级。倒下后再次施放会按召唤等级重新召出。怪物反击在你和宝宝之间随机选目标',
            ]);
    }

    public function down(): void
    {
        DB::table('game_skill_definitions')
            ->where('effect_key', 'charm-light')
            ->update([
                'description' => '召一只宝宝。它每拍随机打一只怪，击杀给它自己经验，最高 7 级。倒下后再次施放会原等级复活。怪物反击在你和宝宝之间随机选目标',
            ]);
    }
};
