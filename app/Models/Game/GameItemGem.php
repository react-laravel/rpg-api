<?php

namespace App\Models\Game;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 装备上镶嵌的宝石
 */
class GameItemGem extends Model
{
    protected $fillable = [
        'item_id',
        'gem_definition_id',
        'socket_index',
    ];

    /**
     * 获取装备
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(GameItem::class, 'item_id');
    }

    /**
     * 获取宝石定义
     */
    public function gemDefinition(): BelongsTo
    {
        return $this->belongsTo(GameItemDefinition::class, 'gem_definition_id');
    }

    /**
     * 获取宝石属性
     */
    public function getGemStats(): array
    {
        return $this->gemDefinition !== null ? ($this->gemDefinition->gem_stats ?? []) : [];
    }

    protected function casts(): array
    {
        return [
            'item_id' => 'integer',
            'gem_definition_id' => 'integer',
            'socket_index' => 'integer',
        ];
    }
}
