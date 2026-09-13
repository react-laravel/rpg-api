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
                'cooldown' => 0,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('game_skill_definitions')
            ->where('skill_line', 'mage_fireball')
            ->where('node_tier', 0)
            ->update([
                'cooldown' => 1,
                'updated_at' => now(),
            ]);
    }
};
