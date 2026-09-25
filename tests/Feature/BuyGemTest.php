<?php

namespace Tests\Feature;

use App\Exceptions\GameException;
use App\Models\Game\GameCharacter;
use App\Models\Game\GameItem;
use App\Models\Game\GameItemDefinition;
use App\Services\Game\BuyGem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuyGemTest extends TestCase
{
    use RefreshDatabase;

    public function test_buying_a_gem_spends_copper_and_puts_it_in_the_backpack(): void
    {
        $definition = GameItemDefinition::factory()->gem()->create([
            'name' => '攻击宝石',
            'required_level' => 1,
            'gem_stats' => ['attack' => 4],
        ]);
        $character = GameCharacter::create([
            'user_id' => 7,
            'name' => '买宝石',
            'level' => 3,
            'copper' => 80,
        ]);

        $result = app(BuyGem::class)->buy($character, $definition->id);

        $this->assertSame(0, $result['copper']);
        $this->assertSame(0, $character->fresh()->copper);
        $this->assertDatabaseHas('game_items', [
            'id' => $result['item']->id,
            'character_id' => $character->id,
            'definition_id' => $definition->id,
            'is_equipped' => false,
        ]);
        $this->assertSame('gem', $result['item']->definition->type);
    }

    public function test_buying_fails_when_copper_or_level_is_short(): void
    {
        $definition = GameItemDefinition::factory()->gem()->create([
            'required_level' => 5,
            'gem_stats' => ['attack' => 4],
        ]);
        $poor = GameCharacter::create([
            'user_id' => 7,
            'name' => '钱不够',
            'level' => 8,
            'copper' => 10,
        ]);
        $low = GameCharacter::create([
            'user_id' => 8,
            'name' => '等级不够',
            'level' => 2,
            'copper' => 500,
        ]);

        try {
            app(BuyGem::class)->buy($poor, $definition->id);
            $this->fail('copper check did not run');
        } catch (GameException $e) {
            $this->assertSame('铜币不足', $e->getMessage());
        }
        $this->assertSame(10, $poor->fresh()->copper);
        $this->assertSame(0, GameItem::query()->count());

        try {
            app(BuyGem::class)->buy($low, $definition->id);
            $this->fail('level check did not run');
        } catch (GameException $e) {
            $this->assertSame('等级不足', $e->getMessage());
        }
        $this->assertSame(500, $low->fresh()->copper);
    }
}
