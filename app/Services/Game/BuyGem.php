<?php

namespace App\Services\Game;

use App\Exceptions\GameException;
use App\Models\Game\GameCharacter;
use App\Models\Game\GameItem;
use App\Models\Game\GameItemDefinition;

/**
 * 用铜币购买现成的宝石定义。孔位仍然只来自装备掉落。
 */
final class BuyGem
{
    public function __construct(
        private readonly GameInventoryService $inventory = new GameInventoryService,
        private readonly InventoryItemCalculator $prices = new InventoryItemCalculator,
    ) {}

    /**
     * @return list<array{id: int, name: string, icon: string|null, required_level: int, gem_stats: array<string, int|float>, buy_price: int}>
     */
    public function catalog(): array
    {
        return GameItemDefinition::query()
            ->where('type', 'gem')
            ->where('is_active', true)
            ->orderBy('required_level')
            ->orderBy('id')
            ->get()
            ->map(function (GameItemDefinition $gem): array {
                $stats = is_array($gem->gem_stats) ? $gem->gem_stats : [];

                return [
                    'id' => (int) $gem->id,
                    'name' => (string) $gem->name,
                    'icon' => $gem->icon,
                    'required_level' => (int) $gem->required_level,
                    'gem_stats' => $stats,
                    'buy_price' => $this->prices->calculateGemBuyPriceFromStats($stats),
                ];
            })
            ->all();
    }

    /**
     * @return array{item: GameItem, copper: int}
     */
    public function buy(GameCharacter $character, int $definitionId): array
    {
        $definition = GameItemDefinition::query()
            ->where('type', 'gem')
            ->where('is_active', true)
            ->find($definitionId);
        if (! $definition instanceof GameItemDefinition) {
            throw GameException::invalidOperation('宝石不存在');
        }
        if ((int) $character->level < (int) $definition->required_level) {
            throw GameException::insufficientLevel('等级不足');
        }
        if ($character->isInventoryFull()) {
            throw GameException::invalidOperation('背包已满');
        }

        $stats = is_array($definition->gem_stats) ? $definition->gem_stats : [];
        $price = $this->prices->calculateGemBuyPriceFromStats($stats);
        $paid = GameCharacter::query()
            ->whereKey($character->id)
            ->where('copper', '>=', $price)
            ->decrement('copper', $price);
        if ($paid === 0) {
            throw GameException::insufficientResources('铜币不足');
        }

        $character->refresh();
        $slot = $this->inventory->findEmptySlot($character, false);
        if ($slot === null) {
            $character->increment('copper', $price);

            throw GameException::invalidOperation('背包已满');
        }

        $item = new GameItem([
            'character_id' => $character->id,
            'definition_id' => $definition->id,
            'quality' => 'common',
            'stats' => [],
            'affixes' => [],
            'is_in_storage' => false,
            'is_equipped' => false,
            'quantity' => 1,
            'slot_index' => $slot,
            'sockets' => 0,
        ]);
        $item->sell_price = $item->calculateSellPrice();
        $item->save();
        $character->discoverItem($definition->id);
        $character->refresh();

        return [
            'item' => $item->load('definition'),
            'copper' => (int) $character->copper,
        ];
    }
}
