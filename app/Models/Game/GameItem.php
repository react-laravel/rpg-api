<?php

namespace App\Models\Game;

use App\Services\Game\InventoryItemCalculator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $definition_id
 * @property int $quantity
 * @property bool $is_in_storage
 * @property bool $is_equipped
 * @property int|null $slot_index
 * @property int $sell_price
 * @property array<string,mixed> $stats
 * @property array<int,array<string,mixed>>|null $affixes
 * @property int|null $sockets
 * @property GameItemDefinition|null $definition
 * @property Collection|GameItemGem[] $gems
 */
class GameItem extends GameItemDefinition
{
    protected $table = 'game_items';

    protected $fillable = [
        'character_id',
        'definition_id',
        'quality',
        'stats',
        'affixes',
        'is_in_storage',
        'is_equipped',
        'quantity',
        'slot_index',
        'sockets',
        'sell_price',
    ];

    protected function casts(): array
    {
        return [
            'stats' => 'array',
            'affixes' => 'array',
            'is_in_storage' => 'boolean',
            'is_equipped' => 'boolean',
            'character_id' => 'integer',
            'definition_id' => 'integer',
            'quantity' => 'integer',
            'slot_index' => 'integer',
            'sockets' => 'integer',
            'sell_price' => 'integer',
        ];
    }

    public const QUALITIES = [
        'common',
        'magic',
        'rare',
        'legendary',
        'mythic',
    ];

    public const QUALITY_COLORS = [
        'common' => '#ffffff',
        'magic' => '#6888ff',
        'rare' => '#ffcc00',
        'legendary' => '#ff8000',
        'mythic' => '#00ff00',
    ];

    public const QUALITY_MULTIPLIERS = [
        'common' => 1.0,
        'magic' => 1.3,
        'rare' => 1.6,
        'legendary' => 2.0,
        'mythic' => 2.5,
    ];

    /**
     * 获取所属角色
     */
    public function character(): BelongsTo
    {
        return $this->belongsTo(GameCharacter::class, 'character_id');
    }

    /**
     * 获取物品定义
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(GameItemDefinition::class, 'definition_id');
    }

    /**
     * 获取装备上的宝石
     */
    public function gems(): HasMany
    {
        return $this->hasMany(GameItemGem::class, 'item_id')->orderBy('socket_index');
    }

    /**
     * 使用 bcmath 将数值规范为指定小数位，避免浮点精度问题(如暴击率 0.020000000000000004)
     *
     * @param  array<string, mixed>  $arr  键值对(如 stats、affixes)
     * @param  int  $scale  小数位数，率类属性用 4
     * @return array<string, mixed>
     */
    public static function normalizeStatsPrecision(array $arr, int $scale = 4): array
    {
        $result = [];
        foreach ($arr as $key => $value) {
            if (is_numeric($value)) {
                // Use PHP round to properly round to the given scale
                $result[$key] = round((float) $value, $scale, PHP_ROUND_HALF_UP);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * 获取完整属性(基础 + 随机词缀 + 宝石)
     */
    public function getTotalStats(): array
    {
        $stats = $this->stats ?? [];

        // 添加随机词缀属性
        foreach ($this->affixes ?? [] as $affix) {
            foreach ($affix as $key => $value) {
                $stats[$key] = bcadd((string) ($stats[$key] ?? 0), (string) $value, 4);
            }
        }

        // 添加宝石属性
        foreach ($this->gems ?? [] as $gem) {
            $gemStats = $gem->getGemStats();
            foreach ($gemStats as $key => $value) {
                $stats[$key] = bcadd((string) ($stats[$key] ?? 0), (string) $value, 4);
            }
        }

        return $stats;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        if (isset($array['stats']) && is_array($array['stats'])) {
            $array['stats'] = self::normalizeStatsPrecision($array['stats']);
        }
        if (isset($array['affixes']) && is_array($array['affixes'])) {
            $array['affixes'] = array_map(
                fn (array $affix): array => self::normalizeStatsPrecision($affix),
                $array['affixes']
            );
        }

        return $array;
    }

    /**
     * 获取品质颜色
     */
    public function getQualityColor(): string
    {
        return self::QUALITY_COLORS[$this->quality];
    }

    /**
     * 获取品质倍率
     */
    public function getQualityMultiplier(): float
    {
        return self::QUALITY_MULTIPLIERS[$this->quality];
    }

    /**
     * 获取物品名称(带品质前缀)
     */
    public function getDisplayName(): string
    {
        $prefix = match ($this->quality) {
            'magic' => '魔法 ',
            'rare' => '稀有 ',
            'legendary' => '传奇 ',
            'mythic' => '神话 ',
            default => '',
        };

        return $prefix . ($this->definition->name ?? '未知物品');
    }

    /**
     * 检查角色是否可以使用该物品
     */
    public function canEquip(GameCharacter $character): array
    {
        $definition = $this->definition;
        if (! $definition instanceof GameItemDefinition) {
            return [
                'can_equip' => false,
                'reason' => '该物品没有定义，无法装备',
            ];
        }

        if ($character->level < $definition->required_level) {
            return [
                'can_equip' => false,
                'reason' => "需要等级 {$definition->required_level}",
            ];
        }

        return [
            'can_equip' => true,
            'reason' => null,
        ];
    }

    /**
     * 计算物品卖出价格（委托 InventoryItemCalculator 统一计价）
     *
     * @return int 卖出价格(铜币)
     */
    public function calculateSellPrice(): int
    {
        if ($this->definition_id && ! $this->relationLoaded('definition')) {
            $this->load('definition');
        }

        if (! $this->relationLoaded('gems')) {
            $this->load('gems');
        }

        return (new InventoryItemCalculator)->calculateSellPrice($this);
    }
}
