<?php

namespace App\Services\Game\DTOs;

/**
 * DamageContext DTO - encapsulates all parameters for damage calculation
 */
readonly class DamageContext
{
    /**
     * @param  array<int, float>|null  $targetDamageRatios
     */
    public function __construct(
        public array $monsters,
        public array $targetMonsters,
        public int $charAttack,
        public int $skillDamage,
        public bool $isCrit,
        public float $charCritDamage,
        public bool $useAoe,
        public float $nonCritBonus = 0.0,
        public float $slowedDamageBonus = 0.0,
        public ?array $targetDamageRatios = null,
    ) {}

    /**
     * Create from raw parameters
     *
     * @param  array<int, float>|null  $targetDamageRatios
     */
    public static function fromParams(
        array $monsters,
        array $targetMonsters,
        int $charAttack,
        int $skillDamage = 0,
        bool $isCrit = false,
        float $charCritDamage = 1.5,
        bool $useAoe = false,
        float $nonCritBonus = 0.0,
        float $slowedDamageBonus = 0.0,
        ?array $targetDamageRatios = null,
    ): self {
        return new self(
            monsters: $monsters,
            targetMonsters: $targetMonsters,
            charAttack: $charAttack,
            skillDamage: $skillDamage,
            isCrit: $isCrit,
            charCritDamage: $charCritDamage,
            useAoe: $useAoe,
            nonCritBonus: $nonCritBonus,
            slowedDamageBonus: $slowedDamageBonus,
            targetDamageRatios: $targetDamageRatios,
        );
    }
}
