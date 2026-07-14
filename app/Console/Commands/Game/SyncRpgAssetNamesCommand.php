<?php

namespace App\Console\Commands\Game;

use App\Models\Game\GameItemDefinition;
use App\Models\Game\GameMapDefinition;
use App\Models\Game\GameMonsterDefinition;
use App\Models\Game\GameSkillDefinition;
use Illuminate\Console\Command;

class SyncRpgAssetNamesCommand extends Command
{
    protected $signature = 'game:sync-rpg-asset-names
                            {--dry-run : 仅预览将要更新的记录，不写入数据库}';

    protected $description = '将 RPG 地图、怪物、物品定义表的资源文件名同步为英文名';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->components->info($dryRun
            ? '预览 RPG 资源英文名同步变更(dry-run)'
            : '开始同步 RPG 资源英文名到数据库');

        $itemSummary = $this->syncItemDefinitions($dryRun);
        $monsterSummary = $this->syncMonsterDefinitions($dryRun);
        $mapSummary = $this->syncMapDefinitions($dryRun);
        $skillSummary = $this->syncSkillDefinitions($dryRun);

        $this->newLine();
        $this->table(
            ['表', '匹配', '更新', '缺失映射'],
            [
                ['game_item_definitions', $itemSummary['matched'], $itemSummary['updated'], $itemSummary['missing']],
                ['game_monster_definitions', $monsterSummary['matched'], $monsterSummary['updated'], $monsterSummary['missing']],
                ['game_map_definitions', $mapSummary['matched'], $mapSummary['updated'], $mapSummary['missing']],
                ['game_skill_definitions', $skillSummary['matched'], $skillSummary['updated'], $skillSummary['missing']],
            ]
        );

        if ($dryRun) {
            $this->components->warn('dry-run 模式未写入数据库。');
        } else {
            $this->components->info('RPG 资源英文名同步完成。');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{matched:int,updated:int,missing:int}
     */
    private function syncItemDefinitions(bool $dryRun): array
    {
        $items = require database_path('seeders/Game/Data/items.php');
        $assetMap = [];
        foreach ($items as $item) {
            $assetMap[$item['name']] = ($item['asset_key'] ?? ('item_' . $item['id'])) . '.png';
        }

        $matched = 0;
        $updated = 0;

        GameItemDefinition::query()->orderBy('id')->each(function (GameItemDefinition $definition) use (
            $assetMap,
            $dryRun,
            &$matched,
            &$updated
        ): void {
            $fileName = $assetMap[$definition->name] ?? null;
            if ($fileName === null) {
                return;
            }

            $matched++;
            if ($definition->icon === $fileName) {
                return;
            }

            $updated++;
            $this->line(sprintf(
                '[item] #%d %s: %s -> %s',
                $definition->id,
                $definition->name,
                $definition->icon ?? '(null)',
                $fileName
            ));

            if (! $dryRun) {
                $definition->forceFill(['icon' => $fileName])->save();
            }
        });

        return [
            'matched' => $matched,
            'updated' => $updated,
            'missing' => max(GameItemDefinition::query()->count() - $matched, 0),
        ];
    }

    /**
     * @return array{matched:int,updated:int,missing:int}
     */
    private function syncMonsterDefinitions(bool $dryRun): array
    {
        $monsters = require database_path('seeders/Game/Data/monsters.php');
        $assetMap = [];
        foreach ($monsters as $monster) {
            $assetMap[$monster['name']] = ($monster['asset_key'] ?? ('monster_' . $monster['id'])) . '.png';
        }

        $matched = 0;
        $updated = 0;

        GameMonsterDefinition::query()->orderBy('id')->each(function (GameMonsterDefinition $definition) use (
            $assetMap,
            $dryRun,
            &$matched,
            &$updated
        ): void {
            $fileName = $assetMap[$definition->name] ?? null;
            if ($fileName === null) {
                return;
            }

            $matched++;
            if ($definition->icon === $fileName) {
                return;
            }

            $updated++;
            $this->line(sprintf(
                '[monster] #%d %s: %s -> %s',
                $definition->id,
                $definition->name,
                $definition->icon ?? '(null)',
                $fileName
            ));

            if (! $dryRun) {
                $definition->forceFill(['icon' => $fileName])->save();
            }
        });

        return [
            'matched' => $matched,
            'updated' => $updated,
            'missing' => max(GameMonsterDefinition::query()->count() - $matched, 0),
        ];
    }

    /**
     * @return array{matched:int,updated:int,missing:int}
     */
    private function syncMapDefinitions(bool $dryRun): array
    {
        $maps = require database_path('seeders/Game/Data/maps.php');
        $assetMap = [];
        foreach ($maps as $index => $map) {
            $assetMap[$map['name'] . '#' . $map['act']] = ($map['asset_key'] ?? ('map_' . ($index + 1))) . '.jpg';
        }

        $matched = 0;
        $updated = 0;

        GameMapDefinition::query()->orderBy('id')->each(function (GameMapDefinition $definition) use (
            $assetMap,
            $dryRun,
            &$matched,
            &$updated
        ): void {
            $key = $definition->name . '#' . $definition->act;
            $fileName = $assetMap[$key] ?? null;
            if ($fileName === null) {
                return;
            }

            $matched++;
            if ($definition->background === $fileName) {
                return;
            }

            $updated++;
            $this->line(sprintf(
                '[map] #%d %s(act %d): %s -> %s',
                $definition->id,
                $definition->name,
                $definition->act,
                $definition->background ?? '(null)',
                $fileName
            ));

            if (! $dryRun) {
                $definition->forceFill(['background' => $fileName])->save();
            }
        });

        return [
            'matched' => $matched,
            'updated' => $updated,
            'missing' => max(GameMapDefinition::query()->count() - $matched, 0),
        ];
    }

    /**
     * @return array{matched:int,updated:int,missing:int}
     */
    private function syncSkillDefinitions(bool $dryRun): array
    {
        $skillsDir = database_path('seeders/Game/Data/Skills');
        $skillFiles = [
            'skills_warrior.php',
            'skills_mage.php',
            'skills_ranger.php',
            'skills_common.php',
        ];
        $assetMap = [];
        foreach ($skillFiles as $file) {
            $path = $skillsDir . '/' . $file;
            if (! file_exists($path)) {
                continue;
            }
            $entries = require $path;
            foreach ($entries as $skill) {
                $icon = $skill['icon'] ?? null;
                if (! $icon && ! empty($skill['effect_key'])) {
                    $icon = $skill['effect_key'] . '.png';
                }
                if (! $icon && ! empty($skill['name'])) {
                    $icon = 'skill_' . preg_replace('/[^a-z0-9]+/', '_', strtolower($skill['name'])) . '.png';
                }
                if ($icon) {
                    $assetMap[$skill['name']] = $icon;
                }
            }
        }

        $matched = 0;
        $updated = 0;

        GameSkillDefinition::query()->orderBy('id')->each(function (GameSkillDefinition $definition) use (
            $assetMap,
            $dryRun,
            &$matched,
            &$updated
        ): void {
            $fileName = $assetMap[$definition->name] ?? null;
            if ($fileName === null) {
                return;
            }

            $matched++;
            if ($definition->icon === $fileName) {
                return;
            }

            $updated++;
            $this->line(sprintf(
                '[skill] #%d %s: %s -> %s',
                $definition->id,
                $definition->name,
                $definition->icon ?? '(null)',
                $fileName
            ));

            if (! $dryRun) {
                $definition->forceFill(['icon' => $fileName])->save();
            }
        });

        return [
            'matched' => $matched,
            'updated' => $updated,
            'missing' => max(GameSkillDefinition::query()->count() - $matched, 0),
        ];
    }
}
