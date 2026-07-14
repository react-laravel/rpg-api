<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Game\GameCharacter;
use Illuminate\Http\Request;

trait CharacterConcern
{
    /**
     * 从请求中获取角色
     */
    protected function getCharacter(Request $request): GameCharacter
    {
        $characterId = $request->query('character_id') ?: $request->input('character_id');

        $query = GameCharacter::query()
            ->where('user_id', $request->user()->id)
            ->with('currentCombatMonster');

        if ($characterId) {
            $query->where('id', $characterId);
        }

        return $query->firstOrFail();
    }

    /**
     * 从请求中获取角色 ID
     */
    protected function getCharacterId(Request $request): ?int
    {
        $characterId = $request->query('character_id') ?: $request->input('character_id');

        return $characterId ? (int) $characterId : null;
    }
}
