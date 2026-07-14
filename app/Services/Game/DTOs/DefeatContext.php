<?php

namespace App\Services\Game\DTOs;

use App\Models\Game\GameMonsterDefinition;

/**
 * DefeatContext DTO - encapsulates the context for handling defeat in combat
 */
readonly class DefeatContext
{
    public function __construct(
        public GameMonsterDefinition $monster,
        public int $monsterLevel,
        public int $monsterMaxHp,
        public int $monsterHpBeforeRound,
    ) {}

    /**
     * Create from raw parameters
     */
    public static function fromParams(
        GameMonsterDefinition $monster,
        int $monsterLevel,
        int $monsterMaxHp,
        int $monsterHpBeforeRound
    ): self {
        return new self(
            monster: $monster,
            monsterLevel: $monsterLevel,
            monsterMaxHp: $monsterMaxHp,
            monsterHpBeforeRound: $monsterHpBeforeRound
        );
    }
}
