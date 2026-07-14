<?php

namespace App\Models\Game;

use App\Support\Game\RpgAssetIconNormalizer;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $type
 * @property string|null $sub_type
 * @property array<string,mixed> $base_stats
 * @property int $required_level
 * @property string|null $icon
 * @property string|null $description
 * @property bool $is_active
 * @property int|null $buy_price
 */
class GameItemDefinition extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'name',
        'type',
        'sub_type',
        'base_stats',
        'required_level',
        'icon',
        'icon_prompt',
        'description',
        'is_active',
        'sockets',
        'gem_stats',
        'buy_price',
    ];

    protected function casts(): array
    {
        return [
            'base_stats' => 'array',
            'is_active' => 'boolean',
            'gem_stats' => 'array',
            'sockets' => 'integer',
            'required_level' => 'integer',
            'buy_price' => 'integer',
        ];
    }

    protected function icon(): Attribute
    {
        return Attribute::get(
            fn (?string $value): ?string => RpgAssetIconNormalizer::normalizeItem($value)
        );
    }

    public const TYPES = [
        'weapon',
        'helmet',
        'armor',
        'gloves',
        'boots',
        'belt',
        'ring',
        'amulet',
        'potion',
        'gem',
    ];

    public const SUB_TYPES = [
        'sword',
        'axe',
        'mace',
        'staff',
        'bow',
        'dagger',
        'cloth',
        'leather',
        'mail',
        'plate',
    ];

    /**
     * 获取槽位映射(物品类型 -> 装备槽位)
     */
    public function getEquipmentSlot(): ?string
    {
        return match ($this->type) {
            'weapon' => 'weapon',
            'helmet' => 'helmet',
            'armor' => 'armor',
            'gloves' => 'gloves',
            'boots' => 'boots',
            'belt' => 'belt',
            'ring' => 'ring',
            'amulet' => 'amulet', // 护符槽位
            default => null,
        };
    }

    /**
     * 获取物品的基础属性
     */
    public function getBaseStats(): array
    {
        return $this->base_stats ?? [];
    }
}
