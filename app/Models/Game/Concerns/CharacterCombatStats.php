<?php

namespace App\Models\Game\Concerns;

use App\Models\Game\GameEquipment;

/**
 * 角色战斗属性计算 Trait
 */
trait CharacterCombatStats
{
    /**
     * 请求级装备属性缓存（避免同一请求内重复查询）
     */
    private ?array $cachedEquipmentStats = null;

    /**
     * 加载所有装备的属性加成（一次性查询）
     */
    private function loadEquipmentStats(): array
    {
        $bonuses = [];

        $equipmentSlots = $this->equipment()
            ->with('item.definition', 'item.gems.gemDefinition')
            ->get();

        /** @var GameEquipment $slot */
        foreach ($equipmentSlots as $slot) {
            if ($slot->item) {
                $itemStats = $slot->item->getTotalStats();
                foreach ($itemStats as $stat => $value) {
                    $bonuses[$stat] = ($bonuses[$stat] ?? 0) + (float) $value;
                }
            }
        }

        return $bonuses;
    }

    /**
     * 获取装备属性加成（带请求级缓存）
     */
    public function getEquipmentBonus(string $stat): float
    {
        if ($this->cachedEquipmentStats === null) {
            $this->cachedEquipmentStats = $this->loadEquipmentStats();
        }

        return $this->cachedEquipmentStats[$stat] ?? 0;
    }

    /**
     * 预加载装备属性缓存（供批量计算方法调用）
     */
    private function ensureEquipmentCacheLoaded(): void
    {
        if ($this->cachedEquipmentStats === null) {
            $this->cachedEquipmentStats = $this->loadEquipmentStats();
        }
    }

    /**
     * 生命值基础值
     */
    public function getBaseHp(): int
    {
        $hpConfig = config('game.hp', []);
        $base = $hpConfig['base'] ?? [];

        return (int) ($base[$this->class] ?? ($base['default'] ?? 15));
    }

    /**
     * 计算最大生命值
     */
    public function getMaxHp(): int
    {
        $hpConfig = config('game.hp', []);
        $base = $hpConfig['base'] ?? [];
        $baseHp = $base[$this->class] ?? ($base['default'] ?? 15);
        $multiplier = $hpConfig['vitality_multiplier'] ?? 5;
        $equipmentBonus = (int) $this->getEquipmentBonus('max_hp');

        return (int) ($baseHp + $this->vitality * $multiplier) + $equipmentBonus;
    }

    /**
     * 角色创建完成时的生命值（职业基础体力，不含后续加点与装备）
     */
    public function getCreationHp(): int
    {
        $hpConfig = config('game.hp', []);
        $base = $hpConfig['base'] ?? [];
        $baseHp = $base[$this->class] ?? ($base['default'] ?? 15);
        $multiplier = $hpConfig['vitality_multiplier'] ?? 5;
        $classStats = config("game.class_base_stats.{$this->class}", []);
        $vitality = (int) ($classStats['vitality'] ?? 5);

        return (int) ($baseHp + $vitality * $multiplier);
    }

    /**
     * 复活：HP 恢复为创建时数值，MP 保持死亡前不变
     */
    public function applyReviveResources(): void
    {
        $this->current_hp = $this->getCreationHp();
    }

    /**
     * 法力值基础值
     */
    public function getBaseMana(): int
    {
        $manaConfig = config('game.mana', []);
        $base = $manaConfig['base'] ?? [];

        return (int) ($base[$this->class] ?? ($base['default'] ?? 15));
    }

    /**
     * 计算最大法力值
     */
    public function getMaxMana(): int
    {
        $manaConfig = config('game.mana', []);
        $base = $manaConfig['base'] ?? [];
        $baseMana = $base[$this->class] ?? ($base['default'] ?? 50);
        $multiplier = $manaConfig['energy_multiplier'] ?? 3;
        $equipmentBonus = (int) $this->getEquipmentBonus('max_mana');

        return (int) ($baseMana + $this->energy * $multiplier) + $equipmentBonus;
    }

    /**
     * 基础攻击力
     */
    public function getBaseAttack(): int
    {
        $attackConfig = config('game.combat.attack', []);
        $stat = $attackConfig['stat'] ?? 'strength';
        $multiplier = (float) ($attackConfig['multiplier'] ?? 1);

        return (int) ($this->{$stat} * $multiplier);
    }

    /**
     * 计算攻击力
     */
    public function getAttack(): int
    {
        return (int) ($this->getBaseAttack() + $this->getEquipmentBonus('attack'));
    }

    /**
     * 基础防御力
     */
    public function getBaseDefense(): int
    {
        $def = config('game.combat.defense', []);
        $vCoef = (float) ($def['vitality_multiplier'] ?? 0.5);
        $dCoef = (float) ($def['dexterity_multiplier'] ?? 0.3);

        return (int) ($this->vitality * $vCoef + $this->dexterity * $dCoef);
    }

    /**
     * 计算防御力
     */
    public function getDefense(): int
    {
        return (int) ($this->getBaseDefense() + $this->getEquipmentBonus('defense'));
    }

    /**
     * 基础暴击率
     */
    public function getBaseCritRate(): float
    {
        $critConfig = config('game.combat.crit_rate', []);
        $coef = (float) ($critConfig['dexterity_multiplier'] ?? 0.01);

        return $this->dexterity * $coef;
    }

    /**
     * 计算暴击率
     */
    public function getCritRate(): float
    {
        $critConfig = config('game.combat.crit_rate', []);
        $cap = (float) ($critConfig['cap'] ?? 0.10);

        return min($cap, $this->getBaseCritRate() + $this->getEquipmentBonus('crit_rate'));
    }

    /**
     * 基础暴击伤害倍率
     */
    public function getBaseCritDamage(): float
    {
        $critConfig = config('game.combat.crit_damage', []);

        return (float) ($critConfig['base'] ?? 1.5);
    }

    /**
     * 计算暴击伤害
     */
    public function getCritDamage(): float
    {
        return $this->getBaseCritDamage() + $this->getEquipmentBonus('crit_damage');
    }

    /**
     * 获取完整战斗属性
     *
     * @return array<string,mixed>
     */
    public function getCombatStats(): array
    {
        // 预加载装备缓存，避免后续每个属性方法重复查库
        $this->ensureEquipmentCacheLoaded();

        return [
            'max_hp' => $this->getMaxHp(),
            'max_mana' => $this->getMaxMana(),
            'attack' => $this->getAttack(),
            'defense' => $this->getDefense(),
            'crit_rate' => $this->getCritRate(),
            'crit_damage' => $this->getCritDamage(),
        ];
    }

    /**
     * 获取战斗属性明细
     *
     * @return array<string, array{base: int|float, equipment: float, total: int|float}>
     */
    public function getCombatStatsBreakdown(): array
    {
        // 预加载装备缓存
        $this->ensureEquipmentCacheLoaded();
        $equipAttack = $this->getEquipmentBonus('attack');
        $equipDefense = $this->getEquipmentBonus('defense');
        $equipCritRate = $this->getEquipmentBonus('crit_rate');
        $equipCritDamage = $this->getEquipmentBonus('crit_damage');

        $critConfig = config('game.combat.crit_rate', []);
        $critCap = (float) ($critConfig['cap'] ?? 0.10);
        $totalCritRate = min($critCap, $this->getBaseCritRate() + $equipCritRate);
        $totalCritDamage = $this->getBaseCritDamage() + $equipCritDamage;

        return [
            'attack' => [
                'base' => $this->getBaseAttack(),
                'equipment' => (float) $equipAttack,
                'total' => $this->getAttack(),
            ],
            'defense' => [
                'base' => $this->getBaseDefense(),
                'equipment' => (float) $equipDefense,
                'total' => $this->getDefense(),
            ],
            'crit_rate' => [
                'base' => round($this->getBaseCritRate(), 4),
                'equipment' => (float) $equipCritRate,
                'total' => round($totalCritRate, 4),
            ],
            'crit_damage' => [
                'base' => $this->getBaseCritDamage(),
                'equipment' => (float) $equipCritDamage,
                'total' => round($totalCritDamage, 4),
            ],
        ];
    }

    /**
     * 获取当前生命值
     */
    public function getCurrentHp(): int
    {
        return $this->current_hp ?? $this->getMaxHp();
    }

    /**
     * 获取当前法力值
     */
    public function getCurrentMana(): int
    {
        return $this->current_mana ?? $this->getMaxMana();
    }

    /**
     * 每回合生命值恢复量（基于体力）
     */
    public function getHpRegenPerRound(): int
    {
        $multiplier = (float) (config('game.combat.hp_regen_per_vitality') ?? 1);

        return max(0, (int) round($this->vitality * $multiplier));
    }

    /**
     * 每回合法力值恢复量（基于能量）
     */
    public function getManaRegenPerRound(): int
    {
        $multiplier = (float) (config('game.combat.mp_regen_per_energy') ?? 1);

        return max(0, (int) round($this->energy * $multiplier));
    }

    /**
     * 恢复生命值
     */
    public function restoreHp(int $amount): void
    {
        $maxHp = $this->getMaxHp();
        $currentHp = $this->getCurrentHp();
        $this->current_hp = min($maxHp, $currentHp + $amount);
        $this->save();
    }

    /**
     * 恢复法力值
     */
    public function restoreMana(int $amount): void
    {
        $maxMana = $this->getMaxMana();
        $currentMana = $this->getCurrentMana();
        $this->current_mana = min($maxMana, $currentMana + $amount);
        $this->save();
    }

    /**
     * 初始化 HP/Mana
     */
    public function initializeHpMana(): void
    {
        $needsSave = false;

        if ($this->current_hp === null && $this->getAttribute('current_hp') === null) {
            $this->current_hp = $this->getMaxHp();
            $needsSave = true;
        }

        if ($this->current_mana === null && $this->getAttribute('current_mana') === null) {
            $this->current_mana = $this->getMaxMana();
            $needsSave = true;
        }

        if ($needsSave) {
            $this->save();
        }
    }
}
