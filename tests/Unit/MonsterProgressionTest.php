<?php

namespace Tests\Unit;

use App\Support\Game\MonsterProgression;
use Tests\TestCase;

class MonsterProgressionTest extends TestCase
{
    /**
     * @return array<int, array<string, mixed>>
     */
    private function maps(): array
    {
        return require database_path('seeders/Game/Data/maps.php');
    }

    public function test_training_camp_has_zero_attack_and_tiny_hp(): void
    {
        $maps = $this->maps();

        $pig = MonsterProgression::apply(['name' => '猪'], 0, $maps);
        $deer = MonsterProgression::apply(['name' => '鹿'], 1, $maps);
        $rabbit = MonsterProgression::apply(['name' => '兔'], 2, $maps);

        $this->assertSame('normal', $pig['type']);
        $this->assertSame(0, $pig['attack_base']);
        $this->assertSame(3, $pig['hp_base']);
        $this->assertSame(2, $deer['hp_base']);
        $this->assertSame(1, $rabbit['hp_base']);
    }

    public function test_dark_forest_to_goblin_lair_uses_linear_normal_hp(): void
    {
        $maps = $this->maps();

        $wolf = MonsterProgression::apply(['name' => '砂尾蜥'], 4, $maps);
        $alpha = MonsterProgression::apply(['name' => '土灵'], 6, $maps);
        $boarKing = MonsterProgression::apply(['name' => '野猪王'], 8, $maps);

        $this->assertSame('砾石小径', $maps[1]['name']);
        $this->assertSame('赤土荒坡', $maps[2]['name']);

        $this->assertSame('normal', $wolf['type']);
        $this->assertSame(6, $wolf['hp_base']);
        $this->assertSame(3, $wolf['attack_base']);

        $this->assertSame('normal', $alpha['type']);
        $this->assertSame(15, $alpha['hp_base']);
        $this->assertSame(4, $alpha['attack_base']);

        $this->assertSame('elite', $boarKing['type']);
        $this->assertSame(6, $boarKing['hp_base']);
    }

    public function test_every_map_keeps_a_normal_monster_and_normal_hp_is_monotonic(): void
    {
        $maps = $this->maps();
        $perMap = MonsterProgression::monstersPerMap();
        $previousNormalHp = [];

        foreach ($maps as $mapIndex => $map) {
            $types = [];
            for ($slot = 0; $slot < $perMap; $slot++) {
                $index = $mapIndex * $perMap + $slot;
                $stats = MonsterProgression::apply(['name' => 'x'], $index, $maps);
                $types[] = $stats['type'];

                if ($stats['type'] !== 'normal') {
                    continue;
                }

                $previous = $previousNormalHp[$slot] ?? null;
                if ($previous !== null) {
                    $this->assertGreaterThanOrEqual(
                        $previous,
                        $stats['hp_base'],
                        "Map {$map['name']} slot {$slot} normal HP should not drop"
                    );
                    if ($mapIndex >= 2) {
                        $this->assertLessThanOrEqual(
                            (int) ceil($previous * 2),
                            $stats['hp_base'],
                            "Map {$map['name']} slot {$slot} normal HP jumped more than 2x ({$previous} -> {$stats['hp_base']})"
                        );
                    }
                }
                $previousNormalHp[$slot] = $stats['hp_base'];
            }

            $this->assertContains('normal', $types, "Map {$map['name']} has no normal monster");
        }
    }

    public function test_act_finale_marks_squishy_slot_as_tanky_boss(): void
    {
        $maps = $this->maps();
        $this->assertSame('大地石庭', $maps[4]['name']);

        $tank = MonsterProgression::apply(['name' => 'tank'], 12, $maps);
        $boss = MonsterProgression::apply(['name' => 'boss'], 14, $maps);

        $this->assertSame('normal', $tank['type']);
        $this->assertSame(27, $tank['hp_base']);
        $this->assertSame('boss', $boss['type']);
        $this->assertSame(54, $boss['hp_base']);
    }

    public function test_seeded_monsters_follow_the_same_formula(): void
    {
        $seeded = require database_path('seeders/Game/Data/monsters.php');

        $this->assertSame('砂尾蜥', $seeded[4]['name']);
        $this->assertSame(6, $seeded[4]['hp_base']);
        $this->assertSame('normal', $seeded[4]['type']);

        $this->assertSame('土灵', $seeded[6]['name']);
        $this->assertSame(15, $seeded[6]['hp_base']);
        $this->assertSame('normal', $seeded[6]['type']);
        $this->assertSame(9, $seeded[6]['experience_base']);
    }
}
