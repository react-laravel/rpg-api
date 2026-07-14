<?php

namespace Database\Factories\Game;

use App\Models\Game\GameMonsterDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameMonsterDefinition>
 */
class GameMonsterDefinitionFactory extends Factory
{
    protected $model = GameMonsterDefinition::class;

    public function definition(): array
    {
        $iconKey = fake()->unique()->slug(2);

        return [
            'name' => fake()->unique()->words(2, true),
            'type' => fake()->randomElement(GameMonsterDefinition::TYPES),
            'level' => fake()->numberBetween(1, 80),
            'hp_base' => fake()->numberBetween(30, 800),
            'attack_base' => fake()->numberBetween(5, 150),
            'defense_base' => fake()->numberBetween(1, 100),
            'experience_base' => fake()->numberBetween(10, 600),
            'drop_table' => [
                'potion_chance' => fake()->randomFloat(2, 0, 1),
                'item_chance' => fake()->randomFloat(2, 0, 1),
                'item_types' => fake()->randomElements(
                    ['weapon', 'helmet', 'armor', 'gloves', 'boots', 'ring', 'amulet', 'belt'],
                    fake()->numberBetween(1, 3)
                ),
            ],
            'icon' => $iconKey . '.png',
            'icon_prompt' => fake()->sentence(),
            'is_active' => true,
        ];
    }

    public function elite(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => 'elite',
        ]);
    }

    public function boss(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => 'boss',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
