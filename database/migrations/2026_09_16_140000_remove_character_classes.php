<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $warriorRangerSkillIds = DB::table('game_skill_definitions')
            ->whereIn('class_restriction', ['warrior', 'ranger'])
            ->pluck('id');

        if ($warriorRangerSkillIds->isNotEmpty()) {
            DB::table('game_character_skills')
                ->whereIn('skill_id', $warriorRangerSkillIds)
                ->delete();
            DB::table('game_skill_definitions')
                ->whereIn('id', $warriorRangerSkillIds)
                ->delete();
        }

        Schema::table('game_characters', function (Blueprint $table) {
            if (Schema::hasColumn('game_characters', 'class')) {
                $table->dropColumn('class');
            }
        });

        Schema::table('game_skill_definitions', function (Blueprint $table) {
            if (Schema::hasColumn('game_skill_definitions', 'class_restriction')) {
                $table->dropColumn('class_restriction');
            }
        });

        Schema::table('game_combat_logs', function (Blueprint $table) {
            if (Schema::hasColumn('game_combat_logs', 'character_class')) {
                $table->dropColumn('character_class');
            }
        });
    }

    public function down(): void
    {
        Schema::table('game_characters', function (Blueprint $table) {
            if (! Schema::hasColumn('game_characters', 'class')) {
                $table->enum('class', ['warrior', 'mage', 'ranger'])->default('mage')->comment('职业');
            }
        });

        Schema::table('game_skill_definitions', function (Blueprint $table) {
            if (! Schema::hasColumn('game_skill_definitions', 'class_restriction')) {
                $table->enum('class_restriction', ['warrior', 'mage', 'ranger', 'all'])->default('all')->comment('职业限制');
            }
        });

        Schema::table('game_combat_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('game_combat_logs', 'character_class')) {
                $table->string('character_class', 20)->nullable()->comment('角色职业');
            }
        });
    }
};
