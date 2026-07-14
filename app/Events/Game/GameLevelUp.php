<?php

namespace App\Events\Game;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GameLevelUp implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $characterId;

    public int $newLevel;

    public int $skillPointsGained;

    public int $statPointsGained;

    /**
     * Create a new event instance.
     */
    public function __construct(int $characterId, int $newLevel, int $skillPointsGained = 1, int $statPointsGained = 5)
    {
        $this->characterId = $characterId;
        $this->newLevel = $newLevel;
        $this->skillPointsGained = $skillPointsGained;
        $this->statPointsGained = $statPointsGained;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("game.{$this->characterId}"),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'level.up';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'level' => $this->newLevel,
            'character' => ['level' => $this->newLevel],
        ];
    }
}
