<?php

namespace Tests\Unit;

use App\Models\Game\GameMonsterDefinition;
use Tests\TestCase;

class GemDropTest extends TestCase
{
    public function test_a_missed_equipment_roll_can_drop_a_gem(): void
    {
        config([
            'game.equipment_drop.chance' => 0,
            'game.gem_drop.chance' => 1,
        ]);

        $loot = $this->monster()->generateLoot(4);

        $this->assertSame('gem', $loot['item']['type']);
        $this->assertSame('common', $loot['item']['quality']);
        $this->assertSame(4, $loot['item']['level']);
    }

    public function test_equipment_drop_is_kept_ahead_of_a_gem(): void
    {
        config([
            'game.equipment_drop.chance' => 1,
            'game.gem_drop.chance' => 1,
        ]);

        $loot = $this->monster()->generateLoot(8);

        $this->assertSame('weapon', $loot['item']['type']);
    }

    public function test_no_item_drops_when_both_chances_are_zero(): void
    {
        config([
            'game.equipment_drop.chance' => 0,
            'game.gem_drop.chance' => 0,
        ]);

        $this->assertSame([], $this->monster()->generateLoot(3));
    }

    private function monster(): GameMonsterDefinition
    {
        $monster = new GameMonsterDefinition;
        $monster->level = 2;
        $monster->drop_table = ['item_types' => ['weapon'], 'equipment_level' => 10];

        return $monster;
    }
}
