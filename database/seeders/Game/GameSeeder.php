<?php

namespace Database\Seeders\Game;

use App\Models\Game\GameItemDefinition;
use App\Models\Game\GameMapDefinition;
use App\Models\Game\GameMonsterDefinition;
use App\Models\Game\GameSkillDefinition;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GameSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->seedItemDefinitions();
        $this->seedSkillDefinitions();
        $this->seedMonsterDefinitions();
        $this->seedMapDefinitions();
    }

    private function seedItemDefinitions(): void
    {
        DB::table('game_item_definitions')->truncate();

        $items = require __DIR__ . '/Data/items.php';

        foreach ($items as $item) {
            $assetKey = $item['asset_key'] ?? ('item_' . $item['id']);
            unset($item['asset_key']);

            GameItemDefinition::create(array_merge($item, [
                'icon' => $assetKey . '.png',
                'is_active' => true,
            ]));
        }
    }

    private function seedSkillDefinitions(): void
    {
        $skillsDir = __DIR__ . '/Data/Skills';
        $skillFiles = [
            'skills_warrior.php',
            'skills_mage.php',
            'skills_ranger.php',
        ];
        $skills = [];
        foreach ($skillFiles as $file) {
            $path = $skillsDir . '/' . $file;
            if (file_exists($path)) {
                $skills = array_merge($skills, require $path);
            }
        }

        $pendingParents = [];

        foreach ($skills as $skill) {
            $parentRef = $skill['parent_ref'] ?? null;
            unset($skill['parent_ref']);

            $isActiveSkill = ($skill['type'] ?? 'active') === 'active';
            $baseDamage = $isActiveSkill
                ? (int) ($skill['base_damage'] ?? max(10, (int) ($skill['mana_cost'] ?? 0) * 2))
                : 0;

            $record = GameSkillDefinition::updateOrCreate(
                [
                    'skill_line' => $skill['skill_line'],
                    'node_tier' => $skill['node_tier'],
                    'spec_branch' => $skill['spec_branch'] ?? null,
                    'class_restriction' => $skill['class_restriction'],
                ],
                array_merge($skill, [
                    'prerequisite_skill_id' => null,
                    'prerequisite_effect_key' => null,
                    'branch' => $skill['skill_stage'] ?? null,
                    'tier' => (int) ($skill['node_tier'] ?? 0) + 1,
                    'target_type' => $skill['target_type'] ?? 'single',
                    'icon' => ! empty($skill['effect_key'])
                        ? $skill['effect_key'] . '.png'
                        : 'skill_' . strtolower(str_replace(' ', '_', $skill['name'])) . '.png',
                    'is_active' => true,
                    'base_damage' => $baseDamage,
                ])
            );

            if ($parentRef !== null) {
                $pendingParents[] = [
                    'skill_id' => $record->id,
                    'parent_ref' => $parentRef,
                ];
            }
        }

        $idByRef = GameSkillDefinition::query()
            ->whereIn('skill_line', array_unique(array_column($skills, 'skill_line')))
            ->get()
            ->keyBy(fn (GameSkillDefinition $def) => $this->skillLineKey([
                'skill_line' => $def->skill_line,
                'node_tier' => $def->node_tier,
                'spec_branch' => $def->spec_branch,
                'class_restriction' => $def->class_restriction,
            ]));

        foreach ($pendingParents as $pending) {
            $parentKey = $this->skillLineKey(array_merge(
                $pending['parent_ref'],
                ['class_restriction' => GameSkillDefinition::find($pending['skill_id'])?->class_restriction]
            ));
            $parent = $idByRef->get($parentKey);
            if ($parent !== null) {
                GameSkillDefinition::whereKey($pending['skill_id'])->update([
                    'prerequisite_skill_id' => $parent->id,
                ]);
            }
        }

        $seededIds = GameSkillDefinition::query()
            ->whereIn('skill_line', array_unique(array_column($skills, 'skill_line')))
            ->pluck('id');

        GameSkillDefinition::query()
            ->whereIn('class_restriction', ['warrior', 'mage', 'ranger'])
            ->whereNotIn('id', $seededIds)
            ->update(['is_active' => false]);
    }

    /**
     * @param  array<string, mixed>  $skill
     */
    private function skillLineKey(array $skill): string
    {
        return implode('|', [
            $skill['class_restriction'] ?? '',
            $skill['skill_line'] ?? '',
            (string) ($skill['node_tier'] ?? ''),
            $skill['spec_branch'] ?? '',
        ]);
    }

    private function seedMonsterDefinitions(): void
    {
        DB::table('game_monster_definitions')->truncate();

        $monsters = require __DIR__ . '/Data/monsters.php';

        foreach ($monsters as $monster) {
            $assetKey = $monster['asset_key'] ?? ('monster_' . strtolower(str_replace(' ', '_', $monster['name'])));
            unset($monster['asset_key']);

            GameMonsterDefinition::create(array_merge($monster, [
                'icon' => $assetKey . '.png',
                'is_active' => true,
            ]));
        }
    }

    private function seedMapDefinitions(): void
    {
        $maps = require __DIR__ . '/Data/maps.php';

        // 按 ID 顺序取当前库中怪物，用于把配置里的“序号”转成真实 ID(避免多次 seed 后 ID 错位)
        $monsterIdsByOrder = GameMonsterDefinition::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('id')
            ->values()
            ->all();

        foreach ($maps as $index => $map) {
            $assetKey = $map['asset_key'] ?? ('map_' . ($index + 1));
            unset($map['asset_key']);
            $rawIds = $map['monster_ids'] ?? [];
            $resolvedIds = array_values(array_filter(array_map(
                fn ($ord) => $monsterIdsByOrder[$ord - 1] ?? null,
                array_map('intval', (array) $rawIds)
            )));
            if (empty($resolvedIds)) {
                $resolvedIds = array_slice($monsterIdsByOrder, 0, 2);
            }

            GameMapDefinition::updateOrCreate(
                [
                    'name' => $map['name'],
                    'act' => $map['act'],
                ],
                array_merge($map, [
                    'monster_ids' => $resolvedIds,
                    'background' => $assetKey . '.jpg',
                    'is_active' => true,
                ])
            );
        }

        // 为所有缺少怪物的地图补全 monster_ids(含历史/重复行)
        $mapList = $maps;
        GameMapDefinition::query()->chunk(50, function ($definitions) use ($mapList, $monsterIdsByOrder) {
            foreach ($definitions as $def) {
                $ids = $def->monster_ids;
                if (is_array($ids) && ! empty(array_filter($ids))) {
                    continue;
                }
                $match = collect($mapList)->first(
                    fn ($m) => $m['name'] === $def->name && (int) $m['act'] === (int) $def->act
                );
                if ($match && ! empty($monsterIdsByOrder)) {
                    $rawIds = $match['monster_ids'] ?? [];
                    $resolvedIds = array_values(array_filter(array_map(
                        fn ($ord) => $monsterIdsByOrder[$ord - 1] ?? null,
                        array_map('intval', (array) $rawIds)
                    )));
                    if (empty($resolvedIds)) {
                        $resolvedIds = array_slice($monsterIdsByOrder, 0, 2);
                    }
                    $def->update(['monster_ids' => $resolvedIds]);
                } elseif (! empty($monsterIdsByOrder)) {
                    $def->update(['monster_ids' => array_slice($monsterIdsByOrder, 0, 2)]);
                }
            }
        });
    }
}
