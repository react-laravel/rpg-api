<?php

namespace App\Console\Commands\Game;

use App\Models\Game\GameMonsterDefinition;
use App\Services\Game\PixelWorldCatalog;
use Illuminate\Console\Command;

class SyncMonsterProgressionCommand extends Command
{
    protected $signature = 'rpg:sync-monster-progression
                            {--dry-run : 仅预览将要更新的记录，不写入数据库}';

    protected $description = '按地图层公式同步怪物生命/攻击/防御/经验与类型，不改变 ID 与名称';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $seeded = require database_path('seeders/Game/Data/monsters.php');
        $legacyNames = array_column(PixelWorldCatalog::plan()['monsters'], 'legacy_name');
        $fields = ['type', 'level', 'hp_base', 'attack_base', 'defense_base', 'experience_base'];

        $updated = 0;
        $unchanged = 0;
        $missing = 0;
        $rows = [];

        foreach ($seeded as $index => $seed) {
            $name = (string) ($seed['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $definition = GameMonsterDefinition::query()->where('name', $name)->first()
                ?? GameMonsterDefinition::query()->where('name', $legacyNames[$index] ?? $name)->first();
            if (! $definition instanceof GameMonsterDefinition) {
                $missing++;
                $this->warn("未找到怪物: {$name}");

                continue;
            }

            $changes = [];
            $payload = [];
            foreach ($fields as $field) {
                if (! array_key_exists($field, $seed)) {
                    continue;
                }
                $from = $definition->{$field};
                $to = $seed[$field];
                if ((string) $from === (string) $to) {
                    continue;
                }
                $changes[$field] = "{$from}->{$to}";
                $payload[$field] = $to;
            }

            if ($payload === []) {
                $unchanged++;

                continue;
            }

            $updated++;
            if (count($rows) < 20) {
                $rows[] = [
                    $definition->id,
                    $definition->name,
                    implode(', ', $changes),
                ];
            }

            if (! $dryRun) {
                $definition->update($payload);
            }
        }

        if ($rows !== []) {
            $this->table(['ID', '名称', '变更'], $rows);
            if ($updated > count($rows)) {
                $this->line('... 其余 '.($updated - count($rows)).' 条已省略');
            }
        }

        $this->info($dryRun
            ? "dry-run: 将更新 {$updated} 条，保持 {$unchanged} 条，缺失 {$missing} 条"
            : "已更新 {$updated} 条，保持 {$unchanged} 条，缺失 {$missing} 条");

        return self::SUCCESS;
    }
}
