<?php

namespace Tests\Feature;

use App\Models\Game\GameCharacter;
use App\Services\Game\GameCharacterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CharacterExperienceLevelCapTest extends TestCase
{
    use RefreshDatabase;

    public function test_character_list_publishes_experience_table_through_level_200(): void
    {
        GameCharacter::query()->create([
            'user_id' => 7,
            'name' => '高等级测试',
            'class' => 'warrior',
            'gender' => 'male',
            'level' => 105,
            'experience' => 19019000,
        ]);

        $result = app(GameCharacterService::class)->getCharacterList(7);

        $this->assertSame(200, $result['max_character_level']);
        $this->assertCount(200, $result['experience_table']);
        $this->assertSame(132335000, $result['experience_table'][200]);
        $this->assertSame(105, $result['characters'][0]['level']);
    }

    public function test_add_experience_can_level_past_105_and_stops_at_200(): void
    {
        $character = GameCharacter::query()->create([
            'user_id' => 8,
            'name' => '冲级测试',
            'class' => 'mage',
            'gender' => 'female',
            'level' => 105,
            'experience' => 19019000,
            'skill_points' => 0,
            'stat_points' => 0,
        ]);

        $to106 = (int) config('game.experience_table')[106];
        $result = $character->addExperience($to106 - $character->experience);

        $this->assertSame(1, $result['levels_gained']);
        $this->assertSame(106, $result['new_level']);

        $character->refresh();
        $maxXp = (int) config('game.experience_table')[200];
        $maxed = $character->addExperience(($maxXp - $character->experience) + 5_000_000);

        $this->assertSame(200, $maxed['new_level']);
        $character->refresh();
        $this->assertSame(200, $character->level);
        $this->assertTrue($character->isMaxLevel());
        $this->assertSame(PHP_INT_MAX, $character->getExperienceToNextLevel());
    }
}
