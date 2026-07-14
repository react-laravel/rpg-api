<?php

namespace App\Models\Game;

use App\Support\Game\RpgAssetIconNormalizer;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<string, mixed>|null $drop_table
 * @property string|null $icon
 */
class GameMonsterDefinition extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'level',
        'hp_base',
        'attack_base',
        'defense_base',
        'experience_base',
        'drop_table',
        'icon',
        'icon_prompt',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'drop_table' => 'array',
            'is_active' => 'boolean',
            'level' => 'integer',
            'hp_base' => 'integer',
            'hp_per_level' => 'integer',
            'attack_base' => 'integer',
            'attack_per_level' => 'integer',
            'defense_base' => 'integer',
            'defense_per_level' => 'integer',
            'experience_base' => 'integer',
            'experience_per_level' => 'integer',
        ];
    }

    public const TYPES = ['normal', 'elite', 'boss'];

    protected function icon(): Attribute
    {
        return Attribute::get(
            fn (?string $value): ?string => RpgAssetIconNormalizer::normalizeMonster($value)
        );
    }

    /**
     * 获取战斗日志
     */
    public function combatLogs(): HasMany
    {
        return $this->hasMany(GameCombatLog::class, 'monster_id');
    }

    /**
     * 获取生命值(直接返回数据库值)
     */
    public function getHp(): int
    {
        return (int) $this->hp_base;
    }

    /**
     * 获取攻击力(直接返回数据库值)
     */
    public function getAttack(): int
    {
        return (int) $this->attack_base;
    }

    /**
     * 获取防御力(直接返回数据库值)
     */
    public function getDefense(): int
    {
        return (int) $this->defense_base;
    }

    /**
     * 获取经验值(直接返回数据库值)
     */
    public function getExperience(): int
    {
        return (int) $this->experience_base;
    }

    /**
     * 获取完整战斗属性
     *
     * @return array{hp:int,attack:int,defense:int,experience:int}
     */
    public function getCombatStats(): array
    {
        return [
            'hp' => $this->getHp(),
            'attack' => $this->getAttack(),
            'defense' => $this->getDefense(),
            'experience' => $this->getExperience(),
        ];
    }

    /**
     * 生成掉落
     *
     * @param  int  $characterLevel  角色等级
     * @return array 掉落物品
     */
    public function generateLoot(int $characterLevel): array
    {
        $loot = [];
        $dropTable = $this->drop_table ?? [];
        // if the drop table is completely empty we should not generate any
        // default loot. this keeps behavior predictable for tests and
        // game designers who intend "no drops".
        if (empty($dropTable)) {
            return [];
        }

        // 装备掉落（全局配置，默认 1%）
        $dropChance = (float) config('game.equipment_drop.chance', 0.01);
        if ($this->rollChance($dropChance, 'equipment_drop_chance_multiplier')) {
            $itemTypes = $dropTable['item_types'] ?? ['weapon', 'helmet', 'armor', 'gloves', 'boots', 'ring', 'amulet', 'belt'];
            $itemType = $itemTypes[array_rand($itemTypes)];

            $quality = $this->generateItemQuality();

            $loot['item'] = [
                'type' => $itemType,
                'quality' => $quality,
                'level' => min($characterLevel, $this->level + 3),
            ];
        }

        return $loot;
    }

    /**
     * 生成物品品质
     */
    private function generateItemQuality(): string
    {
        $roll = mt_rand(1, 10000) / 100;
        $chances = config('game.item_quality_chances');

        // 测试模式概率加成
        if ($this->isTestMode()) {
            $multipliers = config('game.test_mode.quality_multiplier', []);
            foreach ($chances as $quality => $chance) {
                $multiplier = $multipliers[$quality] ?? 1;
                $chances[$quality] = $chance * $multiplier;
            }
        }

        $cumulative = 0;
        foreach ($chances as $quality => $chance) {
            $cumulative += $chance;
            if ($roll >= 100 - $cumulative) {
                return $quality;
            }
        }

        return 'common';
    }

    /**
     * 随机概率判断
     */
    private function rollChance(float $chance, string $testModeMultiplierKey = 'copper_drop_chance_multiplier'): bool
    {
        // 测试模式：掉落概率大幅提升
        if ($this->isTestMode()) {
            $chanceMultiplier = config(
                "game.test_mode.{$testModeMultiplierKey}",
                config('game.test_mode.copper_drop_chance', 10)
            );
            $chance = min(1.0, $chance * $chanceMultiplier);
        }

        return mt_rand() / mt_getrandmax() < $chance;
    }

    /**
     * 判断是否启用测试模式
     */
    private function isTestMode(): bool
    {
        $testMode = config('game.test_mode.enabled', false);
        if ($testMode) {
            return true;
        }

        // 也支持 APP_ENV 为 testing 或 sandbox
        $env = app()->environment();

        return in_array($env, ['testing', 'sandbox', 'test']);
    }
}
