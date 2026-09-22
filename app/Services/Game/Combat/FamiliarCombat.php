<?php

namespace App\Services\Game\Combat;

/**
 * 宝宝每拍打一只还活着的怪。怪物反击在角色和宝宝之间随机选，宝宝倒下后全部打角色。
 */
final class FamiliarCombat
{
    public function __construct(private CombatEffectApplier $effects = new CombatEffectApplier) {}

    /**
     * @param  array<int, array<string, mixed>|null>  $monsters
     * @param  array<string, mixed>  $pet
     * @param  (callable(array<int, int>): int)|null  $pick
     * @return array{0: array<int, array<string, mixed>|null>, 1: int}
     */
    public function attack(array $monsters, array $pet, ?callable $pick = null): array
    {
        if ((int) ($pet['hp'] ?? 0) <= 0) {
            return [$monsters, 0];
        }

        $living = [];
        foreach ($monsters as $idx => $monster) {
            if (is_array($monster) && (int) ($monster['hp'] ?? 0) > 0) {
                $living[] = (int) $idx;
            }
        }
        if ($living === []) {
            return [$monsters, 0];
        }

        $pick ??= fn (array $indexes): int => $indexes[mt_rand(0, count($indexes) - 1)];
        $idx = $pick($living);
        $monster = $monsters[$idx];
        $defense = (int) ($monster['defense'] ?? 0);
        $reduction = (float) config('game.combat.defense_reduction', 0.5);
        $hit = max(1, (int) round((int) ($pet['attack'] ?? 1) - $defense * $reduction));
        $hp = (int) ($monster['hp'] ?? 0);
        $dealt = min($hit, $hp);
        $monster['hp'] = $hp - $dealt;
        $already = (int) ($monster['damage_taken'] ?? -1);
        $monster['damage_taken'] = ($already < 0 ? 0 : $already) + $dealt;
        $monster['was_attacked'] = true;
        $monsters[$idx] = $monster;

        return [$monsters, $dealt];
    }

    /**
     * @param  array<int, array<string, mixed>|null>  $monsters
     * @param  array<string, mixed>|null  $pet
     * @param  (callable(): bool)|null  $hitsPet  返回 true 表示这一下打宝宝
     * @return array{player: int, pet: array<string, mixed>|null}
     */
    public function applyCounterstrikes(array $monsters, int $charDefense, ?array $pet, ?callable $hitsPet = null): array
    {
        $hitsPet ??= fn (): bool => mt_rand(0, 1) === 1;
        $player = 0;
        $petHp = (int) ($pet['hp'] ?? 0);

        foreach ($monsters as $monster) {
            if (! is_array($monster)) {
                continue;
            }
            $hit = $this->effects->calculateMonsterCounterDamage([$monster], $charDefense);
            if ($hit <= 0) {
                continue;
            }
            if ($pet !== null && $petHp > 0 && $hitsPet()) {
                $petHp = max(0, $petHp - $hit);

                continue;
            }
            $player += $hit;
        }

        if ($pet !== null) {
            $pet['hp'] = max(0, $petHp);
        }

        return ['player' => $player, 'pet' => $pet];
    }
}
