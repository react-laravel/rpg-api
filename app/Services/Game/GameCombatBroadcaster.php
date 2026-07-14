<?php

namespace App\Services\Game;

use App\Events\Game\GameCombatUpdate;
use App\Events\Game\GameInventoryUpdate;
use Illuminate\Support\Facades\Log;
use Throwable;

class GameCombatBroadcaster
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function broadcastCombatUpdate(int $characterId, array $payload): void
    {
        // 终止事件(死亡/自动停止)是该角色战斗的最后一条推送，一旦丢失前端会卡在死亡前状态。
        // 记录一条日志，便于排查「websocket 是否发送」这类问题。
        if (! empty($payload['defeat']) || ! empty($payload['auto_stopped'])) {
            Log::info('广播战斗终止事件', [
                'character_id' => $characterId,
                'defeat' => $payload['defeat'] ?? false,
                'auto_stopped' => $payload['auto_stopped'] ?? false,
                'current_hp' => $payload['current_hp'] ?? null,
            ]);
        }

        $this->dispatchSafely(
            new GameCombatUpdate($characterId, $payload),
            'combat.update',
            $characterId
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function broadcastInventoryUpdate(int $characterId, array $payload): void
    {
        $this->dispatchSafely(
            new GameInventoryUpdate($characterId, $payload),
            'inventory.update',
            $characterId
        );
    }

    private function dispatchSafely(object $event, string $eventName, int $characterId): void
    {
        try {
            event($event);
        } catch (Throwable $e) {
            Log::warning('游戏广播发送失败，已跳过本次推送', [
                'event' => $eventName,
                'character_id' => $characterId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
