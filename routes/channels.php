<?php

use App\Models\Game\GameCharacter;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('game.{characterId}', function (User $user, int $characterId): bool {
    return $user->isAdmin() || GameCharacter::query()
        ->whereKey($characterId)
        ->where('user_id', $user->id)
        ->exists();
});
