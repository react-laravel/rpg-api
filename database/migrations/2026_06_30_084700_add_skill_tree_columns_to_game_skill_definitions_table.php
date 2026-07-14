<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_skill_definitions', function (Blueprint $table) {
            if (! Schema::hasColumn('game_skill_definitions', 'skill_stage')) {
                $table->string('skill_stage', 32)
                    ->nullable()
                    ->after('class_restriction')
                    ->comment('阶段：basic/core/defensive/special/ultimate/key_passive');
            }

            if (! Schema::hasColumn('game_skill_definitions', 'skill_line')) {
                $table->string('skill_line', 64)
                    ->nullable()
                    ->after('skill_stage')
                    ->comment('技能线标识，同线节点共享');
            }

            if (! Schema::hasColumn('game_skill_definitions', 'node_tier')) {
                $table->unsignedTinyInteger('node_tier')
                    ->nullable()
                    ->after('skill_line')
                    ->comment('节点层级：0本体/1强化/2专精');
            }

            if (! Schema::hasColumn('game_skill_definitions', 'spec_branch')) {
                $table->string('spec_branch', 1)
                    ->nullable()
                    ->after('node_tier')
                    ->comment('专精分支：a/b');
            }

            if (! Schema::hasColumn('game_skill_definitions', 'unlock_level')) {
                $table->unsignedTinyInteger('unlock_level')
                    ->default(1)
                    ->after('spec_branch')
                    ->comment('阶段解锁所需角色等级');
            }
        });
    }

    public function down(): void
    {
        Schema::table('game_skill_definitions', function (Blueprint $table) {
            $columns = ['unlock_level', 'spec_branch', 'node_tier', 'skill_line', 'skill_stage'];

            foreach ($columns as $column) {
                if (Schema::hasColumn('game_skill_definitions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
