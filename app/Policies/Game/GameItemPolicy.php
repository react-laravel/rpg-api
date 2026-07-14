<?php

namespace App\Policies\Game;

use App\Models\Game\GameCharacter;
use App\Models\Game\GameItem;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class GameItemPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any items.
     */
    public function viewAny(User $user, GameCharacter $character): bool
    {
        return $character->user_id === $user->id || $user->hasRole('admin');
    }

    /**
     * Determine whether the user can view the item.
     */
    public function view(User $user, GameItem $item): bool
    {
        return $item->character->user_id === $user->id || $user->hasRole('admin');
    }

    /**
     * Determine whether the user can use the item.
     */
    public function use(User $user, GameItem $item): bool
    {
        return $item->character->user_id === $user->id;
    }

    /**
     * Determine whether the user can equip the item.
     */
    public function equip(User $user, GameItem $item): bool
    {
        return $item->character->user_id === $user->id;
    }

    /**
     * Determine whether the user can unequip the item.
     */
    public function unequip(User $user, GameItem $item): bool
    {
        return $item->character->user_id === $user->id;
    }

    /**
     * Determine whether the user can drop the item.
     */
    public function drop(User $user, GameItem $item): bool
    {
        return $item->character->user_id === $user->id;
    }

    /**
     * Determine whether the user can sell the item.
     */
    public function sell(User $user, GameItem $item): bool
    {
        return $item->character->user_id === $user->id;
    }

    /**
     * Determine whether the user can trade the item.
     */
    public function trade(User $user, GameItem $item): bool
    {
        return $item->character->user_id === $user->id;
    }

    /**
     * Determine whether the user can store the item.
     */
    public function store(User $user, GameItem $item): bool
    {
        return $item->character->user_id === $user->id;
    }

    /**
     * Determine whether the user can retrieve the item from storage.
     */
    public function retrieve(User $user, GameItem $item): bool
    {
        return $item->character->user_id === $user->id;
    }
}
