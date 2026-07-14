<?php

namespace App\Models\Game;

use App\Support\Game\RpgAssetIconNormalizer;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property mixed $id
 * @property mixed $damage
 * @property mixed $mana_cost
 * @property mixed $cooldown
 * @property mixed $type
 * @property mixed $target_type
 * @property mixed $base_damage
 * @property string|null $icon
 */
class GameSkillDefinition extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'type',
        'class_restriction',
        'damage',
        'mana_cost',
        'cooldown',
        'icon',
        'effect_key',
        'icon_prompt',
        'effects',
        'target_type',
        'is_active',
        'base_damage',
        'skill_points_cost',
        'branch',
        'tier',
        'skill_stage',
        'skill_line',
        'node_tier',
        'spec_branch',
        'unlock_level',
        'prerequisite_skill_id',
        'prerequisite_effect_key',
    ];

    protected function casts(): array
    {
        return [
            'effects' => 'array',
            'is_active' => 'boolean',
            'cooldown' => 'float',
            'base_damage' => 'integer',
            'tier' => 'integer',
            'node_tier' => 'integer',
            'unlock_level' => 'integer',
            'prerequisite_skill_id' => 'integer',
            'mana_cost' => 'integer',
            'skill_points_cost' => 'integer',
        ];
    }

    public const TYPES = ['active', 'passive'];

    public const CLASS_RESTRICTIONS = ['warrior', 'mage', 'ranger', 'all'];

    protected function icon(): Attribute
    {
        return Attribute::get(
            fn (?string $value): ?string => RpgAssetIconNormalizer::normalizeSkill($value)
        );
    }

    /**
     * 检查职业是否可以使用该技能
     */
    public function canLearnByClass(string $class): bool
    {
        return $this->class_restriction === 'all' || $this->class_restriction === $class;
    }
}
