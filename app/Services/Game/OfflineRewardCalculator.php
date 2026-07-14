<?php

namespace App\Services\Game;

use App\Models\Game\GameCharacter;
use Illuminate\Support\Carbon;

/**
 * OfflineRewardCalculator - Handles offline reward calculation for game characters
 */
class OfflineRewardCalculator
{
    /**
     * Check offline rewards information
     */
    public function check(GameCharacter $character): array
    {
        /** @var Carbon|null $lastOnline */
        $lastOnline = $character->last_online;
        /** @var Carbon|null $lastClaimedAt */
        $lastClaimedAt = $character->claimed_offline_at;
        $rewardStartTime = $lastClaimedAt && (! $lastOnline || $lastClaimedAt->greaterThan($lastOnline))
            ? $lastClaimedAt
            : $lastOnline;

        if (! $rewardStartTime) {
            return $this->format(0, false);
        }

        /** @var Carbon $rewardStartTime */
        $now = now();
        $offlineSeconds = $rewardStartTime->diffInSeconds($now);

        // Minimum 60 seconds for offline rewards
        if ($offlineSeconds < 60) {
            return $this->format((int) $offlineSeconds, false);
        }

        // Maximum 24 hours (from config)
        $maxSeconds = config('game.offline_rewards.max_seconds', 86400);
        $offlineSeconds = min($offlineSeconds, $maxSeconds);

        // Calculate rewards (from config)
        $level = $character->level;
        $expPerLevel = config('game.offline_rewards.experience_per_level', 1);
        $copperPerLevel = config('game.offline_rewards.copper_per_level', 0.5);
        $experience = (int) ($level * $offlineSeconds * $expPerLevel);
        $copper = (int) ($level * $offlineSeconds * $copperPerLevel);

        // Check for level up
        $currentExp = $character->experience;
        $expNeeded = $character->getExperienceToNextLevel();
        $newExp = $currentExp + $experience;
        $levelUp = $newExp >= $expNeeded;

        return $this->format((int) $offlineSeconds, true, $experience, $copper, $levelUp);
    }

    /**
     * Claim offline rewards
     */
    public function claim(GameCharacter $character): array
    {
        $rewardInfo = $this->check($character);

        if (! $rewardInfo['available']) {
            return [
                'experience' => 0,
                'copper' => 0,
                'level_up' => false,
                'new_level' => $character->level,
            ];
        }

        $originalLevel = $character->level;

        // Update experience
        $character->experience += $rewardInfo['experience'];
        $character->reconcileLevelFromExperience();

        // Update copper
        $character->copper += $rewardInfo['copper'];

        // Update last claimed time
        $character->claimed_offline_at = now();
        $character->save();

        return [
            'experience' => $rewardInfo['experience'],
            'copper' => $rewardInfo['copper'],
            'level_up' => $character->level > $originalLevel,
            'new_level' => $character->level,
        ];
    }

    /**
     * Format offline rewards return data
     */
    private function format(
        int $offlineSeconds,
        bool $available,
        int $experience = 0,
        int $copper = 0,
        bool $levelUp = false
    ): array {
        return [
            'available' => $available,
            'offline_seconds' => $offlineSeconds,
            'experience' => $experience,
            'copper' => $copper,
            'level_up' => $levelUp,
        ];
    }
}
