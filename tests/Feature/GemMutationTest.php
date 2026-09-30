<?php

namespace Tests\Feature;

use App\Models\Game\GameCharacter;
use App\Models\Game\GameItem;
use App\Models\Game\GameItemDefinition;
use App\Models\Game\GameItemGem;
use App\Services\Game\GameInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class GemMutationTest extends TestCase
{
    use RefreshDatabase;

    private GameCharacter $character;

    private GameItem $equipment;

    private GameItemDefinition $gemDefinition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = GameCharacter::create(['user_id' => 7, 'name' => '宝石测试'])->refresh();
        $definition = GameItemDefinition::factory()->equipment()->create(['type' => 'weapon']);
        $this->equipment = $this->item($definition, ['is_equipped' => true, 'sockets' => 3]);
        $this->character->equipment()->create(['slot' => 'weapon', 'item_id' => $this->equipment->id]);
        $this->gemDefinition = GameItemDefinition::factory()->gem()->create(['gem_stats' => ['attack' => 10]]);
        $this->withSession(['rpg_identity' => ['id' => 7, 'name' => '测试']]);
    }

    public function test_socket_consumes_one_gem_from_a_stack_and_returns_the_remaining_item(): void
    {
        $gem = $this->item($this->gemDefinition, ['quantity' => 3, 'slot_index' => 0]);
        $attackBefore = $this->character->getCombatStats()['attack'];

        $this->postJson('/api/rpg/gems/socket', $this->socketPayload($gem))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.gem_item.id', $gem->id)
            ->assertJsonPath('data.gem_item.quantity', 2)
            ->assertJsonPath('data.gem_item.definition.id', $this->gemDefinition->id)
            ->assertJsonPath('data.equipment.gems.0.gem_definition_id', $this->gemDefinition->id)
            ->assertJsonPath('data.combat_stats.attack', $attackBefore + 10);

        $this->assertDatabaseHas('game_items', ['id' => $gem->id, 'quantity' => 2, 'slot_index' => 0]);
        $this->assertDatabaseCount('game_item_gems', 1);
    }

    public function test_socket_removes_only_the_last_gem_and_returns_null(): void
    {
        $gem = $this->item($this->gemDefinition, ['slot_index' => 0]);

        $this->postJson('/api/rpg/gems/socket', $this->socketPayload($gem))
            ->assertOk()->assertJsonPath('data.gem_item', null);

        $this->assertDatabaseMissing('game_items', ['id' => $gem->id]);
        $this->assertDatabaseHas('game_item_gems', [
            'item_id' => $this->equipment->id,
            'gem_definition_id' => $this->gemDefinition->id,
            'socket_index' => 0,
        ]);
    }

    public function test_repeating_a_socket_request_does_not_consume_another_stack_unit(): void
    {
        $gem = $this->item($this->gemDefinition, ['quantity' => 3, 'slot_index' => 0]);
        $payload = $this->socketPayload($gem);

        $this->postJson('/api/rpg/gems/socket', $payload)->assertOk();
        $this->postJson('/api/rpg/gems/socket', $payload)
            ->assertUnprocessable()->assertJsonPath('message', '该插槽已有宝石，请先卸下');

        $this->assertSame(2, $gem->fresh()->quantity);
        $this->assertDatabaseCount('game_item_gems', 1);
    }

    public function test_a_consumed_gem_cannot_be_reused_in_a_different_socket(): void
    {
        $gem = $this->item($this->gemDefinition, ['slot_index' => 0]);
        $this->postJson('/api/rpg/gems/socket', $this->socketPayload($gem))->assertOk();

        $this->postJson('/api/rpg/gems/socket', $this->socketPayload($gem, 1))->assertUnprocessable();

        $this->assertDatabaseCount('game_item_gems', 1);
    }

    public function test_socket_rolls_back_the_socket_record_when_consuming_the_gem_fails(): void
    {
        $gem = $this->item($this->gemDefinition, ['slot_index' => 0]);
        GameItem::deleting(function (GameItem $item) use ($gem): void {
            if ($item->id === $gem->id) {
                throw new RuntimeException('Simulated gem deletion failure');
            }
        });
        $this->withoutExceptionHandling();

        try {
            $this->postJson('/api/rpg/gems/socket', $this->socketPayload($gem));
            $this->fail('Expected the simulated failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated gem deletion failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('game_item_gems', 0);
        $this->assertDatabaseHas('game_items', ['id' => $gem->id, 'quantity' => 1]);
    }

    public function test_unsocket_returns_one_gem_in_the_first_available_inventory_slot(): void
    {
        $socket = $this->socketRecord();
        $this->item($this->gemDefinition, ['slot_index' => 0]);
        $this->item($this->gemDefinition, ['slot_index' => 2]);
        $attackBefore = $this->character->getCombatStats()['attack'];

        $response = $this->postJson('/api/rpg/gems/unsocket', $this->unsocketPayload())
            ->assertOk()
            ->assertJsonPath('data.gem_item.quantity', 1)
            ->assertJsonPath('data.gem_item.slot_index', 1)
            ->assertJsonPath('data.gem_item.definition.id', $this->gemDefinition->id)
            ->assertJsonPath('data.equipment.gems', [])
            ->assertJsonPath('data.combat_stats.attack', $attackBefore - 10);

        $this->assertDatabaseMissing('game_item_gems', ['id' => $socket->id]);
        $this->assertDatabaseHas('game_items', [
            'id' => $response->json('data.gem_item.id'),
            'character_id' => $this->character->id,
            'definition_id' => $this->gemDefinition->id,
            'is_in_storage' => false,
            'slot_index' => 1,
        ]);
    }

    public function test_repeating_an_unsocket_request_does_not_create_another_gem(): void
    {
        $this->socketRecord();

        $this->postJson('/api/rpg/gems/unsocket', $this->unsocketPayload())->assertOk();
        $this->postJson('/api/rpg/gems/unsocket', $this->unsocketPayload())
            ->assertUnprocessable()->assertJsonPath('message', '该插槽没有宝石');

        $this->assertSame(1, $this->character->items()->where('definition_id', $this->gemDefinition->id)->count());
        $this->assertDatabaseCount('game_item_gems', 0);
    }

    public function test_unsocket_rolls_back_the_created_item_when_removing_the_socket_fails(): void
    {
        $socket = $this->socketRecord();
        GameItemGem::deleting(function (): void {
            throw new RuntimeException('Simulated socket deletion failure');
        });
        $this->withoutExceptionHandling();

        try {
            $this->postJson('/api/rpg/gems/unsocket', $this->unsocketPayload());
            $this->fail('Expected the simulated failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated socket deletion failure', $exception->getMessage());
        }

        $this->assertDatabaseHas('game_item_gems', ['id' => $socket->id]);
        $this->assertSame(0, $this->character->items()->where('definition_id', $this->gemDefinition->id)->count());
    }

    public function test_full_inventory_leaves_the_socketed_gem_untouched(): void
    {
        $socket = $this->socketRecord();
        for ($slot = 0; $slot < GameInventoryService::INVENTORY_SIZE; $slot++) {
            $this->item($this->gemDefinition, ['slot_index' => $slot]);
        }

        $this->postJson('/api/rpg/gems/unsocket', $this->unsocketPayload())
            ->assertUnprocessable()->assertJsonPath('message', '背包已满，无法卸下宝石');

        $this->assertDatabaseHas('game_item_gems', ['id' => $socket->id]);
        $this->assertSame(GameInventoryService::INVENTORY_SIZE, $this->character->getInventoryCount());
    }

    public function test_unsocket_never_creates_an_item_without_an_inventory_slot(): void
    {
        $socket = $this->socketRecord();
        $this->mock(GameInventoryService::class)
            ->shouldReceive('findEmptySlot')->once()->andReturnNull();

        $this->postJson('/api/rpg/gems/unsocket', $this->unsocketPayload())
            ->assertUnprocessable()->assertJsonPath('message', '背包已满，无法卸下宝石');

        $this->assertDatabaseHas('game_item_gems', ['id' => $socket->id]);
        $this->assertSame(0, $this->character->getInventoryCount());
    }

    public function test_socket_rejects_another_characters_gem_without_changing_either_inventory(): void
    {
        $other = GameCharacter::create(['user_id' => 8, 'name' => '其他角色']);
        $gem = $this->item($this->gemDefinition, ['character_id' => $other->id, 'slot_index' => 0]);

        $this->postJson('/api/rpg/gems/socket', $this->socketPayload($gem))->assertNotFound();

        $this->assertDatabaseHas('game_items', ['id' => $gem->id, 'character_id' => $other->id]);
        $this->assertDatabaseCount('game_item_gems', 0);
    }

    public function test_both_mutations_reject_another_characters_equipment(): void
    {
        $other = GameCharacter::create(['user_id' => 8, 'name' => '其他角色']);
        $this->equipment->update(['character_id' => $other->id]);
        $socket = $this->socketRecord();
        $gem = $this->item($this->gemDefinition, ['slot_index' => 0]);

        $this->postJson('/api/rpg/gems/socket', $this->socketPayload($gem, 1))->assertNotFound();
        $this->postJson('/api/rpg/gems/unsocket', $this->unsocketPayload())->assertNotFound();

        $this->assertDatabaseHas('game_item_gems', ['id' => $socket->id]);
        $this->assertDatabaseHas('game_items', ['id' => $gem->id, 'quantity' => 1]);
    }

    public function test_a_stored_or_equipped_gem_cannot_be_consumed(): void
    {
        $gem = $this->item($this->gemDefinition, ['is_in_storage' => true, 'slot_index' => 0]);
        $this->postJson('/api/rpg/gems/socket', $this->socketPayload($gem))->assertNotFound();
        $gem->update(['is_in_storage' => false, 'is_equipped' => true, 'slot_index' => null]);
        $this->postJson('/api/rpg/gems/socket', $this->socketPayload($gem))->assertNotFound();

        $this->assertDatabaseHas('game_items', ['id' => $gem->id, 'quantity' => 1]);
        $this->assertDatabaseCount('game_item_gems', 0);
    }

    public function test_invalid_socket_indexes_and_empty_stacks_do_not_consume_gems(): void
    {
        $gem = $this->item($this->gemDefinition, ['slot_index' => 0]);
        config(['game.max_item_sockets' => 2]);

        foreach ([-1, 2, 3] as $index) {
            $this->postJson('/api/rpg/gems/socket', $this->socketPayload($gem, $index))->assertUnprocessable();
        }
        $this->assertSame(1, $gem->fresh()->quantity);
        $gem->update(['quantity' => 0]);
        $this->postJson('/api/rpg/gems/socket', $this->socketPayload($gem))
            ->assertUnprocessable()->assertJsonPath('message', '宝石数量不足');

        $this->assertDatabaseCount('game_item_gems', 0);
    }

    public function test_missing_gem_definitions_leave_existing_items_and_sockets_untouched(): void
    {
        $gem = $this->item($this->gemDefinition, ['slot_index' => 0]);
        $socket = $this->socketRecord();
        $this->gemDefinition->delete();

        $this->postJson('/api/rpg/gems/socket', $this->socketPayload($gem, 1))->assertUnprocessable();
        $this->postJson('/api/rpg/gems/unsocket', $this->unsocketPayload())->assertUnprocessable();

        $this->assertDatabaseHas('game_items', ['id' => $gem->id, 'quantity' => 1]);
        $this->assertDatabaseHas('game_item_gems', ['id' => $socket->id]);
        $this->assertDatabaseCount('game_items', 2);
    }

    private function item(GameItemDefinition $definition, array $attributes = []): GameItem
    {
        return GameItem::create($attributes + [
            'character_id' => $this->character->id,
            'definition_id' => $definition->id,
            'quality' => 'common',
            'quantity' => 1,
            'stats' => [],
            'affixes' => [],
            'is_in_storage' => false,
            'is_equipped' => false,
            'sockets' => 0,
        ]);
    }

    private function socketRecord(): GameItemGem
    {
        return $this->equipment->gems()->create([
            'gem_definition_id' => $this->gemDefinition->id,
            'socket_index' => 0,
        ]);
    }

    private function socketPayload(GameItem $gem, int $socketIndex = 0): array
    {
        return $this->unsocketPayload($socketIndex) + ['gem_item_id' => $gem->id];
    }

    private function unsocketPayload(int $socketIndex = 0): array
    {
        return [
            'character_id' => $this->character->id,
            'item_id' => $this->equipment->id,
            'socket_index' => $socketIndex,
        ];
    }
}
