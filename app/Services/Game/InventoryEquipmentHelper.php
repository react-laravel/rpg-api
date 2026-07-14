<?php

namespace App\Services\Game;

use App\Models\Game\GameCharacter;
use App\Models\Game\GameEquipment;
use App\Models\Game\GameItem;
use App\Models\Game\GameItemDefinition;

/**
 * 装备槽位辅助类
 */
class InventoryEquipmentHelper
{
    /**
     * 确定装备槽位
     */
    public function determineEquipmentSlot(GameCharacter $character, GameItem $item): string
    {
        /** @var GameItemDefinition|null $def */
        $def = $item->definition;
        if (! $def) {
            throw new \InvalidArgumentException('该物品没有定义，无法装备');
        }

        $slot = $def->getEquipmentSlot();
        if (! $slot) {
            throw new \InvalidArgumentException('该物品无法装备');
        }

        // 如果是戒指，检查两个戒指槽位
        if (($def->type ?? null) === 'ring') {
            $slot = $this->findAvailableRingSlot($character);
        }

        return $slot;
    }

    /**
     * 查找可用的戒指槽位
     */
    public function findAvailableRingSlot(GameCharacter $character): string
    {
        /** @var GameEquipment|null $ring */
        $ring = $character->equipment()->where('slot', 'ring')->first();
        if ($ring && ! $ring->item_id) {
            return 'ring';
        }

        return 'ring';
    }

    /**
     * 获取或创建装备槽位
     */
    public function getOrCreateEquipmentSlot(GameCharacter $character, string $slot): GameEquipment
    {
        /** @var GameEquipment|null $equipmentSlot */
        $equipmentSlot = $character->equipment()->where('slot', $slot)->first();

        if (! $equipmentSlot) {
            /** @var GameEquipment $equipmentSlot */
            $equipmentSlot = $character->equipment()->create(['slot' => $slot]);
        }

        return $equipmentSlot;
    }

    /**
     * 如果需要则卸下装备
     */
    public function handleUnequipIfNeeded(GameCharacter $character, GameEquipment $equipmentSlot): ?GameItem
    {
        $oldItem = null;

        if ($equipmentSlot->item_id) {
            $oldItem = GameItem::find($equipmentSlot->item_id);
            if ($oldItem) {
                $inventoryService = new GameInventoryService;
                $oldItem->is_equipped = false;
                $oldItem->slot_index = $inventoryService->findEmptySlot($character, false);
                $oldItem->save();
            }
        }

        return $oldItem;
    }

    /**
     * 检查物品是否已装备
     */
    public function isItemEquipped(GameCharacter $character, int $itemId): bool
    {
        return $character->equipment()->where('item_id', $itemId)->exists();
    }
}
