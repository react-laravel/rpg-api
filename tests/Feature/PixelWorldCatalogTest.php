<?php

namespace Tests\Feature;

use App\Models\Game\GameCharacter;
use App\Models\Game\GameMapDefinition;
use App\Models\Game\GameMonsterDefinition;
use App\Services\Game\PixelWorldCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PixelWorldCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_world_has_three_unique_matching_monsters_per_map(): void
    {
        $plan = PixelWorldCatalog::plan();
        $this->assertCount(41, $plan['maps']);
        $this->assertCount(123, $plan['monsters']);
        $this->assertCount(123, array_unique(array_column($plan['monsters'], 'name')));
        $this->assertCount(41, GameMapDefinition::all());
        foreach ($plan['maps'] as $entry) {
            $map = GameMapDefinition::where('name', $entry['name'])->firstOrFail();
            $this->assertCount(3, $map->monster_ids);
            foreach ($entry['monster_ordinals'] as $slot => $ordinal) {
                $monster = GameMonsterDefinition::findOrFail($map->monster_ids[$slot]);
                $this->assertSame($plan['monsters'][$ordinal - 1]['name'], $monster->name);
                $this->assertSame($entry['ordinal'], $monster->level);
            }
        }
    }

    public function test_upgrade_keeps_ids_player_location_and_existing_monster_stats(): void
    {
        GameMonsterDefinition::query()->delete();
        GameMapDefinition::query()->delete();
        $plan = PixelWorldCatalog::plan();
        $oldMonster = GameMonsterDefinition::factory()->create([
            'name' => $plan['monsters'][0]['legacy_name'],
            'icon' => $plan['monsters'][0]['legacy_asset_key'].'.png',
            'hp_base' => 777, 'attack_base' => 88,
        ]);
        $oldMap = GameMapDefinition::create([
            'name' => $plan['maps'][0]['legacy_name'], 'act' => 1,
            'background' => $plan['maps'][0]['legacy_asset_key'].'.jpg',
            'monster_ids' => [$oldMonster->id], 'is_active' => true,
        ]);
        $character = GameCharacter::create([
            'user_id' => 123, 'name' => '迁移保护', 'current_map_id' => $oldMap->id,
            'discovered_monsters' => [$oldMonster->id],
        ]);
        $service = app(PixelWorldCatalog::class);
        $service->sync();
        $firstMonsterIds = GameMonsterDefinition::orderBy('id')->pluck('id')->all();
        $firstMapIds = GameMapDefinition::orderBy('id')->pluck('id')->all();
        $service->sync();

        $this->assertSame('泥团史莱姆', $oldMonster->fresh()->name);
        $this->assertSame(777, $oldMonster->fresh()->hp_base);
        $this->assertSame(88, $oldMonster->fresh()->attack_base);
        $this->assertSame('晨光营地', $oldMap->fresh()->name);
        $this->assertSame($oldMonster->id, $oldMap->fresh()->monster_ids[0]);
        $this->assertSame($oldMap->id, $character->fresh()->current_map_id);
        $this->assertSame([$oldMonster->id], $character->fresh()->discovered_monsters);
        $this->assertSame($firstMonsterIds, GameMonsterDefinition::orderBy('id')->pluck('id')->all());
        $this->assertSame($firstMapIds, GameMapDefinition::orderBy('id')->pluck('id')->all());
    }

    public function test_late_maps_can_drop_high_level_sets_without_raising_combat_levels(): void
    {
        config(['game.equipment_drop.chance' => 1.0]);
        $monster = GameMonsterDefinition::where('name', '九霄雷龙')->firstOrFail();
        $this->assertSame(35, $monster->level);
        $this->assertSame(100, $monster->generateLoot(100)['item']['level']);
        $this->assertSame(12, $monster->generateLoot(12)['item']['level']);
        $early = GameMonsterDefinition::where('name', '泥团史莱姆')->firstOrFail();
        $this->assertSame(10, $early->equipmentDropLevel(100));
    }
}
