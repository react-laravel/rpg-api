<?php

namespace App\Providers;

use App\Models\Game\GameCharacter;
use App\Models\Game\GameItem;
use App\Policies\Game\GameCharacterPolicy;
use App\Policies\Game\GameItemPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class PolicyServiceProvider extends ServiceProvider
{
    protected $policies = [
        GameCharacter::class => GameCharacterPolicy::class,
        GameItem::class => GameItemPolicy::class,
    ];
}
