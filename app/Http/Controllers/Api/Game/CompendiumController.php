<?php

namespace App\Http\Controllers\Api\Game;

use App\Http\Controllers\Concerns\CharacterConcern;
use App\Http\Controllers\Controller;
use App\Models\Game\GameItemDefinition;
use App\Models\Game\GameMonsterDefinition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompendiumController extends Controller
{
    use CharacterConcern;

    /**
     * 获取物品图鉴
     */
    public function items(Request $request): JsonResponse
    {
        $items = GameItemDefinition::where('is_active', true)
            ->orderBy('type')
            ->orderBy('required_level')
            ->get();

        $character = $this->getCharacter($request);
        $discoveredItems = $character->discovered_items ?? [];

        // 为每个物品添加 discovered 字段
        $items = $items->map(function ($item) use ($discoveredItems) {
            return array_merge($item->toArray(), [
                'discovered' => in_array($item->id, $discoveredItems),
            ]);
        });

        return response()->json([
            'items' => $items,
            'total' => count($items),
            'discovered_count' => count($discoveredItems),
        ]);
    }

    /**
     * 获取怪物图鉴
     */
    public function monsters(Request $request): JsonResponse
    {
        $monsters = GameMonsterDefinition::where('is_active', true)
            ->orderBy('level')
            ->get();

        $character = $this->getCharacter($request);
        $discoveredMonsters = $character->discovered_monsters ?? [];

        // 为每个怪物添加 discovered 字段
        $monsters = $monsters->map(function ($monster) use ($discoveredMonsters) {
            return array_merge($monster->toArray(), [
                'discovered' => in_array($monster->id, $discoveredMonsters),
            ]);
        });

        return response()->json([
            'monsters' => $monsters,
            'total' => count($monsters),
            'discovered_count' => count($discoveredMonsters),
        ]);
    }

    /**
     * 获取怪物掉落表（属性按当前角色难度缩放）
     */
    public function monsterDrops(Request $request, int $monster): JsonResponse
    {
        $monster = GameMonsterDefinition::where('is_active', true)
            ->findOrFail($monster);

        $character = $this->getCharacter($request);
        $difficulty = $character->getDifficultyMultipliers();
        $stats = $monster->getCombatStats();
        $monsterPayload = array_merge($monster->toArray(), [
            'hp_base' => (int) ($stats['hp'] * (float) $difficulty['monster_hp']),
            'attack_base' => (int) ($stats['attack'] * (float) $difficulty['monster_damage']),
            'defense_base' => (int) ($stats['defense'] * (float) $difficulty['monster_damage']),
            'experience_base' => (int) ($stats['experience'] * (float) $difficulty['reward']),
        ]);

        $dropTable = is_array($monster->drop_table) ? $monster->drop_table : [];

        // 计算掉落概率
        $baseDropChance = $dropTable['item_chance'] ?? 0.1;
        $typeMultiplier = config('game.monster_type_multipliers')[$monster->type] ?? 0.1;
        $dropChance = $baseDropChance * $typeMultiplier;

        // 铜币掉落率
        $goldChance = $dropTable['copper_chance'] ?? 0.7;

        // 解析掉落表中的物品类型
        $itemTypes = $dropTable['item_types'] ?? ['weapon', 'helmet', 'armor', 'gloves', 'boots', 'ring', 'amulet'];

        // 获取对应类型的物品定义
        $items = GameItemDefinition::where('is_active', true)
            ->whereIn('type', $itemTypes)
            ->where('required_level', '<=', $monster->level + 3)
            ->orderBy('required_level')
            ->limit(20)
            ->get();

        // 计算每个物品的权重(基于物品等级和品质)
        // 权重公式：基础权重 + 等级加成 - 品质惩罚(越好的物品权重越低)
        $itemsArray = $items->toArray();
        $totalWeight = 0;
        $itemsWithRates = array_map(function ($item) use (&$totalWeight, $monster) {
            // 基础权重
            $baseWeight = 10;
            // 等级加成(等级越高权重越高一点)
            $levelBonus = $item['required_level'];
            // 品质权重( mythic < legendary < rare < magic < common)
            $qualityWeights = [
                'mythic' => 1,
                'legendary' => 3,
                'rare' => 7,
                'magic' => 15,
                'common' => 30,
            ];
            // 根据物品等级生成随机品质，计算权重
            $quality = $this->generateQualityForItem($item, $monster->level);
            $qualityWeight = $qualityWeights[$quality] ?? 15;

            $weight = $baseWeight + $levelBonus - $qualityWeight;
            $weight = max(1, $weight); // 最小权重为 1

            $item['weight'] = $weight;
            $item['quality'] = $quality;
            $totalWeight += $weight;

            return $item;
        }, $itemsArray);

        // 计算每个物品的掉落概率(基于权重)
        $itemsWithRates = array_map(function ($item) use ($dropChance, $totalWeight) {
            $itemDropRate = $totalWeight > 0 ? ($item['weight'] / $totalWeight) * ($dropChance * 100) : 0;
            $item['drop_rate'] = round($itemDropRate, 2);

            return $item;
        }, $itemsWithRates);

        return response()->json([
            'monster' => $monsterPayload,
            'drop_table' => $dropTable,
            'drop_rates' => [
                'item' => round($dropChance * 100, 2),
                'gold' => round($goldChance * 100, 2),
            ],
            'possible_items' => $itemsWithRates,
        ]);
    }

    /**
     * 为物品生成品质(模拟实际掉落时的品质)
     */
    private function generateQualityForItem(array $item, int $monsterLevel): string
    {
        $roll = mt_rand(1, 10000) / 100;

        return match (true) {
            $roll >= 99 => 'mythic',
            $roll >= 95 => 'legendary',
            $roll >= 85 => 'rare',
            $roll >= 60 => 'magic',
            default => 'common',
        };
    }
}
