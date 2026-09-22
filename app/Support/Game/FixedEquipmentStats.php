<?php

namespace App\Support\Game;

/** Small, fixed item values. Quality affects collection/sockets/value, not combat rolls. */
final class FixedEquipmentStats
{
    public static function apply(array $items): array
    {
        $levels = [];
        foreach ($items as $item) {
            if ($item['type'] !== 'gem') {
                $levels[$item['type']][] = (int) $item['required_level'];
            }
        }
        foreach ($levels as &$values) {
            $values = array_values(array_unique($values));
            sort($values);
        }
        unset($values);

        foreach ($items as &$item) {
            $type = $item['type'];
            if ($type === 'gem') {
                continue;
            }
            $rank = array_search((int) $item['required_level'], $levels[$type], true) + 1;
            $previous = $item['base_stats'] ?? [];
            $item['base_stats'] = match ($type) {
                'weapon' => ['attack' => $rank],
                'armor' => ['defense' => 2 * $rank - 1, 'max_hp' => 2 * $rank + 1, 'max_mana' => 2 * $rank],
                'helmet' => ['defense' => $rank, 'max_hp' => 2 * $rank, 'max_mana' => $rank],
                'gloves' => ['defense' => $rank, 'max_mana' => $rank],
                'boots' => ['defense' => $rank, 'max_hp' => $rank],
                'belt' => ['max_hp' => 2 * $rank, 'max_mana' => $rank],
                'ring', 'amulet' => ['attack' => (int) ceil($rank / 2)],
                default => $previous,
            };
            if (in_array($type, ['weapon', 'ring', 'amulet'], true)) {
                if (isset($previous['crit_rate'])) {
                    $item['base_stats']['crit_rate'] = min(4, (int) ceil($rank / 3)) / 100;
                }
                if (isset($previous['crit_damage'])) {
                    $item['base_stats']['crit_damage'] = min(12, 2 * (int) ceil($rank / 2)) / 100;
                }
                if (isset($previous['energy'])) {
                    $item['base_stats']['max_mana'] = 2 * (int) ceil($rank / 2);
                }
            }
        }

        return $items;
    }
}
