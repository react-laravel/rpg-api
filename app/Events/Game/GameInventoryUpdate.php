<?php

namespace App\Events\Game;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 背包/仓库/装备更新事件立即推送到 Reverb，减少战斗奖励与掉落同步的可见延迟。
 */
class GameInventoryUpdate implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array  $payload  与 GET /rpg/inventory 一致的数组：inventory, storage, equipment, inventory_size, storage_size
     */
    public function __construct(
        public int $characterId,
        public array $payload
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("game.{$this->characterId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'inventory.update';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
