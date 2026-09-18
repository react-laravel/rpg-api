<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $items = require database_path('seeders/Game/Data/items.php');

        DB::transaction(function () use ($items): void {
            if (DB::getDriverName() === 'pgsql') {
                // 历史种子使用显式 ID；PostgreSQL 序列必须先追上现有最大 ID。
                DB::statement("SELECT setval(pg_get_serial_sequence('game_item_definitions', 'id'), GREATEST(COALESCE((SELECT MAX(id) FROM game_item_definitions), 1), nextval(pg_get_serial_sequence('game_item_definitions', 'id'))), true)");
            }
            foreach ($items as $item) {
                if (! str_starts_with($item['asset_key'], 'mage-set-')) {
                    continue;
                }

                $icon = $item['asset_key'].'.png';
                // 在线数据库可能已有动态生成的宝石，不能用种子的固定 ID 覆盖它们。
                unset($item['id'], $item['asset_key']);
                $item['base_stats'] = json_encode($item['base_stats'], JSON_THROW_ON_ERROR);
                $item['is_active'] = true;
                $item['updated_at'] = now();

                if (DB::table('game_item_definitions')->where('icon', $icon)->exists()) {
                    DB::table('game_item_definitions')->where('icon', $icon)->update($item);
                } else {
                    DB::table('game_item_definitions')->insert($item + ['icon' => $icon, 'created_at' => now()]);
                }
            }
        });
    }

    public function down(): void
    {
        // 保留已获得的套装物品及定义，避免回滚破坏玩家背包。
    }
};
