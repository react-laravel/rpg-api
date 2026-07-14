<?php

namespace App\Http\Controllers\Api\Game;

use App\Exceptions\GameException;
use App\Http\Controllers\Concerns\CharacterConcern;
use App\Http\Controllers\Controller;
use App\Jobs\Game\AutoCombatRoundJob;
use App\Services\Game\GameCombatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class CombatController extends Controller
{
    use CharacterConcern;

    public function __construct(
        private readonly GameCombatService $combatService,
    ) {}

    /**
     * 获取战斗状态
     */
    public function status(Request $request): JsonResponse
    {
        try {
            $character = $this->getCharacter($request);
            $this->combatService->syncCombatStatusWithRedis($character);

            $result = $this->combatService->getCombatStatus($character);

            return $this->success($result);
        } catch (GameException $e) {
            return $this->error($e->getMessage());
        } catch (Throwable $e) {
            Log::error('获取战斗状态失败', ['exception' => $e]);

            return $this->error('获取战斗状态失败，请稍后重试');
        }
    }

    /**
     * 开始自动战斗：服务器每 3 秒执行一回合，通过 Reverb WebSocket 推送战斗结果
     */
    public function start(Request $request): JsonResponse
    {
        try {
            $character = $this->getCharacter($request);
            $this->authorize('combat', $character);

            if ($character->getCurrentHp() <= 0) {
                throw GameException::invalidOperation('角色已死亡，请先复活');
            }

            // 检查是否已经有自动战斗在运行（使用原子 SETNX 操作防止竞态条件）
            $skillIds = null;
            if ($request->exists('skill_ids')) {
                $rawSkillIds = $request->input('skill_ids');
                $skillIds = is_array($rawSkillIds) ? array_map('intval', array_values($rawSkillIds)) : [];
            }

            $redisKey = AutoCombatRoundJob::redisKey($character->id);
            if (AutoCombatRoundJob::hasAutoCombatPayload(Redis::get($redisKey))) {
                return $this->success(['message' => '自动战斗已在进行中，结果将通过 WebSocket 推送']);
            }

            if (! AutoCombatRoundJob::tryAcquireAutoCombat($character->id, $skillIds)) {
                return $this->error('自动战斗已在运行中，请先停止当前战斗');
            }

            AutoCombatRoundJob::dispatch($character->id, $skillIds);

            return $this->success(['message' => '自动战斗已开始，结果将通过 WebSocket 推送']);
        } catch (GameException $e) {
            return $this->error($e->getMessage());
        } catch (Throwable $e) {
            Log::error('开始战斗失败', ['exception' => $e]);

            return $this->error('开始战斗失败，请稍后重试');
        }
    }

    /**
     * 死亡复活：传送至新手村，不自动开始战斗
     */
    public function revive(Request $request): JsonResponse
    {
        try {
            $character = $this->getCharacter($request);
            $this->authorize('combat', $character);

            $character = $this->combatService->reviveCharacter($character);

            return $this->success([
                'message' => '角色已复活并传送到新手村，请手动开始战斗',
                'character' => $character->toArray(),
            ]);
        } catch (GameException $e) {
            return $this->error($e->getMessage());
        } catch (Throwable $e) {
            Log::error('角色复活失败', ['exception' => $e]);

            return $this->error('角色复活失败，请稍后重试');
        }
    }

    /**
     * 停止自动战斗
     */
    public function stop(Request $request): JsonResponse
    {
        try {
            $character = $this->getCharacter($request);
            $this->authorize('combat', $character);

            $key = AutoCombatRoundJob::redisKey($character->id);
            Redis::del($key);

            if ($character->is_fighting) {
                $character->update(['is_fighting' => false]);
            }

            return $this->success(['message' => '自动战斗已停止']);
        } catch (GameException $e) {
            return $this->error($e->getMessage());
        } catch (Throwable $e) {
            Log::error('停止战斗失败', ['exception' => $e]);

            return $this->error('停止战斗失败，请稍后重试');
        }
    }

    /**
     * 获取战斗日志
     */
    public function logs(Request $request): JsonResponse
    {
        try {
            $character = $this->getCharacter($request);
            $result = $this->combatService->getCombatLogs($character);

            return $this->success($result);
        } catch (GameException $e) {
            return $this->error($e->getMessage());
        } catch (Throwable $e) {
            Log::error('获取战斗日志失败', ['exception' => $e]);

            return $this->error('获取战斗日志失败，请稍后重试');
        }
    }

    /**
     * 获取单条战斗日志详情
     */
    public function logDetail(Request $request, int $log): JsonResponse
    {
        try {
            $character = $this->getCharacter($request);
            $result = $this->combatService->getCombatLogDetail($character, $log);

            return $this->success($result);
        } catch (GameException $e) {
            return $this->error($e->getMessage());
        } catch (Throwable $e) {
            Log::error('获取战斗日志详情失败', ['exception' => $e]);

            return $this->error('获取战斗日志详情失败，请稍后重试');
        }
    }

    /**
     * 获取战斗统计
     */
    public function stats(Request $request): JsonResponse
    {
        try {
            $character = $this->getCharacter($request);
            $result = $this->combatService->getCombatStats($character);

            return $this->success($result);
        } catch (GameException $e) {
            return $this->error($e->getMessage());
        } catch (Throwable $e) {
            Log::error('获取战斗统计失败', ['exception' => $e]);

            return $this->error('获取战斗统计失败，请稍后重试');
        }
    }

    /**
     * 更新战斗中使用的技能配置
     */
    public function updateSkills(Request $request): JsonResponse
    {
        try {
            $character = $this->getCharacter($request);
            $this->authorize('useSkill', $character);

            $skillIds = $request->input('skill_ids') ?? [];
            $skillIds = is_array($skillIds) ? array_map('intval', array_values($skillIds)) : [];

            $key = AutoCombatRoundJob::redisKey($character->id);
            $payload = Redis::get($key);

            if (! AutoCombatRoundJob::hasAutoCombatPayload($payload)) {
                return $this->error('当前没有进行中的自动战斗');
            }

            $data = json_decode($payload, true);
            if (! is_array($data)) {
                $data = [];
            }

            $data['skill_ids'] = $skillIds;
            $data['cancelled_skill_ids'] = [];

            Redis::setex($key, AutoCombatRoundJob::ttl(), json_encode($data));

            return $this->success(['skill_ids' => $data['skill_ids']], '技能配置已更新');
        } catch (GameException $e) {
            return $this->error($e->getMessage());
        } catch (Throwable $e) {
            Log::error('更新技能配置失败', ['exception' => $e]);

            return $this->error('更新技能配置失败，请稍后重试');
        }
    }
}
