<?php

namespace App\Services\Game;

use App\Jobs\Game\AutoCombatRoundJob;
use App\Models\Game\GameCharacter;
use App\Models\Game\GameItem;
use App\Models\Game\GameItemDefinition;
use App\Support\Game\BalanceUpgradeBackup;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

final class FixedEquipmentUpgrade
{
    public function apply(bool $backup = true, bool $stopJobs = true): array
    {
        $backupPath = $backup ? app(BalanceUpgradeBackup::class)->create() : null;
        $catalog = collect(require database_path('seeders/Game/Data/items.php'))
            ->where('type', '!=', 'gem')->keyBy(fn ($item) => $item['asset_key'].'.png');
        $definitions = GameItemDefinition::where('type', '!=', 'gem')->get();
        $definitionStats = [];
        foreach ($definitions as $definition) {
            $key = basename(parse_url($definition->icon ?? '', PHP_URL_PATH) ?: '');
            if (isset($catalog[$key])) {
                $definitionStats[$definition->id] = $catalog[$key]['base_stats'];
            } elseif ($definition->getEquipmentSlot() !== null) {
                throw new \RuntimeException("装备定义 {$definition->id} 未匹配固定属性表，升级已停止");
            }
        }

        if ($stopJobs) {
            // Deployment stops workers before migration. Cancel delayed jobs before resuming them.
            foreach (GameCharacter::pluck('id') as $id) {
                Redis::del(AutoCombatRoundJob::redisKey($id));
            }
        }

        $result = DB::transaction(function () use ($definitionStats): array {
            foreach ($definitionStats as $id => $stats) {
                DB::table('game_item_definitions')->where('id', $id)->update([
                    'base_stats' => json_encode($stats, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
            }
            $items = 0;
            GameItem::whereIn('definition_id', array_keys($definitionStats))
                ->with('definition', 'gems.gemDefinition')->chunkById(100, function ($chunk) use ($definitionStats, &$items): void {
                    foreach ($chunk as $item) {
                        $item->stats = $definitionStats[$item->definition_id];
                        $item->affixes = [];
                        $item->sell_price = $item->calculateSellPrice();
                        $item->save();
                        $items++;
                    }
                });

            if (Artisan::call('rpg:sync-monster-progression') !== 0) {
                throw new \RuntimeException('怪物数值同步失败，固定装备升级已回滚');
            }
            $characters = 0;
            foreach (GameCharacter::all() as $character) {
                $character->clearCombatState();
                $character->is_fighting = false;
                if ($character->current_hp !== null) {
                    $character->current_hp = min((int) $character->current_hp, $character->getMaxHp());
                }
                if ($character->current_mana !== null) {
                    $character->current_mana = min((int) $character->current_mana, $character->getMaxMana());
                }
                $character->save();
                Cache::forget('game_character:list:'.$character->user_id);
                $characters++;
            }

            return ['definitions' => count($definitionStats), 'items' => $items, 'characters' => $characters];
        });

        return $result + ['backup' => $backupPath];
    }
}
