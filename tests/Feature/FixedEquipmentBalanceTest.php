<?php

namespace Tests\Feature;

use App\Models\Game\GameCharacter;
use App\Models\Game\GameItem;
use App\Models\Game\GameItemDefinition;
use App\Services\Game\FixedEquipmentUpgrade;
use App\Services\Game\GameCombatLootService;
use Database\Seeders\Game\GameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class FixedEquipmentBalanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_has_small_monotonic_primary_values(): void
    {
        $items = collect(require database_path('seeders/Game/Data/items.php'));
        foreach (['weapon' => 'attack', 'armor' => 'defense', 'helmet' => 'defense', 'gloves' => 'defense', 'boots' => 'defense', 'ring' => 'attack', 'amulet' => 'attack'] as $type => $stat) {
            $levels = $items->where('type', $type)->sortBy('required_level')->groupBy('required_level');
            $previous = null;
            foreach ($levels as $levelItems) {
                $values = $levelItems->pluck('base_stats.'.$stat)->unique()->values();
                $this->assertCount(1, $values);
                $value = $values[0];
                $this->assertGreaterThan(0, $value);
                $this->assertLessThanOrEqual(14, $value);
                if ($previous !== null) {
                    $this->assertGreaterThanOrEqual($previous, $value);
                    $this->assertLessThanOrEqual(2, $value - $previous);
                }
                $previous = $value;
            }
        }
    }

    public function test_every_quality_and_roll_has_identical_definition_stats_and_no_affixes(): void
    {
        $this->seed(GameSeeder::class);
        $definition = GameItemDefinition::where('name', '流风法杖')->firstOrFail();
        GameItemDefinition::where('type', 'weapon')->where('id', '!=', $definition->id)->delete();
        $character = GameCharacter::create(['user_id' => 7, 'name' => '固定属性', 'level' => 100]);
        foreach (['common', 'magic', 'rare', 'legendary', 'mythic'] as $quality) {
            for ($i = 0; $i < 3; $i++) {
                $item = app(GameCombatLootService::class)->createItem($character, ['type' => 'weapon', 'level' => 100, 'quality' => $quality]);
                $this->assertSame(['attack' => 12], $item->stats);
                $this->assertSame([], $item->affixes);
                $this->assertSame($quality, $item->quality);
            }
        }
    }

    public function test_upgrade_backs_up_rolls_and_preserves_levels_ids_gems_and_inventory_positions(): void
    {
        $this->seed(GameSeeder::class);
        $definition = GameItemDefinition::where('name', '流风法杖')->firstOrFail();
        $definition->update(['base_stats' => ['attack' => 227]]);
        $character = GameCharacter::create(['user_id' => 7, 'name' => '升级保护', 'level' => 85, 'experience' => config('game.experience_table.85') + 123, 'copper' => 12345, 'skill_points' => 12, 'stat_points' => 9, 'current_map_id' => 31, 'is_fighting' => true, 'current_hp' => 9999, 'current_mana' => 9999, 'auto_recycle_max_value' => 10]);
        $dead = GameCharacter::create(['user_id' => 8, 'name' => '保留死亡', 'current_hp' => 0]);
        $item = GameItem::create(['character_id' => $character->id, 'definition_id' => $definition->id, 'quality' => 'legendary', 'stats' => ['attack' => 400], 'affixes' => [['attack' => 30]], 'is_in_storage' => true, 'slot_index' => 7, 'quantity' => 1, 'sockets' => 3]);
        $gem = GameItemDefinition::where('name', '攻击宝石')->firstOrFail();
        $socket = $item->gems()->create(['gem_definition_id' => $gem->id, 'socket_index' => 0]);
        $character->equipment()->create(['slot' => 'weapon', 'item_id' => $item->id]);
        $before = $character->only(['id', 'level', 'experience', 'copper', 'skill_points', 'stat_points', 'current_map_id', 'auto_recycle_max_value']);
        Redis::shouldReceive('del')->twice()->andReturn(1);

        $result = app(FixedEquipmentUpgrade::class)->apply();
        try {
            $backup = json_decode(gzdecode(file_get_contents($result['backup'])), true, flags: JSON_THROW_ON_ERROR);
            $savedItem = collect($backup['game_items'])->firstWhere('id', $item->id);
            $this->assertSame(['attack' => 400], json_decode($savedItem['stats'], true));
            $this->assertSame([['attack' => 30]], json_decode($savedItem['affixes'], true));
            $this->assertSame($before, $character->fresh()->only(array_keys($before)));
            $this->assertFalse($character->fresh()->is_fighting);
            $this->assertSame(0, $dead->fresh()->current_hp);
            $this->assertSame($character->fresh()->getMaxHp(), $character->fresh()->current_hp);
            $this->assertSame(['attack' => 12], $item->fresh()->stats);
            $this->assertSame([], $item->fresh()->affixes);
            $this->assertEquals(16, $item->fresh()->getTotalStats()['attack']);
            $this->assertDatabaseHas('game_item_gems', ['id' => $socket->id, 'item_id' => $item->id, 'gem_definition_id' => $gem->id, 'socket_index' => 0]);
            $this->assertDatabaseHas('game_items', ['id' => $item->id, 'quality' => 'legendary', 'is_in_storage' => true, 'slot_index' => 7, 'sockets' => 3]);
            $this->assertDatabaseHas('game_equipment', ['character_id' => $character->id, 'item_id' => $item->id]);
            $ids = DB::table('game_items')->orderBy('id')->pluck('id')->all();
            app(FixedEquipmentUpgrade::class)->apply(backup: false, stopJobs: false);
            $this->assertSame($ids, DB::table('game_items')->orderBy('id')->pluck('id')->all());
            $this->assertSame(['attack' => 12], $item->fresh()->stats);
        } finally {
            unlink($result['backup']);
        }
    }

    public function test_unmapped_equipment_stops_upgrade_before_changing_stats(): void
    {
        $this->seed(GameSeeder::class);
        $definition = GameItemDefinition::where('name', '流风法杖')->firstOrFail();
        $definition->update(['base_stats' => ['attack' => 227]]);
        GameItemDefinition::create(['name' => '未映射法杖', 'type' => 'weapon', 'icon' => 'unknown-staff.png', 'base_stats' => ['attack' => 500], 'required_level' => 1]);

        try {
            app(FixedEquipmentUpgrade::class)->apply(backup: false, stopJobs: false);
            $this->fail('Unmapped equipment must stop the upgrade');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('未匹配固定属性表', $exception->getMessage());
        }
        $this->assertSame(['attack' => 227], $definition->fresh()->base_stats);
    }

    public function test_monster_sync_failure_rolls_back_equipment_updates(): void
    {
        $this->seed(GameSeeder::class);
        $definition = GameItemDefinition::where('name', '流风法杖')->firstOrFail();
        $definition->update(['base_stats' => ['attack' => 227]]);
        Artisan::shouldReceive('call')->once()->with('rpg:sync-monster-progression')->andReturn(1);

        try {
            app(FixedEquipmentUpgrade::class)->apply(backup: false, stopJobs: false);
            $this->fail('Monster sync failure must abort the upgrade');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('怪物数值同步失败', $exception->getMessage());
        }
        $this->assertSame(['attack' => 227], $definition->fresh()->base_stats);
    }
}
