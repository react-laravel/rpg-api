<?php

namespace App\Support\Game;

/**
 * 怪物属性按地图层递进。
 *
 * 每张地图 3 只怪（野猪 / 鹿 / 兔子原型），第 N 张地图为第 N 层：
 *   HP   = round((hp_base + (layer-1) * hp_growth) * hp_type_multiplier)
 *   攻击 = (layer-1) * attack_growth
 *   防御 = defense_base + (layer-1) * defense_growth
 *   经验 = round(layer^2 * exp_type_multiplier)
 *
 * 类型：新手营地全普通；其余地图槽 2 为精英；章节最后一张地图槽 2 为 Boss。
 * 槽 0/1 始终普通，相邻图常规刷新只走线性层数，不会被精英/Boss 倍率变成血墙。
 */
final class MonsterProgression
{
    /**
     * @return list<array{key:string,name:string,hp_base:int,hp_growth:int,defense_base:int,defense_growth:int,attack_growth:int}>
     */
    public static function archetypes(): array
    {
        /** @var array<int, array<string, mixed>> $archetypes */
        $archetypes = config('game.monster_progression.archetypes', []);

        return array_values($archetypes);
    }

    public static function monstersPerMap(): int
    {
        $count = count(self::archetypes());

        return $count > 0 ? $count : 3;
    }

    /**
     * @param  array<string, mixed>  $monster
     * @param  array<int, array<string, mixed>>  $maps
     * @return array<string, mixed>
     */
    public static function apply(array $monster, int $index, array $maps): array
    {
        $archetypes = self::archetypes();
        $count = count($archetypes);
        if ($count === 0) {
            return $monster;
        }

        $mapIndex = intdiv($index, $count);
        $slot = $index % $count;
        $layer = $mapIndex + 1;
        $archetype = $archetypes[$slot];
        $type = self::typeForSlot($mapIndex, $slot, $maps);

        $hpMultiplier = (float) config("game.monster_progression.hp_type_multiplier.{$type}", 1.0);
        $expMultiplier = (float) config("game.monster_progression.exp_type_multiplier.{$type}", 1.0);
        $hpArchetype = $type === 'boss' ? $archetypes[0] : $archetype;
        $baseHp = (int) $hpArchetype['hp_base'] + ($layer - 1) * (int) $hpArchetype['hp_growth'];

        return array_merge($monster, [
            'type' => $type,
            'level' => $layer,
            'hp_base' => max(1, (int) round($baseHp * $hpMultiplier)),
            'defense_base' => (int) $archetype['defense_base'] + ($layer - 1) * (int) $archetype['defense_growth'],
            'attack_base' => ($layer - 1) * (int) $archetype['attack_growth'],
            'experience_base' => max(1, (int) round(($layer ** 2) * $expMultiplier)),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $maps
     */
    public static function typeForSlot(int $mapIndex, int $slot, array $maps): string
    {
        if ($mapIndex === 0 || $slot !== 2) {
            return 'normal';
        }

        return self::isActFinale($mapIndex, $maps) ? 'boss' : 'elite';
    }

    /**
     * @param  array<int, array<string, mixed>>  $maps
     */
    public static function isActFinale(int $mapIndex, array $maps): bool
    {
        $currentAct = (int) ($maps[$mapIndex]['act'] ?? 0);
        if (! isset($maps[$mapIndex + 1])) {
            return true;
        }

        return (int) $maps[$mapIndex + 1]['act'] !== $currentAct;
    }
}
