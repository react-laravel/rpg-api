<?php

use App\Models\Game\GameCharacter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            // 不依赖固定 ID，兼容历史种子和改名后的物品。
            $definitionIds = DB::table('game_item_definitions')
                ->whereIn('sub_type', ['sword', 'axe', 'mace', 'bow', 'dagger', 'leather', 'mail', 'plate'])
                ->orWhereIn('name', ['圣骑士戒指', '圣武士戒指', '圣骑士护符', '圣武士护符'])
                ->pluck('id');

            if ($definitionIds->isEmpty()) {
                return;
            }

            $items = DB::table('game_items')->whereIn('definition_id', $definitionIds);
            $itemIds = (clone $items)->select('id');
            $affectedCharacterIds = (clone $items)->distinct()->pluck('character_id')->all();

            // 先解除装备和镶嵌引用，再清除背包、仓库中的实例及定义。
            DB::table('game_equipment')->whereIn('item_id', $itemIds)->update(['item_id' => null]);
            DB::table('game_item_gems')->whereIn('item_id', $itemIds)->delete();
            $items->delete();
            DB::table('game_item_definitions')->whereIn('id', $definitionIds)->delete();

            GameCharacter::query()->chunkById(200, function ($characters) use ($definitionIds, $affectedCharacterIds): void {
                foreach ($characters as $character) {
                    if (is_array($character->discovered_items)) {
                        $character->discovered_items = array_values(array_diff($character->discovered_items, $definitionIds->all()));
                    }

                    if (in_array($character->id, $affectedCharacterIds, true)) {
                        // 清除装备后不能保留超出新上限的生命和法力，也不能复活死亡角色。
                        if ($character->current_hp !== null) {
                            $character->current_hp = min($character->current_hp, $character->getMaxHp());
                        }
                        if ($character->current_mana !== null) {
                            $character->current_mana = min($character->current_mana, $character->getMaxMana());
                        }
                        Cache::forget('game_inventory:'.$character->id);
                    }

                    if ($character->isDirty()) {
                        $character->saveQuietly();
                    }
                }
            });
        });
    }

    public function down(): void
    {
        // 已按要求永久清除旧装备，回滚不能重建玩家原有的随机属性和镶嵌状态。
    }
};
