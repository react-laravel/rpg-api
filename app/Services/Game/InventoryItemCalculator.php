<?php

namespace App\Services\Game;

use App\Models\Game\GameItem;
use App\Models\Game\GameItemDefinition;

/**
 * 物品定价统一入口（与背包/角色显示一致：铜币级属性计价）
 */
class InventoryItemCalculator
{
    /** 不参与计价的属性键 */
    private const PRICING_EXCLUDED_STATS = ['restore', 'price'];

    /** 装备卖出价为估价的 50% */
    private const EQUIPMENT_SELL_RATIO = 0.5;

    /** 商店购入价相对出售价的倍率（装备为收回 50% 折价，故买价≈卖价×2） */
    private const SHOP_BUY_TO_SELL_MULTIPLIER = 2;

    /**
     * 属性价格系数（每 1 点属性对应的基础铜币）。
     * 暴击率/暴伤按百分点计价，避免 1% 暴击比几十防御还贵一个数量级。
     */
    private const STAT_PRICES = [
        'attack' => 10,
        'defense' => 8,
        'max_hp' => 3,
        'max_mana' => 2,
        'strength' => 8,
        'dexterity' => 8,
        'vitality' => 6,
        'energy' => 6,
        'crit_rate' => 25,
        'crit_damage' => 12,
    ];

    /**
     * 物品类型价格系数（部位差距收窄，避免鞋带几十铜、武器几万铜）
     */
    private const TYPE_PRICE_MULTIPLIERS = [
        'weapon' => 1.15,
        'helmet' => 1.0,
        'armor' => 1.1,
        'gloves' => 0.95,
        'boots' => 0.95,
        'belt' => 0.95,
        'ring' => 1.15,
        'amulet' => 1.2,
        'gem' => 1.0,
    ];

    /** 按品质给出售价保底，避免一堆词缀的稀有装只值十几铜 */
    private const QUALITY_SELL_FLOOR = [
        'common' => 6,
        'magic' => 18,
        'rare' => 45,
        'legendary' => 100,
        'mythic' => 220,
    ];

    /**
     * 计算物品卖出价（背包/角色显示、背包出售、商店回收）
     */
    public function calculateSellPrice(GameItem $item): int
    {
        $definition = $item->definition;
        /** @var GameItemDefinition|null $definition */
        if (! $definition) {
            return 0;
        }

        return match ($definition->type) {
            'gem' => $this->calculateGemSellPrice($definition),
            default => $this->calculateEquipmentSellPrice($item),
        };
    }

    /**
     * 根据背包实例计算商店购入价（含词缀、宝石等完整属性）
     */
    public function calculateItemBuyPrice(GameItem $item): int
    {
        $definition = $item->definition;
        /** @var GameItemDefinition|null $definition */
        if (! $definition) {
            return 0;
        }

        $quality = $item->quality ?? 'common';
        $stats = $this->resolvePricingStats($item);

        return $this->calculateBuyPrice(
            $definition,
            $stats,
            $quality,
            (int) ($item->sockets ?? 0),
        );
    }

    /**
     * @return array<string, int|float>
     */
    private function resolvePricingStats(GameItem $item): array
    {
        $definition = $item->definition;
        if (! $definition instanceof GameItemDefinition) {
            return [];
        }

        if ($definition->type === 'gem') {
            $gemStats = $definition->gem_stats;

            return is_array($gemStats) ? $this->normalizePricingStats($gemStats) : [];
        }

        $totalStats = $item->getTotalStats();
        if ($totalStats !== []) {
            return $this->normalizePricingStats($totalStats);
        }

        $baseStats = $definition->base_stats;

        return is_array($baseStats) ? $this->normalizePricingStats($baseStats) : [];
    }

    /**
     * @param  array<string, mixed>  $stats
     * @return array<string, int|float>
     */
    private function normalizePricingStats(array $stats): array
    {
        $normalized = [];
        foreach ($stats as $stat => $value) {
            if (in_array($stat, self::PRICING_EXCLUDED_STATS, true) || ! is_numeric($value)) {
                continue;
            }
            $normalized[$stat] = (float) $value;
        }

        return $normalized;
    }

    /**
     * 计算商店购入价
     *
     * @param  array<string,int|float>  $stats
     */
    public function calculateBuyPrice(
        ?GameItemDefinition $item,
        array $stats = [],
        string $quality = 'common',
        int $sockets = 0,
    ): int {
        if (! $item) {
            return 0;
        }

        /** @var array<string, mixed>|null $baseStats */
        $baseStats = $item->base_stats;
        if (is_array($baseStats) && isset($baseStats['price']) && is_numeric($baseStats['price']) && (int) $baseStats['price'] > 0) {
            return (int) $baseStats['price'];
        }

        return match ($item->type) {
            'gem' => $stats !== []
                ? $this->calculateGemBuyPriceFromStats($stats)
                : $this->calculateGemBuyPrice($item),
            default => max(1, $this->calculateEquipmentFullPrice($item, $stats, $quality, $sockets, 0)),
        };
    }

    /**
     * 装备实例的估价（与商店购入价同一套属性公式，不含卖出折价）
     */
    public function calculateItemValue(GameItem $item): int
    {
        $definition = $item->definition;
        if (! $definition instanceof GameItemDefinition) {
            return 0;
        }

        if ($definition->type === 'gem') {
            return $this->calculateBuyPrice($definition, $this->resolvePricingStats($item), $item->quality ?? 'common');
        }

        return $this->calculateEquipmentValue(
            $definition,
            $this->resolvePricingStats($item),
            $item->quality ?? 'common',
            (int) ($item->sockets ?? 0),
            count($item->affixes ?? []),
        );
    }

    /**
     * @param  array<string, int|float>  $stats
     */
    public function calculateEquipmentValue(
        GameItemDefinition $definition,
        array $stats,
        string $quality = 'common',
        int $sockets = 0,
        int $affixCount = 0,
    ): int {
        return max(1, $this->calculateEquipmentFullPrice($definition, $stats, $quality, $sockets, $affixCount));
    }

    private function calculateEquipmentSellPrice(GameItem $item): int
    {
        $definition = $item->definition;
        if (! $definition instanceof GameItemDefinition) {
            return 0;
        }

        $quality = $item->quality ?? 'common';
        $fullPrice = $this->calculateEquipmentFullPrice(
            $definition,
            $this->resolvePricingStats($item),
            $quality,
            (int) ($item->sockets ?? 0),
            count($item->affixes ?? []),
        );

        $floor = self::QUALITY_SELL_FLOOR[$quality] ?? 6;

        return max($floor, (int) ($fullPrice * self::EQUIPMENT_SELL_RATIO));
    }

    /**
     * @param  array<string, int|float>  $stats
     */
    private function calculateEquipmentFullPrice(
        GameItemDefinition $definition,
        array $stats,
        string $quality,
        int $sockets,
        int $affixCount = 0,
    ): int {
        if ($stats === [] && is_array($definition->base_stats)) {
            $stats = $this->normalizePricingStats($definition->base_stats);
        }

        $basePrice = 0;
        foreach ($stats as $stat => $value) {
            $pricePerPoint = $this->resolveStatPrice($stat);
            $basePrice += (int) ($this->statValueToPricePoints($stat, (float) $value) * $pricePerPoint);
        }

        $qualityMultiplier = GameItem::QUALITY_MULTIPLIERS[$quality] ?? 1.0;
        $type = $definition->type ?? 'weapon';
        $typeMultiplier = self::TYPE_PRICE_MULTIPLIERS[$type] ?? 1.0;
        $requiredLevel = $definition->required_level ?? 1;
        $levelMultiplier = 1 + ($requiredLevel / 50);
        $affixMultiplier = 1 + 0.18 * max(0, $affixCount);
        $socketBonus = $sockets * 25;

        return (int) (($basePrice * $qualityMultiplier * $typeMultiplier * $levelMultiplier * $affixMultiplier) + $socketBonus);
    }

    private function calculateGemSellPrice(GameItemDefinition $definition): int
    {
        return $this->calculateGemPriceFromStats($definition->gem_stats ?? []);
    }

    private function calculateGemBuyPrice(GameItemDefinition $definition): int
    {
        return max(1, $this->calculateGemSellPrice($definition) * self::SHOP_BUY_TO_SELL_MULTIPLIER);
    }

    /**
     * @param  array<string, int|float>  $gemStats
     */
    public function calculateGemBuyPriceFromStats(array $gemStats): int
    {
        return max(1, $this->calculateGemPriceFromStats($gemStats) * self::SHOP_BUY_TO_SELL_MULTIPLIER);
    }

    /**
     * @param  array<string, int|float>  $gemStats
     */
    public function calculateGemSellPriceFromStats(array $gemStats): int
    {
        return $this->calculateGemPriceFromStats($gemStats);
    }

    /**
     * @param  array<string, mixed>  $gemStats
     */
    private function calculateGemPriceFromStats(array $gemStats): int
    {
        $price = 0;

        foreach ($gemStats as $stat => $value) {
            if (! is_numeric($value)) {
                continue;
            }
            $pricePerPoint = $this->resolveStatPrice($stat);
            $price += (int) ($this->statValueToPricePoints($stat, (float) $value) * $pricePerPoint);
        }

        return max(1, $price);
    }

    private function resolveStatPrice(string $stat): float
    {
        return (float) (self::STAT_PRICES[$stat] ?? 1);
    }

    /**
     * 将暴击率/暴伤等小数属性换算为计价用的「百分点」
     */
    private function statValueToPricePoints(string $stat, float $value): float
    {
        if (in_array($stat, ['crit_rate', 'crit_damage'], true) && abs($value) <= 1.0) {
            return $value * 100;
        }

        return $value;
    }
}
