<?php

namespace App\Services\Game\DTOs;

/**
 * DamageContext DTO - encapsulates all parameters for damage calculation
 */
readonly class DamageContext
{
    public function __construct(
        public array $monsters,
        public array $targetMonsters,
        public int $charAttack,
        public int $skillDamage,
        public bool $isCrit,
        public float $charCritDamage,
        public bool $useAoe,
    ) {}

    /**
     * Create from raw parameters
     */
    public static function fromParams(
        array $monsters,
        array $targetMonsters,
        int $charAttack,
        int $skillDamage = 0,
        bool $isCrit = false,
        float $charCritDamage = 1.5,
        bool $useAoe = false,
    ): self {
        return new self(
            monsters: $monsters,
            targetMonsters: $targetMonsters,
            charAttack: $charAttack,
            skillDamage: $skillDamage,
            isCrit: $isCrit,
            charCritDamage: $charCritDamage,
            useAoe: $useAoe,
        );
    }
}
