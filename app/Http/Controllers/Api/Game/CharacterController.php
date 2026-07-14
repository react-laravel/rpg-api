<?php

namespace App\Http\Controllers\Api\Game;

use App\Http\Controllers\Controller;
use App\Http\Requests\Game\AllocateStatsRequest;
use App\Http\Requests\Game\CreateCharacterRequest;
use App\Http\Requests\Game\DeleteCharacterRequest;
use App\Http\Requests\Game\UpdateDifficultyRequest;
use App\Services\Game\GameCharacterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class CharacterController extends Controller
{
    use \App\Http\Controllers\Concerns\CharacterConcern;

    public function __construct(
        private readonly GameCharacterService $characterService,
    ) {}

    /**
     * 获取当前用户的角色列表
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->characterService->getCharacterList($request->user()->id);

        return $this->success($result);
    }

    /**
     * 获取指定角色信息
     */
    public function show(Request $request): JsonResponse
    {
        $characterId = $request->query('character_id');
        $characterId = $characterId !== null && $characterId !== '' ? (int) $characterId : null;
        $result = $this->characterService->getCharacterDetail($request->user()->id, $characterId);

        return $this->success(['character' => $result['character'] ?? null] + [
            'experience_table' => $result['experience_table'] ?? [],
            'combat_stats' => $result['combat_stats'] ?? [],
            'stats_breakdown' => $result['stats_breakdown'] ?? [],
            'equipped_items' => $result['equipped_items'] ?? [],
            'current_hp' => $result['current_hp'] ?? 0,
            'current_mana' => $result['current_mana'] ?? 0,
        ]);
    }

    /**
     * 创建新角色
     */
    public function store(CreateCharacterRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            $character = $this->characterService->createCharacter(
                $request->user()->id,
                $validated['name'],
                $validated['class'],
                $validated['gender'] ?? 'male'
            );

            return $this->success([
                'character' => $character->fresh(['equipment', 'skills', 'currentMap']),
                'combat_stats' => $character->getCombatStats(),
                'stats_breakdown' => $character->getCombatStatsBreakdown(),
                'current_hp' => $character->getCurrentHp(),
                'current_mana' => $character->getCurrentMana(),
            ], '角色创建成功', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 删除角色
     */
    public function destroy(DeleteCharacterRequest $request): JsonResponse
    {
        try {
            $character = $this->getCharacter($request);
            $this->authorize('delete', $character);

            $this->characterService->deleteCharacter(
                $request->user()->id,
                $request->input('character_id')
            );

            return $this->success([], '角色已删除');
        } catch (Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 分配属性点
     */
    public function allocateStats(AllocateStatsRequest $request): JsonResponse
    {
        try {
            $character = $this->getCharacter($request);
            $this->authorize('update', $character);

            $result = $this->characterService->allocateStats(
                $request->user()->id,
                $request->input('character_id'),
                [
                    'strength' => $request->input('strength', 0),
                    'dexterity' => $request->input('dexterity', 0),
                    'vitality' => $request->input('vitality', 0),
                    'energy' => $request->input('energy', 0),
                ]
            );

            return $this->success($result, '属性分配成功');
        } catch (Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 更新难度
     */
    public function updateDifficulty(UpdateDifficultyRequest $request): JsonResponse
    {
        try {
            $character = $this->getCharacter($request);
            $this->authorize('update', $character);

            $character = $this->characterService->updateDifficulty(
                $request->user()->id,
                $request->input('difficulty_tier'),
                $request->input('character_id')
            );

            return $this->success(['character' => $character], '难度已更新');
        } catch (Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取角色详细信息
     */
    public function detail(Request $request): JsonResponse
    {
        $characterId = $request->query('character_id');
        $characterId = $characterId !== null && $characterId !== '' ? (int) $characterId : null;
        $result = $this->characterService->getCharacterFullDetail($request->user()->id, $characterId);

        return $this->success($result);
    }

    /**
     * 更新最后在线时间(玩家选择角色时调用)
     */
    public function online(Request $request): JsonResponse
    {
        try {
            $character = $this->getCharacter($request);
            $this->authorize('update', $character);

            $character = $this->characterService->markOnline($character);

            return $this->success(['last_online' => $character->last_online]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 检查离线奖励
     */
    public function checkOfflineRewards(Request $request): JsonResponse
    {
        try {
            $character = $this->getCharacter($request);
            $this->authorize('view', $character);

            $result = $this->characterService->checkOfflineRewards($character);

            return $this->success($result);
        } catch (Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 领取离线奖励
     */
    public function claimOfflineRewards(Request $request): JsonResponse
    {
        try {
            $character = $this->getCharacter($request);
            $this->authorize('update', $character);

            $result = $this->characterService->claimOfflineRewards($character);

            return $this->success($result, $result['level_up'] ? "升级到了 {$result['new_level']} 级！" : '离线奖励已领取');
        } catch (Throwable $e) {
            return $this->error($e->getMessage());
        }
    }
}
