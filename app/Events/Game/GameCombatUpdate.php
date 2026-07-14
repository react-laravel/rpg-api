<?php

namespace App\Events\Game;

use App\Models\Game\GameCharacter;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 战斗更新事件，立即推送到 Reverb，避免战斗回合与广播各排一次队导致首帧反馈过慢。
 */
class GameCombatUpdate implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $characterId;

    public array $combatResult;

    /**
     * Create a new event instance.
     */
    public function __construct(int $characterId, array $combatResult)
    {
        $this->characterId = $characterId;
        $this->combatResult = $combatResult;
    }

    /**
     * Get the channels the event should broadcast on.
     * 战斗数据只允许角色所有者（或管理员）订阅。
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
        return 'combat.update';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        // 确保包含 current_hp 和 current_mana
        $data = $this->combatResult;

        // 处理 character 对象(可能是 GameCharacter 实例或数组)
        if (isset($data['character'])) {
            $character = $data['character'];

            if ($character instanceof GameCharacter) {
                // 如果是模型实例，使用 current_hp/current_mana 属性(如果存在)或调用方法获取
                $data['current_hp'] = $character->getAttribute('current_hp') ?? $character->getCurrentHp();
                $data['current_mana'] = $character->getAttribute('current_mana') ?? $character->getCurrentMana();
            } elseif (is_array($character)) {
                // 如果是数组，直接使用数组中的值
                $data['current_hp'] = $character['current_hp'] ?? null;
                $data['current_mana'] = $character['current_mana'] ?? null;
            }
        }

        return $data;
    }
}
