<?php

namespace Tests\Feature;

use App\Events\Game\GameCombatUpdate;
use App\Events\Game\GameInventoryUpdate;
use App\Jobs\Game\AutoCombatRoundJob;
use App\Models\Game\GameCharacter;
use App\Models\Game\GameCombatLog;
use App\Models\Game\GameMapDefinition;
use App\Models\Game\GameMonsterDefinition;
use App\Services\Game\GameCombatLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CombatLogDurationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('elapsedTimes')]
    public function test_defeat_duration_is_stored_as_nonnegative_whole_seconds(?string $startedAt, int $expected): void
    {
        $this->travelTo(Carbon::parse('2026-09-19 03:15:36.465168'));
        $map = GameMapDefinition::findOrFail(5);
        $monster = GameMonsterDefinition::findOrFail($map->monster_ids[0]);
        $character = GameCharacter::create([
            'user_id' => 7, 'name' => '日志耗时验证', 'current_map_id' => $map->id,
            'combat_started_at' => $startedAt,
        ])->fresh();

        $log = app(GameCombatLogService::class)->createDefeatLog($character, $map, $monster, [
            'new_skills_aggregated' => [],
        ]);

        // Inspect the stored value, bypassing Eloquent's read cast which hides fractional SQLite values.
        $this->assertSame($expected, DB::table('game_combat_logs')->where('id', $log->id)->value('duration_seconds'));
        $this->assertSame($expected, $log->fresh()->duration_seconds);
    }

    public static function elapsedTimes(): array
    {
        return [
            'reported subsecond duration' => ['2026-09-19 03:15:36', 0],
            'fractional multi-second duration' => ['2026-09-19 03:15:24', 12],
            'long combat' => ['2026-09-19 02:15:36', 3600],
            'missing start time' => [null, 0],
            'future start time' => ['2026-09-19 03:15:38', 0],
        ];
    }

    public function test_switching_map_then_immediate_defeat_finishes_and_allows_revival(): void
    {
        $this->travelTo(Carbon::parse('2026-09-19 03:15:36.000000'));
        Queue::fake();
        Event::fake([GameCombatUpdate::class, GameInventoryUpdate::class]);
        $payloads = [];
        Redis::shouldReceive('get')->andReturnUsing(function (string $key) use (&$payloads) {
            return $payloads[$key] ?? null;
        });
        Redis::shouldReceive('set')->andReturnUsing(function (string $key, string $value) use (&$payloads): bool {
            $payloads[$key] = $value;

            return true;
        });
        Redis::shouldReceive('setex')->andReturnUsing(function (string $key, int $ttl, string $value) use (&$payloads): bool {
            $payloads[$key] = $value;

            return true;
        });
        Redis::shouldReceive('del')->andReturnUsing(function (string $key) use (&$payloads): int {
            unset($payloads[$key]);

            return 1;
        });

        $map = GameMapDefinition::findOrFail(5);
        GameMonsterDefinition::whereIn('id', $map->monster_ids)->update(['hp_base' => 100000, 'attack_base' => 1000]);
        $character = GameCharacter::create([
            'user_id' => 7, 'name' => '切图战败验证', 'current_map_id' => 1,
            'current_hp' => 1, 'current_mana' => 120,
        ]);
        $this->withSession(['rpg_identity' => ['id' => 7, 'name' => '测试玩家']]);
        $request = ['character_id' => $character->id];

        $this->postJson("/api/rpg/maps/{$map->id}/enter", $request)
            ->assertOk()->assertJsonPath('data.character.current_map_id', $map->id);
        $this->travelTo(Carbon::parse('2026-09-19 03:15:36.465168'));
        $response = $this->postJson('/api/rpg/combat/start', $request + ['skill_ids' => []]);
        $response->assertOk()->assertJsonPath('data.defeat', true)->assertJsonPath('data.current_hp', 0);

        $log = GameCombatLog::findOrFail($response->json('data.combat_log_id'));
        $this->assertSame(0, $log->duration_seconds);
        $this->assertSame($map->id, $log->map_id);
        $this->assertFalse($log->victory);
        $character->refresh();
        $this->assertSame(0, $character->current_hp);
        $this->assertFalse($character->is_fighting);
        $this->assertNull($character->combat_started_at);
        $this->assertNull($character->combat_monsters);
        $this->assertArrayNotHasKey(AutoCombatRoundJob::redisKey($character->id), $payloads);
        Queue::assertNothingPushed();
        Event::assertDispatched(GameCombatUpdate::class, fn (GameCombatUpdate $event) => $event->characterId === $character->id
            && ($event->combatResult['defeat'] ?? false) === true
            && ($event->combatResult['current_hp'] ?? null) === 0
            && ($event->combatResult['combat_log_id'] ?? null) === $log->id
        );

        $this->postJson('/api/rpg/combat/revive', $request)->assertOk();
        $character->refresh();
        $this->assertGreaterThan(0, $character->current_hp);
        $this->assertSame(1, $character->current_map_id);
        $this->postJson("/api/rpg/maps/{$map->id}/enter", $request)
            ->assertOk()->assertJsonPath('data.character.current_map_id', $map->id);
    }
}
