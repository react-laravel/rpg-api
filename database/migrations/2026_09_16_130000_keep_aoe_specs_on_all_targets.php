<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $updates = [
            [
                'skill_line' => 'mage_frost_nova',
                'node_tier' => 2,
                'spec_branch' => 'b',
                'description' => '主目标 300% 伤害，其余目标仍受全体伤害',
            ],
            [
                'skill_line' => 'mage_meteor',
                'node_tier' => 2,
                'spec_branch' => 'b',
                'description' => '主目标 350% 伤害，其余目标仍受全体伤害',
            ],
            [
                'skill_line' => 'mage_cataclysm',
                'node_tier' => 2,
                'spec_branch' => 'b',
                'description' => '主目标 450% 伤害，其余目标仍受全体伤害',
            ],
        ];

        foreach ($updates as $update) {
            DB::table('game_skill_definitions')
                ->where('skill_line', $update['skill_line'])
                ->where('node_tier', $update['node_tier'])
                ->where('spec_branch', $update['spec_branch'])
                ->update([
                    'description' => $update['description'],
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        $updates = [
            [
                'skill_line' => 'mage_frost_nova',
                'node_tier' => 2,
                'spec_branch' => 'b',
                'description' => '改为单体，伤害变为 300%',
            ],
            [
                'skill_line' => 'mage_meteor',
                'node_tier' => 2,
                'spec_branch' => 'b',
                'description' => '改为单体，主目标伤害 350%',
            ],
            [
                'skill_line' => 'mage_cataclysm',
                'node_tier' => 2,
                'spec_branch' => 'b',
                'description' => '改为单体，伤害变为 450%',
            ],
        ];

        foreach ($updates as $update) {
            DB::table('game_skill_definitions')
                ->where('skill_line', $update['skill_line'])
                ->where('node_tier', $update['node_tier'])
                ->where('spec_branch', $update['spec_branch'])
                ->update([
                    'description' => $update['description'],
                    'updated_at' => now(),
                ]);
        }
    }
};
