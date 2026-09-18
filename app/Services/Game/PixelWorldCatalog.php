<?php

namespace App\Services\Game;

use App\Models\Game\GameItemDefinition;
use App\Models\Game\GameMapDefinition;
use App\Models\Game\GameMonsterDefinition;
use Illuminate\Support\Facades\DB;

final class PixelWorldCatalog
{
    public static function plan(): array
    {
        return json_decode(file_get_contents(database_path('seeders/Game/Data/pixel-world.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    /** 更新名称与分布，不替换定义 ID，不重置已有战斗数值和玩家探索记录。 */
    public function sync(): array
    {
        return DB::transaction(function (): array {
            $plan = self::plan();
            $seeds = require database_path('seeders/Game/Data/monsters.php');
            $monsterIds = [];
            foreach ($plan['monsters'] as $entry) {
                $definition = GameMonsterDefinition::where('name', $entry['name'])->first()
                    ?? GameMonsterDefinition::where('name', $entry['legacy_name'])
                        ->orWhereIn('icon', [$entry['legacy_asset_key'].'.png', $entry['asset_key'].'.png', 'monster_'.$entry['ordinal'].'.png'])
                        ->orderBy('id')->first();
                $seed = $seeds[$entry['ordinal'] - 1];
                if ($definition) {
                    $definition->update([
                        'name' => $entry['name'],
                        'icon' => $entry['asset_key'].'.png',
                        'icon_prompt' => $seed['icon_prompt'],
                        'drop_table' => array_merge($definition->drop_table ?? [], [
                            'equipment_level' => $seed['drop_table']['equipment_level'],
                        ]),
                    ]);
                } else {
                    unset($seed['asset_key']);
                    $definition = GameMonsterDefinition::create($seed + ['icon' => $entry['asset_key'].'.png', 'is_active' => true]);
                }
                $monsterIds[$entry['ordinal']] = $definition->id;
            }

            foreach ($plan['maps'] as $entry) {
                $definition = GameMapDefinition::where('name', $entry['name'])->first()
                    ?? GameMapDefinition::where('name', $entry['legacy_name'])
                        ->orWhereIn('background', [$entry['legacy_asset_key'].'.jpg', $entry['asset_key'].'.jpg', 'map_'.$entry['ordinal'].'.jpg'])
                        ->orderBy('id')->first();
                $payload = [
                    'name' => $entry['name'], 'act' => $entry['act'], 'description' => $entry['description'],
                    'background' => $entry['asset_key'].'.jpg',
                    'icon_prompt' => '复古RPG像素地图，'.$entry['description'].'。中央保留战斗空地，无人物和文字。',
                    'monster_ids' => array_map(fn ($ordinal) => $monsterIds[$ordinal], $entry['monster_ordinals']),
                ];
                if ($definition) {
                    $definition->update($payload);
                } else {
                    GameMapDefinition::create($payload + ['is_active' => true]);
                }
            }

            GameItemDefinition::where('type', 'gem')->where('icon', 'gem')
                ->chunkById(200, function ($gems): void {
                    foreach ($gems as $gem) {
                        $stat = array_key_first($gem->gem_stats ?? []);
                        if (isset(GameItemDefinition::GEM_ICONS[$stat])) {
                            $gem->update(['icon' => GameItemDefinition::GEM_ICONS[$stat]]);
                        }
                    }
                });

            return ['maps' => count($plan['maps']), 'monsters' => count($monsterIds)];
        });
    }
}
