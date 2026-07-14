<?php

namespace App\Services\Game\Combat;

use App\Models\Game\GameMonsterDefinition;

/**
 * 战斗奖励计算器
 */
class CombatRewardCalculator
{
    /**
     * 计算本回合死亡怪物的经验与铜币奖励(已乘难度系数)
     *
     * @param  array<int, array<string, mixed>>  $monstersUpdated
     * @param  array<int, int>  $hpAtRoundStart
     * @return array{0: int, 1: int}
     */
    public function calculateRoundDeathRewards(
        array $monstersUpdated,
        array $hpAtRoundStart,
        array $difficulty
    ): array {
        $totalExperience = 0;
        $totalCopper = 0;
        $rewardMultiplier = $difficulty['reward'] ?? 1;

        foreach ($monstersUpdated as $i => $monster) {
            $before = $hpAtRoundStart[$i] ?? 0;
            $after = $monster['hp'] ?? 0;
            if ($before > 0 && $after <= 0) {
                $totalExperience += $monster['experience'] ?? 0;

                $copperGained = $this->calculateMonsterCopperLoot($monster);
                $totalCopper += $copperGained;
            }
        }

        return [
            (int) ($totalExperience * $rewardMultiplier),
            (int) ($totalCopper * $rewardMultiplier),
        ];
    }

    /**
     * 根据地图层数计算铜币掉落（概率与数量见 config game.copper_drop）
     */
    public function calculateMonsterCopperLoot(array $monster): int
    {
        $copperConfig = config('game.copper_drop', []);
        $copperChance = (float) ($copperConfig['chance'] ?? 0.1);
        $perLayer = (int) ($copperConfig['per_layer'] ?? 1);

        if (! $this->rollChance($copperChance)) {
            return 0;
        }

        $layer = $this->resolveMonsterLayer($monster);

        return max(0, $layer * $perLayer);
    }

    /**
     * 解析金币层数：优先使用生成怪物时写入的地图层数，旧缓存才回退到怪物等级。
     */
    private function resolveMonsterLayer(array $monster): int
    {
        $rewardLayer = (int) ($monster['reward_layer'] ?? 0);
        if ($rewardLayer > 0) {
            return $rewardLayer;
        }

        $mapLayer = (int) ($monster['map_layer'] ?? 0);
        if ($mapLayer > 0) {
            return $mapLayer;
        }

        $layer = (int) ($monster['level'] ?? 0);
        if ($layer > 0) {
            return $layer;
        }

        $monsterId = $monster['id'] ?? null;
        if (! $monsterId) {
            return 1;
        }

        $definition = GameMonsterDefinition::query()->find($monsterId);

        if ($definition === null) {
            return 1;
        }

        return max(1, (int) $definition->level);
    }

    /**
     * 概率判定
     */
    private function rollChance(float $chance): bool
    {
        if ($this->isTestMode()) {
            $chanceMultiplier = config(
                'game.test_mode.copper_drop_chance_multiplier',
                config('game.test_mode.copper_drop_chance', 10)
            );
            $chance = min(1.0, $chance * $chanceMultiplier);
        }

        return mt_rand() / mt_getrandmax() < $chance;
    }

    private function isTestMode(): bool
    {
        if (config('game.test_mode.enabled', false)) {
            return true;
        }

        return in_array(config('app.env'), ['testing', 'sandbox'], true);
    }
}
