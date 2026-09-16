<?php

namespace Tests\Unit;

use App\Models\Game\GameItem;
use App\Models\Game\GameItemDefinition;
use App\Services\Game\InventoryItemCalculator;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

class InventoryItemCalculatorTest extends TestCase
{
    public function test_rare_boots_with_many_affixes_are_not_pocket_change(): void
    {
        $price = (new InventoryItemCalculator)->calculateSellPrice($this->makeItem(
            type: 'boots',
            requiredLevel: 1,
            quality: 'rare',
            stats: ['defense' => 4, 'max_hp' => 9, 'max_mana' => 5],
            affixes: [
                ['max_hp' => 9],
                ['max_mana' => 5],
                ['defense' => 2],
            ],
        ));

        $this->assertGreaterThanOrEqual(45, $price);
        $this->assertLessThan(400, $price);
    }

    public function test_high_level_weapon_is_not_thousands_of_times_boots(): void
    {
        $calculator = new InventoryItemCalculator;
        $boots = $calculator->calculateSellPrice($this->makeItem(
            type: 'boots',
            requiredLevel: 1,
            quality: 'rare',
            stats: ['defense' => 4, 'max_hp' => 9, 'max_mana' => 5],
            affixes: [['max_hp' => 9], ['max_mana' => 5], ['defense' => 2]],
        ));
        $weapon = $calculator->calculateSellPrice($this->makeItem(
            type: 'weapon',
            requiredLevel: 75,
            quality: 'rare',
            stats: ['attack' => 192, 'crit_rate' => 0.07, 'crit_damage' => 0.5],
        ));

        $this->assertGreaterThan($boots, $weapon);
        $this->assertLessThan(200, $weapon / max(1, $boots));
    }

    /**
     * @param  array<string, int|float>  $stats
     * @param  array<int, array<string, int|float>>  $affixes
     */
    private function makeItem(
        string $type,
        int $requiredLevel,
        string $quality,
        array $stats,
        array $affixes = [],
        int $sockets = 0,
    ): GameItem {
        $definition = new GameItemDefinition;
        $definition->forceFill([
            'type' => $type,
            'required_level' => $requiredLevel,
            'base_stats' => $stats,
        ]);

        $item = new GameItem;
        $item->forceFill([
            'quality' => $quality,
            'stats' => $stats,
            'affixes' => $affixes,
            'sockets' => $sockets,
        ]);
        $item->setRelation('definition', $definition);
        $item->setRelation('gems', new Collection);

        return $item;
    }
}
