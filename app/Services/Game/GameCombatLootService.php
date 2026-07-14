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
        $qualityMultiplier = GameItem::QUALITY_MULTIPLIERS[$quality] ?? 1.0;
        $stats = [];

        // 按部位分类过滤基础属性
        $equipmentCategory = $this->getEquipmentStatCategory($definition->type);
        /** @var array<string, mixed> $baseStatsArr */
        $baseStatsArr = $definition->base_stats ?? [];
        $filteredBaseStats = $equipmentCategory !== null
            ? $this->filterBaseStatsByCategory($baseStatsArr, $equipmentCategory)
            : $baseStatsArr;
        foreach ($filteredBaseStats as $stat => $value) {
            if (! is_numeric($value)) {
                continue;
            }

            $scaledValue = (float) $value * $qualityMultiplier * (0.8 + rand(0, 40) / 100);
            $statValue = in_array($stat, ['crit_rate', 'crit_damage'], true)
                ? round($scaledValue, 4)
                : ($scaledValue > 0 ? max(1, (int) round($scaledValue)) : (int) round($scaledValue));

            if ($statValue !== 0 && $statValue !== 0.0) {
                $stats[$stat] = $statValue;
            }
        }

        // Affixes and sockets
        $affixes = [];
        $sockets = 0;
        if ($quality !== 'common') {
            $affixCount = match ($quality) {
                'magic' => rand(1, 2),
                'rare' => rand(2, 3),
                'legendary' => rand(3, 4),
                'mythic' => rand(4, 5),
                default => 0,
            };

            // 按部位分类构建词缀池
            $possibleAffixes = $this->buildAffixPoolForCategory($equipmentCategory);
            shuffle($possibleAffixes);
            $affixes = array_slice($possibleAffixes, 0, $affixCount);

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

        $recycled = $inventoryService->tryAutoRecycleItem($character->fresh(), $item);
        if ($recycled !== null) {
            return null;
        }

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
            'icon' => 'gem',
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

    /**
     * 判断物品类型所属的属性分类
     *
     * @return 'defense'|'offense'|null
     */
    private function getEquipmentStatCategory(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }

        $defenseTypes = ['helmet', 'armor', 'gloves', 'boots', 'belt'];
        $offenseTypes = ['weapon', 'ring', 'amulet'];

        return in_array($type, $defenseTypes, true) ? 'defense'
            : (in_array($type, $offenseTypes, true) ? 'offense' : null);
    }

    /**
     * 按部位分类过滤基础属性（仅保留该类别允许的属性）
     */
    private function filterBaseStatsByCategory(array $baseStats, string $category): array
    {
        if ($baseStats === []) {
            return [];
        }

        $allowedStats = match ($category) {
            'defense' => config('game.defense_stat_categories', []),
            'offense' => config('game.offense_stat_categories', []),
            default => [],
        };

        if ($allowedStats === []) {
            return $baseStats;
        }

        // all_stats 始终保留
        $allowedStats[] = 'all_stats';

        return array_filter($baseStats, fn (string $stat): bool => in_array($stat, $allowedStats, true), ARRAY_FILTER_USE_KEY);
    }

    /**
     * 按部位分类构建词缀池
     *
     * @return array<int, array<string, int|float>>
     */
    private function buildAffixPoolForCategory(?string $category): array
    {
        $offenseAffixes = [
            ['attack' => rand(1, 4)],
            ['crit_rate' => rand(1, 5) / 100],
            ['crit_damage' => rand(10, 30) / 100],
            ['strength' => rand(1, 3)],
            ['dexterity' => rand(1, 2)],
            ['energy' => rand(1, 2)],
        ];

        $defenseAffixes = [
            ['defense' => rand(1, 3)],
            ['max_hp' => rand(2, 10)],
            ['max_mana' => rand(1, 6)],
        ];

        return match ($category) {
            'defense' => $defenseAffixes,
            'offense' => $offenseAffixes,
            default => array_merge($offenseAffixes, $defenseAffixes),
        };
    }
}
