<?php

namespace App\Http\Controllers\Api\Game;

use App\Http\Controllers\Concerns\CharacterConcern;
use App\Http\Controllers\Controller;
use App\Jobs\Game\AutoCombatRoundJob;
use App\Models\Game\GameMapDefinition;
use App\Services\Game\GameCombatService;
use App\Services\Game\GameMonsterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

class MapController extends Controller
{
    use CharacterConcern;

    public function __construct(
        private readonly GameCombatService $combatService,
        private readonly GameMonsterService $monsterService,
    ) {}

    /**
     * 获取所有地图
     */
    public function index(Request $request): JsonResponse
    {
        $character = $this->getCharacter($request);

        $maps = GameMapDefinition::query()
            ->where('is_active', true)
            ->orderBy('act')
            ->orderBy('id')
            ->get();

        GameMapDefinition::preloadMonsters($maps);

        $mapsWithMonsters = $maps->map(function (GameMapDefinition $map) {
            $arr = $map->toArray();
            $arr['monsters'] = array_values(array_map(
                fn ($m) => $m->toArray(),
                $map->getMonsters()
            ));

            return $arr;
        });

        return $this->success([
            'maps' => $mapsWithMonsters,
            'current_map_id' => $character->current_map_id,
        ]);
    }

    /**
     * 进入地图
     */
    public function enter(Request $request, int $mapId): JsonResponse
    {
        $character = $this->getCharacter($request);
        $map = GameMapDefinition::findOrFail($mapId);

        Redis::del(AutoCombatRoundJob::redisKey($character->id));
        $character->clearCombatState();
        $character->current_map_id = $mapId;
        // 死亡角色(切图瞬间被打死)不进入战斗状态，需先复活，避免出现「HP=0 却在战斗中」的卡死状态
        $isAlive = $character->getCurrentHp() > 0;
        $character->is_fighting = $isAlive;
        $character->save();

        $character->refresh();
        $character->load('currentMap');

        $monsters = [];
        if ($isAlive) {
            $this->combatService->broadcastMonstersAppear($character, $map);
            $monsters = $this->monsterService->formatMonstersForResponse($character->fresh())['monsters'] ?? [];
        }

        return $this->success([
            'character' => $character->fresh('currentMap'),
            'map' => $map,
            'monsters' => $monsters,
        ], "已进入 {$map->name}");
    }

    /**
     * 传送到地图
     */
    public function teleport(Request $request, int $mapId): JsonResponse
    {
        $character = $this->getCharacter($request);
        $map = GameMapDefinition::findOrFail($mapId);

        // 直接传送到地图，自动开始战斗；若当前未在战斗中则视为复活，只恢复基础生命值与法力值
        $wasNotFighting = ! $character->is_fighting;
        Redis::del(AutoCombatRoundJob::redisKey($character->id));
        $character->clearCombatState();
        $character->current_map_id = $mapId;
        if ($wasNotFighting) {
            $character->applyReviveResources();
        }
        // 与 enter 一致：复活后仍须存活才进入战斗，避免 HP=0 却 is_fighting=true
        $isAlive = $character->getCurrentHp() > 0;
        $character->is_fighting = $isAlive;
        $character->save();

        $character->refresh();
        $character->load('currentMap');

        $monsters = [];
        if ($isAlive) {
            $this->combatService->broadcastMonstersAppear($character, $map);
            $monsters = $this->monsterService->formatMonstersForResponse($character->fresh())['monsters'] ?? [];
        }

        return $this->success([
            'character' => $character->fresh('currentMap'),
            'monsters' => $monsters,
        ], "已传送到 {$map->name}");
    }

    /**
     * 获取当前地图信息
     */
    public function current(Request $request): JsonResponse
    {
        $character = $this->getCharacter($request);

        if (! $character->current_map_id) {
            return $this->success([
                'current_map' => null,
                'monsters' => [],
            ]);
        }

        $map = $character->currentMap;
        $monsters = $map ? $map->getMonsters() : [];

        return $this->success([
            'current_map' => $map,
            'monsters' => $monsters,
            'is_fighting' => $character->is_fighting,
        ]);
    }
}
