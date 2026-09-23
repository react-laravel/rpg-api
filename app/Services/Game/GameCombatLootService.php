<?php

namespace App\Services\Game;

use App\Models\Game\GameCharacter;
use App\Models\Game\GameItem;
use App\Models\Game\GameItemDefinition;
use App\Models\Game\GameMonsterDefinition;

class GameCombatLootService
{
    private ?GameInventoryService $inventoryService = null;

    /**
     * Get inventory service instance (lazy initialization)
     */
    private function getInventoryService(): GameInventoryService
    {
        return $this->inventoryService ??= new GameInventoryService;
    }

    /**
     * Create a GameItem with common attributes and save to database
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createGameItem(GameCharacter $character, array $attributes): GameItem
    {
        $item = new GameItem(array_merge([
            'character_id' => $character->id,
            'quality' => 'common',
            'stats' => [],
            'affixes' => [],
            'is_in_storage' => false,
            'quantity' => 1,
            'sockets' => 0,
        ], $attributes));

        $item->sell_price = $item->calculateSellPrice();
        $item->save();

        return $item;
    }

    /**
     * Process death loot from monsters
     */
    public function processDeathLoot(GameCharacter $character, array $roundResult): array
    {
        $loot = $roundResult['loot'] ?? [];
        $monstersUpdated = $roundResult['monsters_updated'] ?? [];

        foreach ($monstersUpdated as $m) {
            if (! is_array($m) || ($m['hp'] ?? 0) > 0) {
                continue;
            }
            // Monster died, try to generate loot
            $monster = GameMonsterDefinition::query()->find($m['id'] ?? 0);
            if (! $monster) {
                continue;
            }

            // 发现怪物
            $character->discoverMonster($monster->id);

            $lootResult = $monster->generateLoot($character->level);
            if (isset($lootResult['item']) && ! isset($loot['item'])) {
                $item = $this->createItem($character, $lootResult['item']);
                if ($item) {
                    $loot['item'] = $item;
                }
            }
        }

        return $loot;
    }

    /**
     * Distribute experience and copper to character
     */
    public function distributeRewards(GameCharacter $character, array $roundResult): array
    {
        $loot = $roundResult['loot'] ?? [];

        // Grant experience
        $expGained = $roundResult['experience_gained'] ?? 0;
        if ($expGained > 0) {
            $character->addExperience($expGained);
        }

        // Grant copper
        $copperGained = $roundResult['copper_gained'] ?? 0;
        if ($copperGained > 0) {
            $character->copper += $copperGained;
            $character->save();
            $loot = array_merge($loot, ['copper' => $copperGained]);
        }

        return [
            'loot' => $loot,
            'experience_gained' => $expGained,
            'copper_gained' => $copperGained,
        ];
    }

    /**
     * Create a loot item
     */
    public function createItem(GameCharacter $character, array $itemData): ?GameItem
    {
        $definition = GameItemDefinition::query()
            ->where('type', $itemData['type'])
            ->where('required_level', '<=', $itemData['level'])
            ->where('is_active', true)
            ->inRandomOrder()
            ->first();

        if (! $definition) {
            return null;
        }

        $inventoryService = $this->getInventoryService();

        if ($character->isInventoryFull()) {
            $freed = $inventoryService->sellCheapestInventoryItemByType($character, $definition->type);
            if ($freed === null) {
                return null;
            }
            $character->refresh();
        }

        $quality = $itemData['quality'];
        $stats = $definition->base_stats ?? [];

        // Combat stats are fixed by definition; quality only affects sockets and value.
        $affixes = [];
        $sockets = 0;
        if ($quality !== 'common') {
            if (in_array($definition->type, ['weapon', 'helmet', 'armor', 'gloves', 'boots', 'belt', 'ring', 'amulet'])) {
                $sockets = match ($quality) {
                    'magic' => rand(0, 1),
                    'rare' => rand(1, 2),
                    'legendary' => rand(2, 3),
                    'mythic' => 3,
                    default => 0,
                };
                $sockets = min($sockets, (int) config('game.max_item_sockets', 3));
            }
        }

        $item = $this->createGameItem($character, [
            'definition_id' => $definition->id,
            'quality' => $quality,
            'stats' => $stats,
            'affixes' => $affixes,
            'slot_index' => $inventoryService->findEmptySlot($character, false),
            'sockets' => $sockets,
        ]);

        // 发现物品
        $character->discoverItem($definition->id);

        return $item->load('definition');
    }

    /**
     * Create a loot gem
     */
    public function createGem(GameCharacter $character, int $level): ?GameItem
    {
        $gemTypes = [
            ['attack' => rand(2, 5), 'name' => '攻击宝石'],
            ['defense' => rand(1, 3), 'name' => '防御宝石'],
            ['max_hp' => rand(3, 8), 'name' => '生命宝石'],
            ['max_mana' => rand(2, 6), 'name' => '法力宝石'],
            ['crit_rate' => rand(1, 3) / 100, 'name' => '暴击宝石'],
            ['crit_damage' => rand(5, 15) / 100, 'name' => '暴伤宝石'],
        ];

        $selectedGem = $gemTypes[array_rand($gemTypes)];
        $gemStats = $selectedGem;
        unset($gemStats['name']);

        $inventoryService = $this->getInventoryService();

        if ($character->isInventoryFull()) {
            $freed = $inventoryService->sellCheapestInventoryItemByType($character, 'gem');
            if ($freed === null) {
                return null;
            }
            $character->refresh();
        }

        // 根据宝石属性计算价格
        $gemValue = 0;
        foreach ($gemStats as $stat => $value) {
            $gemValue += (int) ($value * 100); // 每个属性点 100 金币
        }

        $definition = GameItemDefinition::create([
            'name' => $selectedGem['name'],
            'type' => 'gem',
            'sub_type' => null,
            'base_stats' => [],
            'required_level' => 1,
            'icon' => GameItemDefinition::GEM_ICONS[array_key_first($gemStats)] ?? 'gem',
            'description' => '可镶嵌到装备上，提升属性',
            'is_active' => true,
            'sockets' => 0,
            'gem_stats' => $gemStats,
            'buy_price' => max(10, $gemValue), // 最低 10 金币
        ]);

        $gem = $this->createGameItem($character, [
            'definition_id' => $definition->id,
            'slot_index' => $inventoryService->findEmptySlot($character, false),
        ]);

        // 发现物品
        $character->discoverItem($definition->id);

        return $gem->load('definition');
    }
}
