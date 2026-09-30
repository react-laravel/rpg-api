<?php

namespace App\Http\Controllers\Api\Game;

use App\Http\Controllers\Concerns\CharacterConcern;
use App\Http\Controllers\Controller;
use App\Http\Requests\Game\SocketGemRequest;
use App\Http\Requests\Game\UnsocketGemRequest;
use App\Models\Game\GameCharacter;
use App\Models\Game\GameItem;
use App\Models\Game\GameItemGem;
use App\Services\Game\GameInventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GemController extends Controller
{
    use CharacterConcern;

    /** @var array<int, string> */
    private const EQUIPMENT_SLOT_TYPES = [
        'weapon', 'helmet', 'armor', 'gloves', 'boots', 'belt', 'ring', 'amulet',
    ];

    public function __construct(
        private readonly GameInventoryService $inventoryService,
    ) {}

    /**
     * 镶嵌宝石到装备
     */
    public function socket(SocketGemRequest $request): JsonResponse
    {
        $character = $this->getCharacter($request);

        return DB::transaction(function () use ($request, $character): JsonResponse {
            // 串行处理同一角色的宝石操作，并在锁内重新读取物品和背包空间。
            $character = GameCharacter::whereKey($character->id)->lockForUpdate()->firstOrFail();

            // 获取装备和宝石
            /** @var GameItem $equipment */
            $equipment = GameItem::where('id', $request->input('item_id'))
                ->where('character_id', $character->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var GameItem $gemItem */
            $gemItem = GameItem::where('id', $request->input('gem_item_id'))
                ->where('character_id', $character->id)
                ->where('is_in_storage', false)
                ->where(function ($query) {
                    $query->where('is_equipped', false)->orWhereNull('is_equipped');
                })
                ->lockForUpdate()
                ->firstOrFail();

            $gemDefinition = $gemItem->definition;

            // 验证是否为宝石
            if ($gemDefinition?->type !== 'gem') {
                return $this->error('只能镶嵌宝石');
            }

            if ($gemItem->quantity < 1) {
                return $this->error('宝石数量不足');
            }

            // 验证装备类型
            if (! in_array($equipment->definition?->type, self::EQUIPMENT_SLOT_TYPES, true)) {
                return $this->error('只能向装备镶嵌宝石');
            }

            $maxSockets = min((int) $equipment->sockets, (int) config('game.max_item_sockets', 3));

            // 验证插槽数量
            if ($maxSockets <= 0) {
                return $this->error('该装备没有宝石插槽');
            }

            // 验证插槽索引
            if ($request->input('socket_index') >= $maxSockets) {
                return $this->error('插槽索引超出范围');
            }

            // 检查该插槽是否已有宝石
            $existingGem = GameItemGem::where('item_id', $equipment->id)
                ->where('socket_index', $request->input('socket_index'))
                ->lockForUpdate()
                ->first();

            if ($existingGem) {
                return $this->error('该插槽已有宝石，请先卸下');
            }

            // 镶嵌宝石
            GameItemGem::create([
                'item_id' => $equipment->id,
                'gem_definition_id' => $gemDefinition->id,
                'socket_index' => $request->input('socket_index'),
            ]);

            // 每次只消耗一颗宝石，保留尚未耗尽的堆叠
            if ($gemItem->quantity > 1) {
                $gemItem->decrement('quantity');
                $gemItem->refresh()->load('definition');
            } else {
                // 删除宝石物品
                $gemItem->delete();
                $gemItem = null;
            }

            $equipment->refresh()->load('definition', 'gems.gemDefinition');

            return $this->success([
                'equipment' => $equipment,
                'gem_item' => $gemItem,
                'combat_stats' => $character->getCombatStats(),
                'stats_breakdown' => $character->getCombatStatsBreakdown(),
                'message' => '宝石镶嵌成功',
            ], '宝石镶嵌成功');
        });
    }

    /**
     * 从装备卸下宝石
     */
    public function unsocket(UnsocketGemRequest $request): JsonResponse
    {
        $character = $this->getCharacter($request);

        return DB::transaction(function () use ($request, $character): JsonResponse {
            // 串行处理同一角色的宝石操作，并在锁内重新读取物品和背包空间。
            $character = GameCharacter::whereKey($character->id)->lockForUpdate()->firstOrFail();

            // 获取装备
            /** @var GameItem $equipment */
            $equipment = GameItem::where('id', $request->input('item_id'))
                ->where('character_id', $character->id)
                ->lockForUpdate()
                ->firstOrFail();

            // 查找宝石
            $gem = GameItemGem::where('item_id', $equipment->id)
                ->where('socket_index', $request->input('socket_index'))
                ->lockForUpdate()
                ->first();

            if (! $gem) {
                return $this->error('该插槽没有宝石');
            }

            $gemDefinition = $gem->gemDefinition;
            if ($gemDefinition?->type !== 'gem') {
                return $this->error('宝石定义不存在或无效');
            }

            // 检查背包空间
            if ($character->isInventoryFull()) {
                return $this->error('背包已满，无法卸下宝石');
            }

            // 找到空位
            $slotIndex = $this->inventoryService->findEmptySlot($character, false);
            if ($slotIndex === null) {
                return $this->error('背包已满，无法卸下宝石');
            }

            // 创建宝石物品
            $gemItem = GameItem::create([
                'character_id' => $character->id,
                'definition_id' => $gemDefinition->id,
                'quality' => 'common',
                'stats' => [],
                'affixes' => [],
                'is_in_storage' => false,
                'quantity' => 1,
                'slot_index' => $slotIndex,
                'sockets' => 0,
            ]);

            // 删除镶嵌记录
            $gem->delete();

            $equipment->refresh()->load('definition', 'gems.gemDefinition');
            $gemItem->load('definition');

            return $this->success([
                'equipment' => $equipment,
                'gem_item' => $gemItem,
                'combat_stats' => $character->getCombatStats(),
                'stats_breakdown' => $character->getCombatStatsBreakdown(),
                'message' => '宝石卸下成功',
            ], '宝石卸下成功');
        });
    }

    /**
     * 获取装备的宝石信息
     */
    public function getGems(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'item_id' => 'required|integer|exists:game_items,id',
        ]);

        $character = $this->getCharacter($request);

        /** @var GameItem $item */
        $item = GameItem::where('id', $validated['item_id'])
            ->where('character_id', $character->id)
            ->with('gems.gemDefinition')
            ->firstOrFail();

        return $this->success([
            'item' => $item,
            'sockets' => $item->sockets,
            'socketed_gems' => $item->gems,
        ]);
    }
}
