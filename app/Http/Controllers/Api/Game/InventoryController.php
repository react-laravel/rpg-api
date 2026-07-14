<?php

namespace App\Http\Controllers\Api\Game;

use App\Events\Game\GameInventoryUpdate;
use App\Http\Controllers\Concerns\CharacterConcern;
use App\Http\Controllers\Controller;
use App\Http\Requests\Game\EquipItemRequest;
use App\Http\Requests\Game\MoveItemRequest;
use App\Http\Requests\Game\SellItemRequest;
use App\Http\Requests\Game\UnequipItemRequest;
use App\Http\Requests\Game\UpdateAutoRecycleSettingsRequest;
use App\Models\Game\GameCharacter;
use App\Services\Cache\RedisLockService;
use App\Services\Game\GameInventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class InventoryController extends Controller
{
    use CharacterConcern;

    public function __construct(
        private readonly GameInventoryService $inventoryService,
        private readonly RedisLockService $redisLockService,
    ) {}

    /**
     * 广播背包更新
     */
    private function broadcastInventoryUpdate(GameCharacter $character): void
    {
        broadcast(new GameInventoryUpdate($character->id, $this->inventoryService->getInventoryForBroadcast($character)));
    }

    /**
     * 获取背包物品
     */
    public function index(Request $request): JsonResponse
    {
        $character = $this->getCharacter($request);
        $result = $this->inventoryService->getInventory($character);

        return $this->success($result);
    }

    /**
     * 装备物品
     */
    public function equip(EquipItemRequest $request): JsonResponse
    {
        try {
            $character = $this->getCharacter($request);

            // 使用 Redis 分布式锁防止并发装备操作
            $lockKey = 'inventory_equip:' . $character->id;
            $lock = $this->redisLockService->lock($lockKey, 10);

            if ($lock === false) {
                return $this->error('装备操作正在进行中，请稍后再试');
            }

            try {
                $result = $this->inventoryService->equipItem($character, $request->input('item_id'));
            } finally {
                $this->redisLockService->release($lockKey, $lock);
            }

            $this->broadcastInventoryUpdate($character);

            return $this->success($result, '装备成功');
        } catch (Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 卸下装备
     */
    public function unequip(UnequipItemRequest $request): JsonResponse
    {
        try {
            $character = $this->getCharacter($request);

            // 使用 Redis 分布式锁防止并发卸下装备操作
            $lockKey = 'inventory_unequip:' . $character->id;
            $lock = $this->redisLockService->lock($lockKey, 10);

            if ($lock === false) {
                return $this->error('卸下装备操作正在进行中，请稍后再试');
            }

            try {
                $result = $this->inventoryService->unequipItem($character, $request->input('slot'));
            } finally {
                $this->redisLockService->release($lockKey, $lock);
            }

            $this->broadcastInventoryUpdate($character);

            return $this->success($result, '卸下装备成功');
        } catch (Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 出售物品
     */
    public function sell(SellItemRequest $request): JsonResponse
    {
        try {
            $character = $this->getCharacter($request);

            // 使用 Redis 分布式锁防止并发出售
            $lockKey = 'inventory_sell:' . $character->id;
            $lock = $this->redisLockService->lock($lockKey, 10);

            if ($lock === false) {
                return $this->error('出售操作正在进行中，请稍后再试');
            }

            try {
                $result = $this->inventoryService->sellItem(
                    $character,
                    $request->input('item_id'),
                    $request->input('quantity', 1)
                );
            } finally {
                $this->redisLockService->release($lockKey, $lock);
            }

            $this->broadcastInventoryUpdate($character);

            return $this->success($result, '出售成功');
        } catch (Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 移动物品
     */
    public function move(MoveItemRequest $request): JsonResponse
    {
        try {
            $character = $this->getCharacter($request);

            // 使用 Redis 分布式锁防止并发移动物品
            $lockKey = 'inventory_move:' . $character->id;
            $lock = $this->redisLockService->lock($lockKey, 10);

            if ($lock === false) {
                return $this->error('移动物品操作正在进行中，请稍后再试');
            }

            try {
                $result = $this->inventoryService->moveItem(
                    $character,
                    $request->input('item_id'),
                    $request->input('to_storage'),
                    $request->input('slot_index')
                );
            } finally {
                $this->redisLockService->release($lockKey, $lock);
            }

            $this->broadcastInventoryUpdate($character);

            return $this->success($result, '移动成功');
        } catch (Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 整理背包
     */
    public function sort(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'sort_by' => 'nullable|string|in:quality,price,default',
                'to_storage' => 'nullable|boolean',
            ]);

            $character = $this->getCharacter($request);
            $sortBy = $request->input('sort_by', 'default');
            $inStorage = $request->boolean('to_storage');
            $result = $this->inventoryService->sortInventory($character, $sortBy, $inStorage);
            $this->broadcastInventoryUpdate($character);

            $message = match ($sortBy) {
                'quality' => '按品质整理完成',
                'price' => '按价格整理完成',
                default => '按时间整理完成',
            };

            return $this->success($result, $message);
        } catch (Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 更新自动回收设置（按物品单价）
     */
    public function updateAutoRecycleSettings(UpdateAutoRecycleSettingsRequest $request): JsonResponse
    {
        try {
            $character = $this->getCharacter($request);
            $maxValue = $request->input('auto_recycle_max_value');
            $normalized = is_numeric($maxValue) ? (int) $maxValue : null;

            $lockKey = 'inventory_auto_recycle:' . $character->id;
            $lock = $this->redisLockService->lock($lockKey, 10);

            if ($lock === false) {
                return $this->error('自动回收设置更新中，请稍后再试');
            }

            try {
                $result = $this->inventoryService->updateAutoRecycleSettings($character, $normalized);
            } finally {
                $this->redisLockService->release($lockKey, $lock);
            }

            if (($result['recycled']['count'] ?? 0) > 0) {
                $this->broadcastInventoryUpdate($character);
            }

            $message = $normalized === null || $normalized <= 0
                ? '已关闭自动回收'
                : "自动回收已设为价值 ≤ {$normalized} 铜";

            if (($result['recycled']['count'] ?? 0) > 0) {
                $message .= "，已回收 {$result['recycled']['count']} 件，获得 {$result['recycled']['total_price']} 铜";
            }

            return $this->success($result, $message);
        } catch (Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 批量出售指定品质的物品
     */
    public function sellByQuality(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'quality' => 'required|string|in:common,magic,rare,legendary,mythic,all',
            ]);

            $character = $this->getCharacter($request);
            $quality = $request->input('quality');

            // 使用 Redis 分布式锁防止并发批量出售
            $lockKey = 'inventory_sell_quality:' . $character->id;
            $lock = $this->redisLockService->lock($lockKey, 10);

            if ($lock === false) {
                return $this->error('批量出售操作正在进行中，请稍后再试');
            }

            try {
                $result = $this->inventoryService->sellItemsByQuality($character, $quality);
            } finally {
                $this->redisLockService->release($lockKey, $lock);
            }

            $this->broadcastInventoryUpdate($character);

            $qualityLabel = $quality === 'all' ? '' : $this->getQualityName($quality);

            return $this->success($result, "已出售 {$result['count']} 件{$qualityLabel}物品，获得 {$result['total_price']} 铜");
        } catch (Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取品质中文名
     */
    private function getQualityName(string $quality): string
    {
        return match ($quality) {
            'common' => '普通',
            'magic' => '魔法',
            'rare' => '稀有',
            'legendary' => '传奇',
            'mythic' => '神话',
            default => $quality,
        };
    }
}
