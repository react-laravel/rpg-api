<?php

namespace App\Policies\Game;

use App\Models\Game\GameCharacter;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class GameCharacterPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any characters.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the character.
     */
    public function view(User $user, GameCharacter $character): bool
    {
        return $character->user_id === $user->id || $user->hasRole('admin');
    }

    /**
     * Determine whether the user can create a character.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the character.
     */
    public function update(User $user, GameCharacter $character): bool
    {
        return $character->user_id === $user->id || $user->hasRole('admin');
    }

    /**
     * Determine whether the user can delete the character.
     */
    public function delete(User $user, GameCharacter $character): bool
    {
        return $character->user_id === $user->id || $user->hasRole('admin');
    }

    /**
     * Determine whether the user can start combat.
     */
    public function combat(User $user, GameCharacter $character): bool
    {
        return $character->user_id === $user->id;
    }

    /**
     * Determine whether the user can use skills.
     */
    public function useSkill(User $user, GameCharacter $character): bool
    {
        return $character->user_id === $user->id;
    }

    /**
     * Determine whether the user can manage inventory.
     */
    public function manageInventory(User $user, GameCharacter $character): bool
    {
        return $character->user_id === $user->id;
    }

    /**
     * Determine whether the user can view combat logs.
     */
    public function viewCombatLogs(User $user, GameCharacter $character): bool
    {
        return $character->user_id === $user->id || $user->hasRole('admin');
    }
}
