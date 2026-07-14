<?php

namespace App\Services\Game;

use App\Models\Game\GameCharacter;

/**
 * CharacterValidator - Handles character validation logic
 */
class CharacterValidator
{
    /**
     * Validate character name
     *
     * @throws \InvalidArgumentException
     */
    public function validateName(string $name): void
    {
        $length = mb_strlen($name);

        if ($length < 2) {
            throw new \InvalidArgumentException('角色名至少需要 2 个字符');
        }

        if ($length > 12) {
            throw new \InvalidArgumentException('角色名最多 12 个字符');
        }

        if (! preg_match('/^[\x{4e00}-\x{9fa5}a-zA-Z0-9]+$/u', $name)) {
            throw new \InvalidArgumentException('角色名只能包含中文、英文和数字');
        }
    }

    /**
     * Check if character name is taken
     */
    public function isNameTaken(string $name): bool
    {
        return GameCharacter::query()->where('name', $name)->exists();
    }

    /**
     * Validate and throw if name is taken
     *
     * @throws \InvalidArgumentException
     */
    public function validateNameNotTaken(string $name): void
    {
        if ($this->isNameTaken($name)) {
            throw new \InvalidArgumentException('角色名已被使用');
        }
    }

    /**
     * Get class base stats
     */
    public function getClassBaseStats(string $class): array
    {
        return config("game.class_base_stats.{$class}", [
            'strength' => 2,
            'dexterity' => 3,
            'vitality' => 2,
            'energy' => 2,
        ]);
    }

    /**
     * Get starting copper for a class
     */
    public function getStartingCopper(string $class): int
    {
        return config("game.starting_copper.{$class}", 0);
    }
}
