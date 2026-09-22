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
            if (! Schema::hasColumn('game_characters', 'pet')) {
                $table->json('pet')->nullable()->after('combat_buffs')->comment('宝宝：等级、经验、生命');
            }
        });

        $skills = array_values(array_filter(
            require database_path('seeders/Game/Data/Skills/skills_mage.php'),
            fn (array $skill): bool => ($skill['skill_line'] ?? '') === 'mage_charm'
        ));

        $ids = [];
        foreach ($skills as $skill) {
            $parentRef = $skill['parent_ref'] ?? null;
            unset($skill['parent_ref'], $skill['class_restriction'], $skill['icon_prompt']);
            $now = now();
            $key = [
                'skill_line' => $skill['skill_line'],
                'node_tier' => $skill['node_tier'],
                'spec_branch' => $skill['spec_branch'] ?? null,
            ];
            $values = array_merge($skill, [
                'prerequisite_skill_id' => null,
                'prerequisite_effect_key' => null,
                'branch' => $skill['skill_stage'] ?? 'defensive',
                'tier' => (int) ($skill['node_tier'] ?? 0) + 1,
                'target_type' => $skill['target_type'] ?? 'single',
                'icon' => 'charm-light.png',
                'is_active' => true,
                'base_damage' => 0,
                'effects' => json_encode($skill['effects'] ?? [], JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
            ]);

            $existing = DB::table('game_skill_definitions')
                ->where('skill_line', $key['skill_line'])
                ->where('node_tier', $key['node_tier'])
                ->where(function ($query) use ($key) {
                    if ($key['spec_branch'] === null) {
                        $query->whereNull('spec_branch');
                    } else {
                        $query->where('spec_branch', $key['spec_branch']);
                    }
                })
                ->first();

            if ($existing) {
                DB::table('game_skill_definitions')->where('id', $existing->id)->update($values);
                $ids[$this->nodeKey($key)] = (int) $existing->id;
            } else {
                $values['created_at'] = $now;
                $ids[$this->nodeKey($key)] = (int) DB::table('game_skill_definitions')->insertGetId($values);
            }

            if ($parentRef !== null) {
                $parentId = $ids[$this->nodeKey($parentRef)] ?? null;
                if ($parentId !== null) {
                    DB::table('game_skill_definitions')->where('id', $ids[$this->nodeKey($key)])->update([
                        'prerequisite_skill_id' => $parentId,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('game_skill_definitions')->where('skill_line', 'mage_charm')->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('game_character_skills')->whereIn('skill_id', $ids)->delete();
            DB::table('game_skill_definitions')->whereIn('id', $ids)->delete();
        }

        Schema::table('game_characters', function (Blueprint $table) {
            if (Schema::hasColumn('game_characters', 'pet')) {
                $table->dropColumn('pet');
            }
        });
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function nodeKey(array $node): string
    {
        return implode('|', [
            $node['skill_line'] ?? 'mage_charm',
            (string) ($node['node_tier'] ?? ''),
            $node['spec_branch'] ?? '',
        ]);
    }
};
