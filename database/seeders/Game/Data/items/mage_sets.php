<?php

$sets = require dirname(__DIR__).'/mage-sets.php';
$slots = ['weapon', 'helmet', 'armor', 'gloves', 'boots', 'belt', 'ring', 'amulet'];

$items = [];
foreach ($sets as $set) {
    $stats = [
        'weapon' => ['attack' => $set['attack']],
        'helmet' => ['defense' => max(1, (int) round($set['defense'] * 0.6)), 'max_hp' => max(2, (int) round($set['health'] * 0.5)), 'max_mana' => $set['mana']],
        'armor' => ['defense' => $set['defense'], 'max_hp' => $set['health'], 'max_mana' => $set['mana']],
        'gloves' => ['defense' => max(1, (int) round($set['defense'] * 0.25)), 'max_mana' => max(1, (int) round($set['mana'] * 0.5))],
        'boots' => ['defense' => max(1, (int) round($set['defense'] * 0.35)), 'max_hp' => max(2, (int) round($set['health'] * 0.2))],
        'belt' => ['max_hp' => max(2, (int) round($set['health'] * 0.3)), 'max_mana' => max(1, (int) round($set['mana'] * 0.5))],
        'ring' => ['attack' => max(1, (int) round($set['attack'] * 0.15))],
        'amulet' => ['attack' => max(1, (int) round($set['attack'] * 0.2))],
    ];

    foreach ($slots as $index => $type) {
        $items[] = [
            'id' => $set['first_id'] + $index,
            'name' => $set['names'][$index],
            'type' => $type,
            'sub_type' => match ($type) {
                'weapon' => 'staff',
                'ring', 'amulet' => null,
                default => 'cloth',
            },
            'base_stats' => $stats[$type],
            'required_level' => $set['level'],
            'sockets' => 0,
            'asset_key' => 'mage-set-'.$set['key'].'-'.$type,
            'description' => $set['name'].'套装 · '.$set['theme'],
            'icon_prompt' => $set['visual'].'物品：'.$set['names'][$index].'。64像素图标，透明背景，无文字。',
        ];
    }
}

return $items;
