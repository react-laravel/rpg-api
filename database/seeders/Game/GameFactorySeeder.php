<?php

namespace Database\Seeders\Game;

use App\Models\Game\GameItemDefinition;
use App\Models\Game\GameMapDefinition;
use App\Models\Game\GameMonsterDefinition;
use App\Models\Game\GameSkillDefinition;
use Illuminate\Database\Seeder;

class GameFactorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        GameItemDefinition::factory()->count(30)->create();
        GameSkillDefinition::factory()->count(12)->create();

        $maps = GameMapDefinition::factory()->count(8)->create();
        $monsters = GameMonsterDefinition::factory()->count($maps->count() * 3)->create();

        $maps->each(function (GameMapDefinition $map, int $index) use ($monsters): void {
            $map->update([
                'monster_ids' => $monsters
                    ->slice($index * 3, 3)
                    ->modelKeys(),
            ]);
        });
    }
}
