<?php

namespace Tests\Unit;

use App\Services\Game\Combat\FamiliarCombat;
use App\Support\Game\Familiar;
use Tests\TestCase;

class FamiliarTest extends TestCase
{
    public function test_form_follows_character_level_and_kills_raise_pet_level(): void
    {
        $this->assertSame('小兽', Familiar::profile(15)['name']);
        $this->assertSame('石像', Familiar::profile(40)['name']);
        $this->assertSame('狼妖', Familiar::profile(80)['name']);

        $pet = Familiar::summon(null, 15, 1, 7, 0, 0);
        $this->assertSame(1, $pet['level']);
        $this->assertSame(10, $pet['hp']);
        $this->assertSame(2, $pet['attack']);

        $pet = Familiar::grantXp($pet, Familiar::xpToAdvance(1), 15, 7, 0, 0);
        $this->assertSame(2, $pet['level']);
        $this->assertSame(3, $pet['attack']);
        $this->assertSame(14, $pet['max_hp']);
    }

    public function test_beast_spirit_raises_the_floor_and_cap_only_when_summoning(): void
    {
        $revived = Familiar::summon(['level' => 1, 'experience' => 3, 'hp' => 0, 'max_hp' => 10], 15, 3, 9, 0, 0);
        $this->assertSame(3, $revived['level']);
        $this->assertSame($revived['max_hp'], $revived['hp']);

        $alive = Familiar::summon(['level' => 2, 'experience' => 0, 'hp' => 4, 'max_hp' => 14], 15, 3, 9, 0, 0);
        $this->assertSame(2, $alive['level']);
        $this->assertSame(4, $alive['hp']);
    }

    public function test_a_downed_pet_is_summoned_again_at_the_floor(): void
    {
        $revived = Familiar::summon(['level' => 7, 'experience' => 12, 'hp' => 0, 'max_hp' => 34], 15, 1, 7, 0, 0);

        $this->assertSame(1, $revived['level']);
        $this->assertSame(0, $revived['experience']);
        $this->assertSame($revived['max_hp'], $revived['hp']);
        $this->assertSame(10, $revived['max_hp']);

        $withFloor = Familiar::summon(['level' => 7, 'experience' => 12, 'hp' => 0, 'max_hp' => 34], 15, 3, 9, 0, 0);

        $this->assertSame(3, $withFloor['level']);
        $this->assertSame(0, $withFloor['experience']);
    }

    public function test_counterstrikes_pick_player_or_pet_per_monster_and_stop_at_zero_hp(): void
    {
        $combat = new FamiliarCombat;
        $monsters = [
            ['hp' => 10, 'attack' => 2],
            ['hp' => 10, 'attack' => 2],
            ['hp' => 10, 'attack' => 2],
        ];
        $rolls = [true, true, false];
        $result = $combat->applyCounterstrikes($monsters, 0, ['hp' => 3, 'max_hp' => 10], function () use (&$rolls): bool {
            return (bool) array_shift($rolls);
        });

        $this->assertSame(2, $result['player']);
        $this->assertSame(0, $result['pet']['hp']);
    }

    public function test_pet_attacks_one_chosen_monster(): void
    {
        $combat = new FamiliarCombat;
        $monsters = [
            ['hp' => 8, 'defense' => 0, 'damage_taken' => -1],
            ['hp' => 8, 'defense' => 0, 'damage_taken' => -1],
        ];

        [$updated, $dealt] = $combat->attack($monsters, ['hp' => 10, 'attack' => 3], fn (array $indexes): int => $indexes[1]);

        $this->assertSame(3, $dealt);
        $this->assertSame(8, $updated[0]['hp']);
        $this->assertSame(5, $updated[1]['hp']);
        $this->assertSame(3, $updated[1]['pet_swing']);
    }

    public function test_pet_still_swings_when_the_player_already_killed_the_monster(): void
    {
        $combat = new FamiliarCombat;
        $monsters = [
            ['hp' => 0, 'defense' => 0, 'damage_taken' => 8, 'name' => '史莱姆'],
        ];

        [$updated, $dealt, $action] = $combat->attack(
            $monsters,
            ['hp' => 10, 'attack' => 3, 'name' => '小兽'],
            null,
            [0]
        );

        $this->assertSame(0, $dealt);
        $this->assertSame(0, $updated[0]['hp']);
        $this->assertSame(8, $updated[0]['damage_taken']);
        $this->assertSame(3, $updated[0]['pet_swing']);
        $this->assertArrayNotHasKey('pet_damage', $updated[0]);
        $this->assertSame(3, $action['damage']);
        $this->assertSame('史莱姆', $action['monster_name']);
    }

    public function test_a_monster_killed_this_pulse_still_hits_the_living_pet(): void
    {
        $combat = new FamiliarCombat;
        $monsters = [
            ['hp' => 0, 'attack' => 4],
        ];

        $result = $combat->applyCounterstrikes($monsters, 0, ['hp' => 10, 'name' => '小兽'], fn (): bool => true, [0]);

        $this->assertSame(0, $result['player']);
        $this->assertSame(4, $result['pet_damage']);
        $this->assertSame(6, $result['pet']['hp']);
    }

    public function test_a_dead_monster_does_not_counter_without_a_pet_on_the_field(): void
    {
        $combat = new FamiliarCombat;

        $result = $combat->applyCounterstrikes(
            [['hp' => 0, 'attack' => 4]],
            0,
            ['hp' => 10],
            fn (): bool => true
        );

        $this->assertSame(0, $result['player']);
        $this->assertSame(0, $result['pet_damage']);
        $this->assertSame(10, $result['pet']['hp']);
    }
}
