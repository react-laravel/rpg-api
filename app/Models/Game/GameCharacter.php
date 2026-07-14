<?php

namespace App\Models\Game;

use App\Models\Game\Concerns\CharacterCombatStats;
use App\Services\Game\GameInventoryService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property array<int, array<string,mixed>|null>|null $combat_monsters
 * @property Carbon|null $combat_monsters_refreshed_at
 * @property int|null $combat_monster_id
 * @property int|null $combat_monster_hp
 * @property int|null $combat_monster_max_hp
 * @property array<int, int>|null $discovered_items
 * @property array<int, int>|null $discovered_monsters
 * @property GameMapDefinition|null $currentMap
 */
class GameCharacter extends Model
{
    use CharacterCombatStats;
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'class',
        'gender',
        'level',
        'experience',
        'copper',
        'strength',
        'dexterity',
        'vitality',
        'energy',
        'skill_points',
        'stat_points',
        'current_map_id',
        'is_fighting',
        'last_combat_at',
        'difficulty_tier',
        'current_hp',
        'current_mana',
        'auto_use_hp_potion',
        'hp_potion_threshold',
        'auto_use_mp_potion',
        'mp_potion_threshold',
        'auto_recycle_max_value',
        'combat_monster_id',
        'combat_monster_hp',
        'combat_monster_max_hp',
        'combat_monsters',
        'combat_monsters_refreshed_at',
        'combat_total_damage_dealt',
        'combat_total_damage_taken',
        'combat_rounds',
        'combat_skills_used',
        'combat_skill_cooldowns',
        'combat_started_at',
        'last_online',
        'claimed_offline_at',
        'discovered_items',
        'discovered_monsters',
    ];

    protected function casts(): array
    {
        return [
            'is_fighting' => 'boolean',
            'last_combat_at' => 'datetime',
            'auto_use_hp_potion' => 'boolean',
            'auto_use_mp_potion' => 'boolean',
            'hp_potion_threshold' => 'integer',
            'mp_potion_threshold' => 'integer',
            'auto_recycle_max_value' => 'integer',
            'combat_skills_used' => 'array',
            'combat_skill_cooldowns' => 'array',
            'combat_monsters' => 'array',
            'combat_monsters_refreshed_at' => 'datetime',
            'combat_started_at' => 'datetime',
            'last_online' => 'datetime',
            'claimed_offline_at' => 'datetime',
            'discovered_items' => 'array',
            'discovered_monsters' => 'array',
            'user_id' => 'integer',
            'level' => 'integer',
            'experience' => 'integer',
            'copper' => 'integer',
            'strength' => 'integer',
            'dexterity' => 'integer',
            'vitality' => 'integer',
            'energy' => 'integer',
            'skill_points' => 'integer',
            'stat_points' => 'integer',
            'current_map_id' => 'integer',
            'difficulty_tier' => 'integer',
            'current_hp' => 'integer',
            'current_mana' => 'integer',
            'combat_monster_id' => 'integer',
            'combat_monster_hp' => 'integer',
            'combat_monster_max_hp' => 'integer',
            'combat_total_damage_dealt' => 'integer',
            'combat_total_damage_taken' => 'integer',
            'combat_rounds' => 'integer',
        ];
    }

    /**
     * 装备槽位列表
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
     * 获取角色装备
     */
    public function equipment(): HasMany
    {
        return $this->hasMany(GameEquipment::class, 'character_id');
    }

    /**
     * 获取背包物品
     */
    public function items(): HasMany
    {
        return $this->hasMany(GameItem::class, 'character_id');
    }

    /**
     * 获取已学技能
     */
    public function skills(): HasMany
    {
        return $this->hasMany(GameCharacterSkill::class, 'character_id');
    }

    /**
     * 获取当前地图
     */
    public function currentMap(): BelongsTo
    {
        return $this->belongsTo(GameMapDefinition::class, 'current_map_id');
    }

    /**
     * 获取当前战斗的怪物
     */
    public function currentCombatMonster(): BelongsTo
    {
        return $this->belongsTo(GameMonsterDefinition::class, 'combat_monster_id');
    }

    /**
     * 获取战斗日志
     */
    public function combatLogs(): HasMany
    {
        return $this->hasMany(GameCombatLog::class, 'character_id');
    }

    /**
     * 是否处于一场战斗的进行中
     */
    public function hasActiveCombat(): bool
    {
        // 多怪物模式
        $monsters = $this->combat_monsters ?? [];
        if (! empty($monsters)) {
            foreach ($monsters as $monster) {
                if (($monster['hp'] ?? 0) > 0) {
                    return true;
                }
            }

            return false;
        }

        // 兼容旧数据：单怪物模式
        return $this->combat_monster_id !== null
            && (int) $this->combat_monster_hp > 0;
    }

    /**
     * 清除当前战斗状态
     */
    public function clearCombatState(): void
    {
        $this->combat_monster_id = null;
        $this->combat_monster_hp = null;
        $this->combat_monster_max_hp = null;
        $this->combat_monsters = null;
        $this->combat_monsters_refreshed_at = null;
        $this->combat_total_damage_dealt = 0;
        $this->combat_total_damage_taken = 0;
        $this->combat_rounds = 0;
        $this->combat_skills_used = null;
        $this->combat_skill_cooldowns = null;
        $this->combat_started_at = null;
    }

    /**
     * 难度倍率
     *
     * @return array{monster_hp: float, monster_damage: float, reward: float}
     */
    public function getDifficultyMultipliers(): array
    {
        $tier = (int) ($this->difficulty_tier ?? 0);
        $table = config('game.difficulty_multipliers', [0 => ['monster_hp' => 1.0, 'monster_damage' => 1.0, 'reward' => 1.0]]);

        return $table[$tier] ?? $table[0];
    }

    /**
     * 获取升级所需经验
     */
    public function getExperienceToNextLevel(): int
    {
        $table = config('game.experience_table', []);

        return $table[$this->level + 1] ?? $this->calculateExperienceThresholdForLevel($this->level + 1);
    }

    /**
     * 获取当前等级总经验
     */
    public function getExperienceForCurrentLevel(): int
    {
        $table = config('game.experience_table', []);

        return $table[$this->level] ?? 0;
    }

    private function calculateExperienceThresholdForLevel(int $level): int
    {
        $multiplier = (int) config('game.experience_fallback_multiplier', 50);
        $total = 0;

        for ($currentLevel = 1; $currentLevel < $level; $currentLevel++) {
            $total += $multiplier * ($currentLevel ** 2);
        }

        return $total;
    }

    /**
     * 根据当前总经验重算等级
     */
    public function reconcileLevelFromExperience(): bool
    {
        $levelsGained = 0;

        while ($this->experience >= $this->getExperienceToNextLevel()) {
            $this->level++;
            $this->skill_points += config('game.skill_points_per_level', 1);
            $this->stat_points += config('game.stat_points_per_level', 1);
            $levelsGained++;
        }

        if ($levelsGained > 0) {
            $this->save();

            return true;
        }

        return false;
    }

    /**
     * 添加经验值
     */
    public function addExperience(int $amount): array
    {
        $this->experience += $amount;
        $levelsGained = 0;

        while ($this->experience >= $this->getExperienceToNextLevel()) {
            $this->level++;
            $this->skill_points += config('game.skill_points_per_level', 1);
            $this->stat_points += config('game.stat_points_per_level', 1);
            $levelsGained++;
        }

        $this->save();

        return [
            'experience_gained' => $amount,
            'levels_gained' => $levelsGained,
            'new_level' => $this->level,
            'total_experience' => $this->experience,
        ];
    }

    /**
     * 获取装备中的所有物品
     */
    public function getEquippedItems(): array
    {
        $equipped = [];
        $equipmentSlots = $this->equipment()->with('item.definition', 'item.gems')->get();

        /** @var GameEquipment $slot */
        foreach ($equipmentSlots as $slot) {
            if ($slot->item) {
                $item = $slot->item;
                $newPrice = $item->calculateSellPrice();
                if ($item->sell_price !== $newPrice) {
                    $item->sell_price = $newPrice;
                    $item->saveQuietly();
                }
                $equipped[$slot->slot] = $item;
            }
        }

        return $equipped;
    }

    /**
     * 发现一个物品
     */
    public function discoverItem(int $itemDefinitionId): void
    {
        $discovered = $this->discovered_items ?? [];
        if (! in_array($itemDefinitionId, $discovered)) {
            $discovered[] = $itemDefinitionId;
            $this->discovered_items = $discovered;
            $this->save();
        }
    }

    /**
     * 发现一个怪物
     */
    public function discoverMonster(int $monsterDefinitionId): void
    {
        $discovered = $this->discovered_monsters ?? [];
        if (! in_array($monsterDefinitionId, $discovered)) {
            $discovered[] = $monsterDefinitionId;
            $this->discovered_monsters = $discovered;
            $this->save();
        }
    }

    /**
     * 检查是否已发现物品
     */
    public function hasDiscoveredItem(int $itemDefinitionId): bool
    {
        $discovered = $this->discovered_items ?? [];

        return in_array($itemDefinitionId, $discovered);
    }

    /**
     * 检查是否已发现怪物
     */
    public function hasDiscoveredMonster(int $monsterDefinitionId): bool
    {
        $discovered = $this->discovered_monsters ?? [];

        return in_array($monsterDefinitionId, $discovered);
    }

    /**
     * 获取背包物品数量（不在仓库且未装备）
     */
    public function getInventoryCount(): int
    {
        return $this->items()
            ->where('is_in_storage', false)
            ->where(function ($query) {
                $query->where('is_equipped', false)->orWhereNull('is_equipped');
            })
            ->count();
    }

    /**
     * 检查背包是否已满
     */
    public function isInventoryFull(): bool
    {
        return $this->getInventoryCount() >= GameInventoryService::INVENTORY_SIZE;
    }

    /**
     * 获取仓库物品数量（未装备）
     */
    public function getStorageCount(): int
    {
        return $this->items()
            ->where('is_in_storage', true)
            ->where(function ($query) {
                $query->where('is_equipped', false)->orWhereNull('is_equipped');
            })
            ->count();
    }

    /**
     * 检查仓库是否已满
     */
    public function isStorageFull(): bool
    {
        return $this->getStorageCount() >= GameInventoryService::STORAGE_SIZE;
    }
}
