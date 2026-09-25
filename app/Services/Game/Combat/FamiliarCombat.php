<?php

namespace App\Services\Game\Combat;

/**
 * 宝宝每拍打一只这一拍开头还活着的怪。怪物反击在角色和宝宝之间随机选，宝宝倒下后全部打角色。
 */
final class FamiliarCombat
{
    public function __construct(private CombatEffectApplier $effects = new CombatEffectApplier) {}

    /**
     * 优先打还活着的怪。候选里只剩本拍已被打死的怪时，仍然记下这一下，方便日志和飘字，不再扣血。
     *
     * @param  array<int, array<string, mixed>|null>  $monsters
     * @param  array<string, mixed>  $pet
     * @param  (callable(array<int, int>): int)|null  $pick
     * @param  list<int>|null  $eligibleIndexes  这一拍开头还活着的槽位；null 表示只看当前还活着的
     * @return array{0: array<int, array<string, mixed>|null>, 1: int, 2: array{name: string, damage: int, position: int, monster_name: string}|null}
     */
    public function attack(array $monsters, array $pet, ?callable $pick = null, ?array $eligibleIndexes = null): array
    {
        if ((int) ($pet['hp'] ?? 0) <= 0) {
            return [$monsters, 0, null];
        }

        $pool = [];
        if ($eligibleIndexes === null) {
            foreach ($monsters as $idx => $monster) {
                if (is_array($monster) && (int) ($monster['hp'] ?? 0) > 0) {
                    $pool[] = (int) $idx;
                }
            }
        } else {
            foreach ($eligibleIndexes as $idx) {
                $idx = (int) $idx;
                if (isset($monsters[$idx]) && is_array($monsters[$idx])) {
                    $pool[] = $idx;
                }
            }
        }
        if ($pool === []) {
            return [$monsters, 0, null];
        }

        $living = array_values(array_filter(
            $pool,
            fn (int $idx): bool => (int) ($monsters[$idx]['hp'] ?? 0) > 0
        ));
        $choices = $living !== [] ? $living : $pool;
        $pick ??= fn (array $indexes): int => $indexes[mt_rand(0, count($indexes) - 1)];
        $idx = $pick($choices);
        $monster = $monsters[$idx];
        $defense = (int) ($monster['defense'] ?? 0);
        $reduction = (float) config('game.combat.defense_reduction', 0.5);
        $hit = max(1, (int) round((int) ($pet['attack'] ?? 1) - $defense * $reduction));
        $hp = (int) ($monster['hp'] ?? 0);
        $dealt = min($hit, $hp);
        $monster['hp'] = $hp - $dealt;
        $monster['pet_swing'] = $hit;
        if ($dealt > 0) {
            $monster['pet_damage'] = $dealt;
            $already = (int) ($monster['damage_taken'] ?? -1);
            $monster['damage_taken'] = ($already < 0 ? 0 : $already) + $dealt;
            $monster['was_attacked'] = true;
        }
        $monsters[$idx] = $monster;

        return [$monsters, $dealt, [
            'name' => (string) ($pet['name'] ?? '宝宝'),
            'damage' => $hit,
            'position' => $idx,
            'monster_name' => (string) ($monster['name'] ?? '怪物'),
        ]];
    }

    /**
     * @param  array<int, array<string, mixed>|null>  $monsters
     * @param  array<string, mixed>|null  $pet
     * @param  (callable(): bool)|null  $hitsPet  返回 true 表示这一下打宝宝
     * @param  list<int>|null  $strikeIndexes  宝宝在场时传入本拍开头还活着的槽位，打死也会打出这一下
     * @return array{player: int, pet: array<string, mixed>|null, pet_damage: int}
     */
    public function applyCounterstrikes(array $monsters, int $charDefense, ?array $pet, ?callable $hitsPet = null, ?array $strikeIndexes = null): array
    {
        $hitsPet ??= fn (): bool => mt_rand(0, 1) === 1;
        $player = 0;
        $petHp = (int) ($pet['hp'] ?? 0);
        $petDamage = 0;
        $indexes = $strikeIndexes ?? array_keys($monsters);

        foreach ($indexes as $idx) {
            $monster = $monsters[$idx] ?? null;
            if (! is_array($monster)) {
                continue;
            }
            if ($strikeIndexes === null && (int) ($monster['hp'] ?? 0) <= 0) {
                continue;
            }
            $forCalc = $monster;
            if ((int) ($forCalc['hp'] ?? 0) <= 0) {
                $forCalc['hp'] = 1;
            }
            $hit = $this->effects->calculateMonsterCounterDamage([$forCalc], $charDefense);
            if ($hit <= 0) {
                continue;
            }
            if ($pet !== null && $petHp > 0 && $hitsPet()) {
                $applied = min($hit, $petHp);
                $petHp -= $applied;
                $petDamage += $applied;

                continue;
            }
            $player += $hit;
        }

        if ($pet !== null) {
            $pet['hp'] = max(0, $petHp);
        }

        return ['player' => $player, 'pet' => $pet, 'pet_damage' => $petDamage];
    }
}
