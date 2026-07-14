<?php

namespace App\Models\Game;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameCharacterMap extends Model
{
    use HasFactory;

    protected $table = 'game_character_maps';

    protected $fillable = [
        'character_id',
        'map_id',
        'progress',
        'best_score',
        'visited_at',
    ];

    protected $casts = [
        'visited_at' => 'datetime',
        'progress' => 'integer',
        'best_score' => 'integer',
    ];

    public function character(): BelongsTo
    {
        return $this->belongsTo(GameCharacter::class, 'character_id');
    }

    public function map(): BelongsTo
    {
        return $this->belongsTo(GameMapDefinition::class, 'map_id');
    }
}
