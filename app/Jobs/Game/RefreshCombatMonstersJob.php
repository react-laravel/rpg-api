<?php

namespace App\Jobs\Game;

use App\Models\Game\GameCharacter;
use App\Models\Game\GameMapDefinition;
use App\Services\Game\GameCombatBroadcaster;
use App\Services\Game\GameMonsterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshCombatMonstersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(GameMonsterService $monsterService): void
    {
        // 查找所有正在战斗且需要刷新怪物的角色
        $characters = GameCharacter::where('is_fighting', true)
            ->whereNotNull('combat_monsters')
            ->get();

        foreach ($characters as $character) {
            if ($monsterService->shouldRefreshMonsters($character)) {
                $map = $character->currentMap;
                if (! $map instanceof GameMapDefinition) {
                    continue;
                }

                // 刷新怪物
                $monsterService->generateNewMonsters($character, $map, $character->combat_monsters ?? [], true);

                // 广播怪物出现
                $monsterData = $monsterService->formatMonstersForResponse($character);
                $monstersAppear = [
                    'type' => 'monsters_appear',
                    'monsters' => $monsterData['monsters'],
                    'character' => [
                        'current_hp' => $character->getCurrentHp(),
                        'current_mana' => $character->getCurrentMana(),
                    ],
                ];
                $this->broadcaster()->broadcastCombatUpdate($character->id, $monstersAppear);
            }
        }
    }

    private function broadcaster(): GameCombatBroadcaster
    {
        return app(GameCombatBroadcaster::class);
    }
}
