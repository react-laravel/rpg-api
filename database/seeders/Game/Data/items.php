<?php

use App\Support\Game\FixedEquipmentStats;

$dir = __DIR__.'/items';

$items = array_merge(
    require $dir.'/weapons.php',
    require $dir.'/helmets.php',
    require $dir.'/armor.php',
    require $dir.'/gloves.php',
    require $dir.'/boots.php',
    require $dir.'/belts.php',
    require $dir.'/rings.php',
    require $dir.'/amulets.php',
    require $dir.'/gems.php',
    require $dir.'/mage_sets.php'
);

$presentation = require __DIR__.'/item-presentation.php';
$items = FixedEquipmentStats::apply($items);

return array_map(
    static function (array $item) use ($presentation): array {
        $assetKey = $item['asset_key'];
        $originalName = $item['name'];
        $item['name'] = $presentation['names'][$assetKey] ?? $originalName;

        // 保留每个物品自身的提示词，宝石也有完整提示词。
        $subject = $presentation['subjects'][$assetKey]
            ?? $item['icon_prompt']
            ?? ('A single fantasy RPG '.$item['type']);

        $item['icon_prompt'] = 'Catalogue name: '.$item['name'].' (metadata only, do not draw text). '
            .'Equipment level: '.$item['required_level'].'. Subject: '.rtrim($subject, ". \t\n\r").'. '
            .$presentation['style'];

        return $item;
    },
    $items
);
