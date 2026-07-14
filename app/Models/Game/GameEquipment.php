<?php

namespace App\Models\Game;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property GameItem|null $item
 * @property string $slot
 */
class GameEquipment extends Model
{
    protected $fillable = [
        'character_id',
        'slot',
        'item_id',
    ];

    /**
     * 装备槽位列表(与 GameCharacter 共用 config)
     *
     * @return array<int, string>
     */
    public static function getSlots(): array
    {
        return config('game.slots', [
            'weapon', 'helmet', 'armor', 'gloves', 'boots', 'belt', 'ring', 'amulet',
        ]);
    }

    /**
     * 获取所属角色
     */
    public function character(): BelongsTo
    {
        return $this->belongsTo(GameCharacter::class, 'character_id');
    }

    /**
     * 获取装备的物品
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(GameItem::class, 'item_id');
    }

    protected function casts(): array
    {
        return [
            'character_id' => 'integer',
            'item_id' => 'integer',
        ];
    }
}
