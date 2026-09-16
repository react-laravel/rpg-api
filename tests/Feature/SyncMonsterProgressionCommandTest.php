<?php

namespace Tests\Feature;

use App\Models\Game\GameMonsterDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncMonsterProgressionCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_existing_monsters_by_name_without_renaming(): void
    {
        $wolf = GameMonsterDefinition::factory()->create([
            'name' => '野狼',
            'type' => 'normal',
            'level' => 2,
            'hp_base' => 6,
            'attack_base' => 3,
            'defense_base' => 4,
            'experience_base' => 4,
        ]);
        $alpha = GameMonsterDefinition::factory()->create([
            'name' => '巨狼',
            'type' => 'elite',
            'level' => 3,
            'hp_base' => 33,
            'attack_base' => 4,
            'defense_base' => 3,
            'experience_base' => 9,
        ]);

        $this->artisan('rpg:sync-monster-progression')
            ->assertSuccessful();

        $wolf->refresh();
        $alpha->refresh();

        $this->assertSame('野狼', $wolf->name);
        $this->assertSame(6, $wolf->hp_base);
        $this->assertSame('normal', $wolf->type);

        $this->assertSame('巨狼', $alpha->name);
        $this->assertSame($wolf->id, $wolf->refresh()->id);
        $this->assertSame('normal', $alpha->type);
        $this->assertSame(15, $alpha->hp_base);
        $this->assertSame(4, $alpha->attack_base);
        $this->assertSame(3, $alpha->defense_base);
        $this->assertSame(9, $alpha->experience_base);
    }

    public function test_dry_run_does_not_write(): void
    {
        $alpha = GameMonsterDefinition::factory()->create([
            'name' => '巨狼',
            'type' => 'elite',
            'hp_base' => 33,
            'attack_base' => 4,
            'defense_base' => 3,
            'experience_base' => 9,
            'level' => 3,
        ]);

        $this->artisan('rpg:sync-monster-progression --dry-run')
            ->assertSuccessful();

        $this->assertSame(33, $alpha->refresh()->hp_base);
        $this->assertSame('elite', $alpha->type);
    }
}
