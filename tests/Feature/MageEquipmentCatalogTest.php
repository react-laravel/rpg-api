<?php

namespace Tests\Feature;

use App\Models\Game\GameCharacter;
use App\Models\Game\GameItem;
use App\Models\Game\GameItemDefinition;
use App\Models\Game\GameMonsterDefinition;
use App\Services\Game\GameCombatLootService;
use App\Services\Game\GameInventoryService;
use App\Support\Game\RpgAssetIconNormalizer;
use Database\Seeders\Game\GameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MageEquipmentCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_item_icons_use_definition_ids_after_catalogue_removal(): void
    {
        $this->assertSame('beginner-staff.png', RpgAssetIconNormalizer::normalizeItem('item_14.png'));
        $this->assertSame('cloth-cap-helmet.png', RpgAssetIconNormalizer::normalizeItem('item_40.png'));
        $this->assertSame('attack-gem.png', RpgAssetIconNormalizer::normalizeItem('item_146.png'));
    }

    public function test_seeded_catalog_has_complete_mage_sets_with_consistent_art_and_no_retired_equipment(): void
    {
        $this->seed(GameSeeder::class);
        $items = require database_path('seeders/Game/Data/items.php');
        $sets = require database_path('seeders/Game/Data/mage-sets.php');

        $this->assertCount(110, $items);
        $this->assertCount(110, array_unique(array_column($items, 'name')));
        $this->assertCount(110, array_unique(array_column($items, 'asset_key')));
        foreach ($items as $item) {
            $this->assertContains($item['sub_type'], ['staff', 'cloth', null]);
            $this->assertStringNotContainsString('圣骑士', $item['name']);
        }
        foreach ($sets as $set) {
            $pieces = array_values(array_filter($items, fn ($item) => str_starts_with($item['asset_key'], 'mage-set-'.$set['key'].'-')));
            $this->assertCount(8, $pieces);
            $this->assertEqualsCanonicalizing(GameCharacter::getSlots(), array_column($pieces, 'type'));
            foreach ($pieces as $piece) {
                $this->assertSame($set['level'], $piece['required_level']);
                $this->assertStringContainsString($set['visual'], $piece['icon_prompt']);
                $this->assertStringContainsString($set['name'].'套装', $piece['description']);
                $this->assertNotEmpty($piece['base_stats']);
            }
        }
    }

    public function test_cleanup_removes_owned_items_and_references_but_keeps_mage_items_and_gems(): void
    {
        $character = GameCharacter::create(['user_id' => 7, 'name' => '装备清理', 'current_hp' => 9999, 'current_mana' => 9999]);
        $dead = GameCharacter::create(['user_id' => 8, 'name' => '死亡角色', 'current_hp' => 0]);
        $staff = GameItemDefinition::factory()->create(['type' => 'weapon', 'sub_type' => 'staff']);
        $gem = GameItemDefinition::factory()->gem()->create();
        $survivor = $this->item($character, $staff, ['slot_index' => 1]);
        $looseGem = $this->item($character, $gem, ['slot_index' => 2]);
        $retiredIds = [];
        $oldItemIds = [];
        foreach (['sword', 'bow', 'plate', 'leather', 'mail', 'axe', 'mace', 'dagger'] as $index => $subType) {
            $definition = GameItemDefinition::factory()->create(['type' => 'weapon', 'sub_type' => $subType]);
            $retiredIds[] = $definition->id;
            $item = $this->item($character, $definition, ['is_in_storage' => $index > 0, 'slot_index' => $index]);
            $oldItemIds[] = $item->id;
            if ($index === 0) {
                $item->update(['is_equipped' => true, 'slot_index' => null, 'stats' => ['max_hp' => 1000, 'max_mana' => 1000]]);
                $character->equipment()->create(['slot' => 'weapon', 'item_id' => $item->id]);
                DB::table('game_item_gems')->insert(['item_id' => $item->id, 'gem_definition_id' => $gem->id, 'socket_index' => 0]);
            }
        }
        foreach (['圣骑士戒指', '圣武士护符'] as $name) {
            $definition = GameItemDefinition::factory()->create(['name' => $name, 'type' => 'ring', 'sub_type' => null, 'is_active' => false]);
            $retiredIds[] = $definition->id;
            $oldItemIds[] = $this->item($dead, $definition)->id;
        }
        DB::table('game_item_gems')->insert(['item_id' => $survivor->id, 'gem_definition_id' => $gem->id, 'socket_index' => 0]);
        $character->update(['discovered_items' => [...$retiredIds, $staff->id, $gem->id]]);
        $migration = require database_path('migrations/2026_09_19_010000_remove_non_mage_items.php');
        $migration->up();
        $migration->up();

        $this->assertSame(0, GameItemDefinition::whereIn('id', $retiredIds)->count());
        $this->assertSame(0, GameItem::whereIn('id', $oldItemIds)->count());
        $this->assertSame(0, DB::table('game_item_gems')->whereIn('item_id', $oldItemIds)->count());
        $this->assertNull($character->equipment()->first()->item_id);
        $this->assertDatabaseHas('game_item_gems', ['item_id' => $survivor->id]);
        $character = $character->fresh();
        $this->assertSame([$staff->id, $gem->id], $character->discovered_items);
        $this->assertSame($character->getMaxHp(), $character->current_hp);
        $this->assertSame($character->getMaxMana(), $character->current_mana);
        $this->assertSame(0, $dead->fresh()->current_hp);
        $inventory = app(GameInventoryService::class)->getInventory($character);
        $this->assertEqualsCanonicalizing([$survivor->id, $looseGem->id], $inventory['inventory']->modelKeys());
        $this->assertCount(0, $inventory['storage']);
        $this->assertSame(0, app(GameInventoryService::class)->findEmptySlot($character, false));
    }

    public function test_set_migration_is_repeatable_and_does_not_overwrite_existing_dynamic_items(): void
    {
        DB::table('game_item_definitions')->delete();
        $gem = GameItemDefinition::factory()->gem()->create(['id' => 1001, 'name' => '现有宝石']);
        $migration = require database_path('migrations/2026_09_19_020000_add_mage_equipment_sets.php');
        $migration->up();
        $ids = GameItemDefinition::orderBy('id')->pluck('id')->all();
        $migration->up();
        $this->assertSame($ids, GameItemDefinition::orderBy('id')->pluck('id')->all());
        $this->assertSame('现有宝石', $gem->fresh()->name);
        $this->assertSame(57, GameItemDefinition::count());
    }

    public function test_compendium_drops_and_equip_flow_use_the_mage_catalog(): void
    {
        $this->seed(GameSeeder::class);
        $character = GameCharacter::create(['user_id' => 7, 'name' => '法师流程', 'level' => 100]);
        $this->withSession(['rpg_identity' => ['id' => 7, 'name' => '测试']]);
        $this->getJson('/api/rpg/compendium/items?character_id='.$character->id)
            ->assertOk()->assertJsonPath('total', 110)->assertJsonFragment(['name' => '鎏金法袍']);
        $monster = GameMonsterDefinition::factory()->create(['level' => 100, 'drop_table' => ['item_types' => ['armor']]]);
        $drops = $this->getJson('/api/rpg/compendium/monsters/'.$monster->id.'/drops?character_id='.$character->id)->assertOk()->json('possible_items');
        $this->assertContains('流风法袍', array_column($drops, 'name'));
        $this->assertSame(['cloth'], array_values(array_unique(array_column($drops, 'sub_type'))));

        foreach (GameCharacter::getSlots() as $type) {
            $loot = app(GameCombatLootService::class)->createItem($character, ['type' => $type, 'level' => 100, 'quality' => 'common']);
            $this->assertNotNull($loot);
            $this->assertContains($loot->definition->sub_type, ['staff', 'cloth', null]);
        }
        $robe = GameItemDefinition::where('name', '鎏金法袍')->firstOrFail();
        $item = $this->item($character, $robe, ['stats' => $robe->base_stats, 'slot_index' => 20]);
        $result = app(GameInventoryService::class)->equipItem($character->fresh(), $item->id);
        $this->assertSame('armor', $result['equipped_slot']);
        $this->assertSame('鎏金法袍', $result['equipped_item']->definition->name);
        $this->assertGreaterThan(0, $character->fresh()->getEquipmentBonus('max_mana'));
    }

    private function item(GameCharacter $character, GameItemDefinition $definition, array $attributes = []): GameItem
    {
        return GameItem::create($attributes + [
            'character_id' => $character->id, 'definition_id' => $definition->id,
            'quality' => 'common', 'quantity' => 1, 'stats' => [], 'affixes' => [],
            'is_in_storage' => false, 'is_equipped' => false, 'sockets' => 1,
        ]);
    }
}
