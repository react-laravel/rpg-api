<?php

namespace Database\Factories\Game;

use App\Models\Game\GameItemDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameItemDefinition>
 */
class GameItemDefinitionFactory extends Factory
{
    protected $model = GameItemDefinition::class;

    public function definition(): array
    {
        $type = fake()->randomElement(GameItemDefinition::TYPES);
        $iconKey = fake()->unique()->slug(2);

        return array_merge([
            'name' => fake()->unique()->words(2, true),
            'type' => $type,
            'required_level' => fake()->numberBetween(1, 80),
            'icon' => $iconKey . '.png',
            'icon_prompt' => fake()->sentence(),
            'description' => fake()->sentence(),
            'is_active' => true,
            'buy_price' => fake()->numberBetween(5, 5000),
        ], $this->typeAttributes($type));
    }

    public function equipment(): static
    {
        $type = fake()->randomElement(['weapon', 'helmet', 'armor', 'gloves', 'boots', 'belt', 'ring', 'amulet']);

        return $this->state(fn (array $attributes): array => array_merge([
            'type' => $type,
        ], $this->typeAttributes($type)));
    }

    public function potion(): static
    {
        return $this->state(fn (array $attributes): array => array_merge([
            'type' => 'potion',
        ], $this->typeAttributes('potion')));
    }

    public function gem(): static
    {
        return $this->state(fn (array $attributes): array => array_merge([
            'type' => 'gem',
        ], $this->typeAttributes('gem')));
    }

    /**
     * @return array<string, mixed>
     */
    private function typeAttributes(string $type): array
    {
        return match ($type) {
            'potion' => $this->potionAttributes(),
            'gem' => $this->gemAttributes(),
            default => $this->equipmentAttributes($type),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function potionAttributes(): array
    {
        $subType = fake()->randomElement(['hp', 'mp']);

        return [
            'sub_type' => $subType,
            'sockets' => 0,
            'base_stats' => [
                'price' => fake()->numberBetween(5, 200),
            ],
            'gem_stats' => [
                'restore' => fake()->numberBetween(25, 300),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function gemAttributes(): array
    {
        $stat = fake()->randomElement(['attack', 'defense', 'max_hp', 'max_mana', 'crit_rate']);

        return [
            'sub_type' => null,
            'sockets' => 0,
            'base_stats' => [],
            'gem_stats' => [
                $stat => fake()->numberBetween(1, 25),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function equipmentAttributes(string $type): array
    {
        $subType = match ($type) {
            'weapon' => fake()->randomElement(['sword', 'axe', 'mace', 'staff', 'bow', 'dagger']),
            'helmet', 'armor', 'gloves', 'boots' => fake()->randomElement(['cloth', 'leather', 'mail', 'plate']),
            default => null,
        };

        return [
            'sub_type' => $subType,
            'sockets' => fake()->numberBetween(0, 3),
            'base_stats' => $this->equipmentStats($type),
            'gem_stats' => null,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function equipmentStats(string $type): array
    {
        return match ($type) {
            'weapon' => [
                'attack' => fake()->numberBetween(8, 60),
            ],
            'helmet', 'armor' => [
                'defense' => fake()->numberBetween(5, 40),
                'max_hp' => fake()->numberBetween(10, 120),
            ],
            'gloves' => [
                'attack' => fake()->numberBetween(3, 20),
                'dexterity' => fake()->numberBetween(1, 12),
            ],
            'boots' => [
                'defense' => fake()->numberBetween(3, 18),
                'dexterity' => fake()->numberBetween(2, 15),
            ],
            'belt' => [
                'max_hp' => fake()->numberBetween(15, 100),
                'vitality' => fake()->numberBetween(1, 10),
            ],
            'ring' => [
                'attack' => fake()->numberBetween(2, 15),
                'crit_rate' => fake()->numberBetween(1, 8),
            ],
            'amulet' => [
                'max_mana' => fake()->numberBetween(10, 80),
                'energy' => fake()->numberBetween(1, 10),
            ],
            default => [
                'attack' => fake()->numberBetween(1, 10),
            ],
        };
    }
}
