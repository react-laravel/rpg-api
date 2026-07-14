<?php

namespace Database\Seeders\Game\Data\Skills;

/**
 * D4 风格技能树构建器：每条技能线 = 本体 + 强化 + 专精 A/B
 */
class SkillTreeBuilder
{
    /** @var array<string, int> */
    public const STAGE_UNLOCK = [
        'basic' => 1,
        'core' => 5,
        'defensive' => 15,
        'special' => 25,
        'ultimate' => 40,
        'key_passive' => 50,
    ];

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $enhanced
     * @param  array<string, mixed>  $specA
     * @param  array<string, mixed>  $specB
     * @return array<int, array<string, mixed>>
     */
    public static function line(
        string $class,
        string $stage,
        string $skillLine,
        string $effectKey,
        string $baseName,
        array $base,
        string $enhancedName,
        array $enhanced,
        array $specA,
        array $specB,
    ): array {
        $unlock = self::STAGE_UNLOCK[$stage] ?? 1;

        $baseNode = self::node($class, $stage, $skillLine, $effectKey, 0, null, $baseName, $unlock, [
            'type' => 'active',
            'skill_points_cost' => 1,
            ...$base,
        ]);

        $enhancedNode = self::node($class, $stage, $skillLine, $effectKey, 1, null, $enhancedName, $unlock, [
            'type' => 'passive',
            'skill_points_cost' => 1,
            'parent_ref' => self::parentRef($skillLine, 0, null),
            ...$enhanced,
        ]);

        $specANode = self::node($class, $stage, $skillLine, $effectKey, 2, 'a', (string) ($specA['name'] ?? '专精 A'), $unlock, [
            'type' => 'passive',
            'skill_points_cost' => 1,
            'parent_ref' => self::parentRef($skillLine, 1, null),
            ...$specA,
        ]);

        $specBNode = self::node($class, $stage, $skillLine, $effectKey, 2, 'b', (string) ($specB['name'] ?? '专精 B'), $unlock, [
            'type' => 'passive',
            'skill_points_cost' => 1,
            'parent_ref' => self::parentRef($skillLine, 1, null),
            ...$specB,
        ]);

        return [$baseNode, $enhancedNode, $specANode, $specBNode];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function keyPassive(
        string $class,
        string $skillLine,
        string $effectKey,
        string $name,
        array $overrides,
    ): array {
        return self::node($class, 'key_passive', $skillLine, $effectKey, 0, null, $name, self::STAGE_UNLOCK['key_passive'], [
            'type' => 'passive',
            'skill_points_cost' => 2,
            'mana_cost' => 0,
            'cooldown' => 0,
            ...$overrides,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  ...$groups
     * @return array<int, array<string, mixed>>
     */
    public static function merge(array ...$groups): array
    {
        $merged = [];
        foreach ($groups as $group) {
            foreach ($group as $skill) {
                $merged[] = $skill;
            }
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function node(
        string $class,
        string $stage,
        string $skillLine,
        string $effectKey,
        int $nodeTier,
        ?string $specBranch,
        string $name,
        int $unlockLevel,
        array $extra,
    ): array {
        return array_merge([
            'name' => $name,
            'effect_key' => $effectKey,
            'class_restriction' => $class,
            'skill_stage' => $stage,
            'skill_line' => $skillLine,
            'node_tier' => $nodeTier,
            'spec_branch' => $specBranch,
            'unlock_level' => $unlockLevel,
            'mana_cost' => 0,
            'cooldown' => 0,
            'target_type' => 'single',
            'effects' => [],
            'description' => '',
        ], $extra);
    }

    /**
     * @return array{skill_line: string, node_tier: int, spec_branch: null|string}
     */
    private static function parentRef(string $skillLine, int $nodeTier, ?string $specBranch): array
    {
        return [
            'skill_line' => $skillLine,
            'node_tier' => $nodeTier,
            'spec_branch' => $specBranch,
        ];
    }
}
