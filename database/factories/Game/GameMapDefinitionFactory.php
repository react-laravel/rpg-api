<?php

namespace Database\Factories\Game;

use App\Models\Game\GameMapDefinition;
use App\Models\Game\GameMonsterDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameMapDefinition>
 */
class GameMapDefinitionFactory extends Factory
{
    protected $model = GameMapDefinition::class;

    public function definition(): array
    {
        $backgroundKey = fake()->unique()->slug(3);

        return [
            'name' => fake()->unique()->words(2, true),
            'act' => fake()->numberBetween(1, 8),
            'monster_ids' => [],
            'background' => $backgroundKey . '.jpg',
            'icon_prompt' => fake()->sentence(),
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }

    /**
     * @param  array<int, int>  $monsterIds
     */
    public function withMonsterIds(array $monsterIds): static
    {
        $resolvedIds = array_values(array_unique(array_map(
            'intval',
            array_filter($monsterIds, fn ($id) => (int) $id > 0)
        )));

        return $this->state(fn (array $attributes): array => [
            'monster_ids' => $resolvedIds,
        ]);
    }

    public function withMonsters(int $count = 2): static
    {
        return $this->afterCreating(function (GameMapDefinition $map) use ($count): void {
            $monsterIds = GameMonsterDefinition::factory()
                ->count(max(1, $count))
                ->create()
                ->modelKeys();

            $map->update([
                'monster_ids' => $monsterIds,
            ]);
        });
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
