<?php

namespace App\Services\Game;

use App\Models\Game\GameCharacter;
use App\Models\Game\GameSkillDefinition;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * GameCharacterService - Main service for character management
 *
 * Delegates offline rewards to OfflineRewardCalculator and validation to CharacterValidator
 */
class GameCharacterService
{
    /** Cache key prefix */
    private const CACHE_PREFIX = 'game_character:';

    /** Cache TTL (seconds) */
    private const CACHE_TTL = 300;

    public function __construct(
        private readonly OfflineRewardCalculator $offlineRewardCalculator = new OfflineRewardCalculator,
        private readonly CharacterValidator $characterValidator = new CharacterValidator
    ) {}

    /**
     * Get user character list
     */
    public function getCharacterList(int $userId): array
    {
        $cacheKey = self::CACHE_PREFIX."list:{$userId}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($userId) {
            $characters = GameCharacter::query()
                ->where('user_id', $userId)
                ->get();

            $characters->each(fn ($character) => $character->reconcileLevelFromExperience());

            return [
                // Cache only scalar arrays. Cached Collection objects are restored as
                // __PHP_Incomplete_Class by Laravel's safe Redis unserializer.
                'characters' => $characters->map(fn ($c) => $c->only([
                    'id', 'name', 'class', 'level', 'experience', 'copper', 'is_fighting', 'difficulty_tier',
                ]))->values()->all(),
                'experience_table' => config('game.experience_table', []),
            ];
        });
    }

    /**
     * Get character detail
     */
    public function getCharacterDetail(int $userId, ?int $characterId = null): ?array
    {
        $query = GameCharacter::query()
            ->where('user_id', $userId)
            ->with([
                'equipment.item.definition',
                'skills.skill',
                'currentMap',
            ]);

        if ($characterId) {
            $query->where('id', $characterId);
        }

        $character = $query->first();

        if (! $character) {
            return null;
        }

        $character->reconcileLevelFromExperience();

        return [
            'character' => $character,
            'experience_table' => config('game.experience_table', []),
            'combat_stats' => $character->getCombatStats(),
            'stats_breakdown' => $character->getCombatStatsBreakdown(),
            'equipped_items' => $character->getEquippedItems(),
            'current_hp' => $character->getCurrentHp(),
            'current_mana' => $character->getCurrentMana(),
        ];
    }

    /**
     * Create new character
     */
    public function createCharacter(int $userId, string $name, string $class, string $gender = 'male'): GameCharacter
    {
        $this->characterValidator->validateName($name);
        $this->characterValidator->validateNameNotTaken($name);

        $classStats = $this->characterValidator->getClassBaseStats($class);

        return DB::transaction(function () use ($userId, $name, $class, $gender, $classStats) {
            $character = GameCharacter::create([
                'user_id' => $userId,
                'name' => $name,
                'class' => $class,
                'gender' => $gender,
                'level' => 1,
                'experience' => 0,
                'copper' => $this->characterValidator->getStartingCopper($class),
                'strength' => $classStats['strength'],
                'dexterity' => $classStats['dexterity'],
                'vitality' => $classStats['vitality'],
                'energy' => $classStats['energy'],
                'skill_points' => 0,
                'stat_points' => 0,
            ]);

            $this->initializeEquipmentSlots($character);
            $this->grantDefaultSkills($character);
            $this->clearCharacterCache($userId);

            return $character;
        });
    }

    /**
     * Delete character
     */
    public function deleteCharacter(int $userId, int $characterId): void
    {
        $character = GameCharacter::query()
            ->where('id', $characterId)
            ->where('user_id', $userId)
            ->firstOrFail();

        $character->delete();
        $this->clearCharacterCache($userId);
    }

    /**
     * Allocate stat points
     */
    public function allocateStats(int $userId, int $characterId, array $stats): array
    {
        $character = GameCharacter::query()
            ->where('id', $characterId)
            ->where('user_id', $userId)
            ->firstOrFail();

        $sanitizedStats = [
            'strength' => max(0, (int) ($stats['strength'] ?? 0)),
            'dexterity' => max(0, (int) ($stats['dexterity'] ?? 0)),
            'vitality' => max(0, (int) ($stats['vitality'] ?? 0)),
            'energy' => max(0, (int) ($stats['energy'] ?? 0)),
        ];

        $totalPoints = array_sum(array_map(fn ($v) => max(0, (int) $v), $sanitizedStats));

        if ($totalPoints > $character->stat_points) {
            throw new \InvalidArgumentException('属性点不足');
        }

        $character->fill([
            'strength' => $character->strength + $sanitizedStats['strength'],
            'dexterity' => $character->dexterity + $sanitizedStats['dexterity'],
            'vitality' => $character->vitality + $sanitizedStats['vitality'],
            'energy' => $character->energy + $sanitizedStats['energy'],
            'stat_points' => $character->stat_points - $totalPoints,
        ]);
        $character->save();

        return [
            'character' => $character,
            'combat_stats' => $character->getCombatStats(),
            'stats_breakdown' => $character->getCombatStatsBreakdown(),
            'current_hp' => $character->getCurrentHp(),
            'current_mana' => $character->getCurrentMana(),
        ];
    }

    /**
     * Update difficulty setting
     */
    public function updateDifficulty(int $userId, int $difficultyTier, ?int $characterId = null): GameCharacter
    {
        $query = GameCharacter::query()->where('user_id', $userId);

        if ($characterId) {
            $query->where('id', $characterId);
        }

        $character = $query->firstOrFail();
        $character->difficulty_tier = $difficultyTier;
        $character->save();

        return $character;
    }

    /**
     * Get character full detail
     */
    public function getCharacterFullDetail(int $userId, ?int $characterId = null): array
    {
        $query = GameCharacter::query()->where('user_id', $userId);

        if ($characterId) {
            $query->where('id', $characterId);
        }

        $character = $query->firstOrFail();

        $inventory = $character->items()
            ->where('is_in_storage', false)
            ->with('definition')
            ->orderBy('slot_index')
            ->get();

        $storage = $character->items()
            ->where('is_in_storage', true)
            ->with('definition')
            ->orderBy('slot_index')
            ->get();

        $skills = $character->skills()
            ->with('skill')
            ->orderBy('slot_index')
            ->get();

        $availableSkills = $this->getAvailableSkills($character);

        return [
            'character' => $character,
            'inventory' => $inventory,
            'storage' => $storage,
            'skills' => $skills,
            'available_skills' => $availableSkills,
            'combat_stats' => $character->getCombatStats(),
            'stats_breakdown' => $character->getCombatStatsBreakdown(),
            'current_hp' => $character->getCurrentHp(),
            'current_mana' => $character->getCurrentMana(),
        ];
    }

    /**
     * Check offline rewards information
     */
    public function checkOfflineRewards(GameCharacter $character): array
    {
        return $this->offlineRewardCalculator->check($character);
    }

    /**
     * Claim offline rewards
     */
    public function claimOfflineRewards(GameCharacter $character): array
    {
        return $this->offlineRewardCalculator->claim($character);
    }

    /**
     * Initialize equipment slots
     */
    private function initializeEquipmentSlots(GameCharacter $character): void
    {
        foreach (GameCharacter::getSlots() as $slot) {
            $character->equipment()->create(['slot' => $slot]);
        }
    }

    /**
     * Grant default level-1 skills for newly created characters.
     */
    private function grantDefaultSkills(GameCharacter $character): void
    {
        if ($character->class !== 'mage') {
            return;
        }

        $fireball = GameSkillDefinition::query()
            ->where('class_restriction', 'mage')
            ->where('skill_line', 'mage_fireball')
            ->where('node_tier', 0)
            ->where('is_active', true)
            ->first();

        if (! $fireball) {
            return;
        }

        $character->skills()->firstOrCreate(
            ['skill_id' => $fireball->id],
            ['slot_index' => 0]
        );
    }

    /**
     * Get available skills for character
     */
    private function getAvailableSkills(GameCharacter $character)
    {
        return GameSkillDefinition::query()
            ->where('is_active', true)
            ->where(function ($query) use ($character) {
                $query->where('class_restriction', 'all')
                    ->orWhere('class_restriction', $character->class);
            })
            ->get();
    }

    /**
     * Mark character as online and update last_online timestamp
     */
    public function markOnline(GameCharacter $character): GameCharacter
    {
        $character->last_online = now();
        $character->save();

        return $character;
    }

    /**
     * Clear character cache
     */
    private function clearCharacterCache(int $userId): void
    {
        Cache::forget(self::CACHE_PREFIX."list:{$userId}");
    }
}
