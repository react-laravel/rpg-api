<?php

namespace Tests\Feature;

use App\Models\Game\GameCharacter;
use App\Services\Game\GameCharacterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameCharacterListCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_character_list_is_cached_as_plain_arrays(): void
    {
        $character = GameCharacter::query()->create([
            'user_id' => 42,
            'name' => '缓存测试角色',
            'class' => 'mage',
            'gender' => 'female',
        ]);

        $result = app(GameCharacterService::class)->getCharacterList(42);

        $this->assertIsArray($result['characters']);
        $this->assertSame($character->id, $result['characters'][0]['id']);
        $this->assertSame('缓存测试角色', $result['characters'][0]['name']);
    }
}
