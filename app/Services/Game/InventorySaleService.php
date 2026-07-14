<?php

namespace App\Services\Game;

use App\Models\Game\GameCharacter;
use App\Models\Game\GameItem;
use App\Services\Game\Traits\UsesDistributedLock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 背包出售与自动回收服务
 */
class InventorySaleService
{
    use UsesDistributedLock;

    private const CACHE_PREFIX = 'game_inventory:';

    private const ITEM_LOCK_TIMEOUT = 10;

    public function __construct(
        private InventoryEquipmentHelper $equipmentHelper = new InventoryEquipmentHelper
    ) {}

    /**
     * @return array{copper:int, sell_price:int}
     */
    public function sellItem(GameCharacter $character, int $itemId, int $quantity = 1): array
    {
        return $this->executeWithDistributedLock(
            lockKey: 'game:inventory:item:' . $character->id . ':' . $itemId,
            callback: fn () => $this->performSell($character, $itemId, $quantity),
            timeoutSeconds: self::ITEM_LOCK_TIMEOUT,
        );
    }

    /**
     * @return array{count: int, total_price: int, copper: int}
     */
    public function sellItemsByQuality(GameCharacter $character, string $quality): array
    {
        $items = $quality === 'all'
            ? $this->getSellableInventoryItems($character)
            : $this->getSellableItemsByQuality($character, $quality);

        return $this->sellItems($character, $items);
    }

    /**
     * @return array{
     *     character: GameCharacter,
     *     recycled: array{count: int, total_price: int, copper: int}
     * }
     */
    public function updateAutoRecycleSettings(GameCharacter $character, ?int $maxValue): array
    {
        $normalized = ($maxValue !== null && $maxValue > 0) ? $maxValue : null;

        return DB::transaction(function () use ($character, $normalized) {
            $character->auto_recycle_max_value = $normalized;
            $character->save();

            $recycled = $normalized === null
                ? $this->emptySaleResult($character)
                : $this->sellItemsAtOrBelowValue($character, $normalized);

            return [
                'character' => $character->fresh(),
                'recycled' => $recycled,
            ];
        });
    }

    /**
     * @return array{
     *     sold_item_id: int,
     *     sold_item_name: string,
     *     sold_price: int,
     *     copper: int
     * }|null
     */
    public function sellCheapestInventoryItemByType(
        GameCharacter $character,
        string $type,
        ?string $subType = null,
    ): ?array {
        $equippedItemIds = $character->equipment()->pluck('item_id');

        /** @var \Illuminate\Database\Eloquent\Collection<int, GameItem> $items */
        $items = $character->items()
            ->where('is_in_storage', false)
            ->where(function ($query) {
                $query->where('is_equipped', false)->orWhereNull('is_equipped');
            })
            ->whereHas('definition', function ($query) use ($type, $subType) {
                $query->where('type', $type);
                if ($subType !== null) {
                    $query->where('sub_type', $subType);
                }
            })
            ->with('definition')
            ->get()
            ->filter(fn ($item): bool => $item instanceof GameItem && ! $equippedItemIds->contains($item->id));

        if ($items->isEmpty()) {
            return null;
        }

        $this->ensureItemsSellPrice($items);

        /** @var GameItem|null $cheapest */
        $cheapest = $items
            ->sortBy(fn (GameItem $item) => $item->calculateSellPrice())
            ->first();

        if ($cheapest === null) {
            return null;
        }

        $soldName = $cheapest->definition->name ?? '物品';
        $soldId = $cheapest->id;
        $recycled = $this->sellSingleItem($character, $cheapest);

        return [
            'sold_item_id' => $soldId,
            'sold_item_name' => $soldName,
            'sold_price' => $recycled['total_price'],
            'copper' => $recycled['copper'],
        ];
    }

    /**
     * @return array{count: int, total_price: int, copper: int}|null
     */
    public function tryAutoRecycleItem(GameCharacter $character, GameItem $item): ?array
    {
        $maxValue = $character->auto_recycle_max_value;
        if ($maxValue === null || $maxValue <= 0) {
            return null;
        }

        if (! $this->shouldAutoRecycleItem($character, $item, $maxValue)) {
            return null;
        }

        return $this->sellSingleItem($character, $item);
    }

    /**
     * @return array{count: int, total_price: int, copper: int}
     */
    public function sellItemsAtOrBelowValue(GameCharacter $character, int $maxValue): array
    {
        $items = $this->getSellableInventoryItems($character)
            ->filter(fn (GameItem $item) => $item->calculateSellPrice() <= $maxValue);

        return $this->sellItems($character, $items);
    }

    /**
     * @return array{copper:int, sell_price:int}
     */
    private function performSell(GameCharacter $character, int $itemId, int $quantity): array
    {
        $item = $this->findItem($character, $itemId);

        if ($this->equipmentHelper->isItemEquipped($character, $itemId)) {
            throw new \InvalidArgumentException('请先卸下装备');
        }

        if ($item->quantity < $quantity) {
            throw new \InvalidArgumentException('物品数量不足');
        }

        $sellPrice = $item->calculateSellPrice() * $quantity;

        return DB::transaction(function () use ($character, $item, $quantity, $sellPrice) {
            $character->copper += $sellPrice;
            $character->save();

            if ($item->quantity > $quantity) {
                $item->quantity -= $quantity;
                $item->save();
            } else {
                $item->delete();
            }

            $this->clearInventoryCache($character->id);

            return [
                'copper' => $character->copper,
                'sell_price' => $sellPrice,
            ];
        });
    }

    /**
     * @param  Collection<int, GameItem>|\Illuminate\Database\Eloquent\Collection<int, GameItem>  $items
     * @return array{count: int, total_price: int, copper: int}
     */
    private function sellItems(GameCharacter $character, Collection|\Illuminate\Database\Eloquent\Collection $items): array
    {
        if ($items->isEmpty()) {
            return $this->emptySaleResult($character);
        }

        return DB::transaction(function () use ($character, $items) {
            $totalPrice = 0;
            $count = 0;
            $equippedItemIds = $character->equipment()->pluck('item_id');

            foreach ($items as $item) {
                if ($equippedItemIds->contains($item->id)) {
                    continue;
                }

                $totalPrice += $item->calculateSellPrice() * $item->quantity;
                $count++;
                $item->delete();
            }

            $character->copper += $totalPrice;
            $character->save();
            $this->clearInventoryCache($character->id);

            return [
                'count' => $count,
                'total_price' => $totalPrice,
                'copper' => $character->copper,
            ];
        });
    }

    /**
     * @return array{count: int, total_price: int, copper: int}
     */
    private function emptySaleResult(GameCharacter $character): array
    {
        return [
            'count' => 0,
            'total_price' => 0,
            'copper' => $character->copper,
        ];
    }

    /**
     * @param  Collection<int, GameItem>|\Illuminate\Database\Eloquent\Collection<int, GameItem>  $items
     */
    private function ensureItemsSellPrice(Collection|\Illuminate\Database\Eloquent\Collection $items): void
    {
        foreach ($items as $item) {
            $newPrice = $item->calculateSellPrice();
            if ($item->sell_price !== $newPrice) {
                $item->sell_price = $newPrice;
                $item->saveQuietly();
            }
        }
    }

    private function findItem(GameCharacter $character, int $itemId): GameItem
    {
        $item = GameItem::query()
            ->where('id', $itemId)
            ->where('character_id', $character->id)
            ->where('is_in_storage', false)
            ->where(function ($query) {
                $query->where('is_equipped', false)->orWhereNull('is_equipped');
            })
            ->with('definition')
            ->first();

        if (! $item) {
            throw new \InvalidArgumentException('物品不存在或不属于你');
        }

        return $item;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, GameItem>
     */
    private function getSellableItemsByQuality(GameCharacter $character, string $quality): \Illuminate\Database\Eloquent\Collection
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, GameItem> $result */
        $result = $character->items()
            ->where('is_in_storage', false)
            ->where('quality', $quality)
            ->whereHas('definition', fn ($query) => $query->where('type', '!=', 'gem'))
            ->with('definition')
            ->get();

        return $result;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, GameItem>
     */
    private function getSellableInventoryItems(GameCharacter $character): \Illuminate\Database\Eloquent\Collection
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, GameItem> $result */
        $result = $character->items()
            ->where('is_in_storage', false)
            ->whereHas('definition', fn ($query) => $query->where('type', '!=', 'gem'))
            ->with('definition')
            ->get();

        return $result;
    }

    private function shouldAutoRecycleItem(GameCharacter $character, GameItem $item, int $maxValue): bool
    {
        if ($item->is_in_storage || $item->is_equipped) {
            return false;
        }

        if ($this->equipmentHelper->isItemEquipped($character, $item->id)) {
            return false;
        }

        if ($item->definition?->type === 'gem') {
            return false;
        }

        return $item->calculateSellPrice() <= $maxValue;
    }

    /**
     * @return array{count: int, total_price: int, copper: int}
     */
    private function sellSingleItem(GameCharacter $character, GameItem $item): array
    {
        return DB::transaction(function () use ($character, $item) {
            $price = $item->calculateSellPrice() * $item->quantity;
            $item->delete();

            $character->copper += $price;
            $character->save();
            $this->clearInventoryCache($character->id);

            return [
                'count' => 1,
                'total_price' => $price,
                'copper' => $character->copper,
            ];
        });
    }

    private function clearInventoryCache(int $characterId): void
    {
        Cache::forget(self::CACHE_PREFIX . $characterId);
    }
}
