<?php

namespace App\Services\Game\DTOs;

use App\Models\Game\GameCharacter;

/**
 * RoundDetailsContext DTO - encapsulates all parameters needed to build round details
 */
readonly class RoundDetailsContext
{
    public function __construct(
        public GameCharacter $character,
        public ?array $firstAliveMonster,
        public int $charAttack,
        public int $charDefense,
        public float $charCritRate,
        public float $charCritDamage,
        public int $baseAttackDamage,
        public int $skillDamage,
        public int $critDamageAmount,
        public int $aoeDamageAmount,
        public int $totalDamageDealt,
        public float $defenseReduction,
        public int $totalMonsterDamage,
        public int $currentRound,
        public int $aliveMonsterCount,
        public int $monstersKilledThisRound,
        public bool $isCrit,
        public bool $useAoe,
        public array $difficulty,
    ) {}

    /**
     * Create from raw parameters
     */
    public static function fromParams(
        GameCharacter $character,
        ?array $firstAliveMonster,
        int $charAttack,
        int $charDefense,
        float $charCritRate,
        float $charCritDamage,
        int $baseAttackDamage,
        int $skillDamage,
        int $critDamageAmount,
        int $aoeDamageAmount,
        int $totalDamageDealt,
        float $defenseReduction,
        int $totalMonsterDamage,
        int $currentRound,
        int $aliveMonsterCount,
        int $monstersKilledThisRound,
        bool $isCrit,
        bool $useAoe,
        array $difficulty,
    ): self {
        return new self(
            character: $character,
            firstAliveMonster: $firstAliveMonster,
            charAttack: $charAttack,
            charDefense: $charDefense,
            charCritRate: $charCritRate,
            charCritDamage: $charCritDamage,
            baseAttackDamage: $baseAttackDamage,
            skillDamage: $skillDamage,
            critDamageAmount: $critDamageAmount,
            aoeDamageAmount: $aoeDamageAmount,
            totalDamageDealt: $totalDamageDealt,
            defenseReduction: $defenseReduction,
            totalMonsterDamage: $totalMonsterDamage,
            currentRound: $currentRound,
            aliveMonsterCount: $aliveMonsterCount,
            monstersKilledThisRound: $monstersKilledThisRound,
            isCrit: $isCrit,
            useAoe: $useAoe,
            difficulty: $difficulty,
        );
    }
}
