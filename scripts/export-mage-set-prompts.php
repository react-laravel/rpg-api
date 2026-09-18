<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$sets = require $root.'/database/seeders/Game/Data/mage-sets.php';
$destination = $argv[1] ?? $root.'/storage/app/item-art/mage-sets-prompts.md';
$lines = ['# 七元素法师套装：像素精灵图提示词', '', '每套 8 件，共 56 件。每套一张 4×2 精灵图，再程序切成 64×64 透明 PNG。仅使用单件属性，不设集齐奖励。', '', '图片无需强求 AI 原生输出 64 像素；保留源图，切分时使用最近邻缩放、有限调色板和透明像素边缘。', '', '| 套装 | 元素 | 等级 | 风格 |', '|---|---|---|---|'];
$outfits = ['# 七元素法师衣服：像素版', '', '已替换早期的写实长袍设计。完整配件的精灵图提示词见 mage-sets-prompts.md。', ''];
$elements = ['earth' => '土', 'wood' => '木', 'water' => '水', 'fire' => '火', 'metal' => '金', 'wind' => '风', 'thunder' => '雷'];
foreach ($sets as $set) {
    $lines[] = '| '.$set['name'].' | '.$elements[$set['element']].' | '.$set['level'].' | '.$set['theme'].' |';
}
foreach ($sets as $set) {
    $lines = array_merge($lines, ['', '## '.$set['name'].' · '.$elements[$set['element']], '', '```text',
        '复古RPG像素装备精灵图，'.$set['theme'].'严格4列2行，上排依次法杖、兜帽、法袍、一双护手，下排依次一双长靴、腰带、指环、护符。每格一件装备，完整居中留空，互不接触。清晰像素块、有限配色，真实透明PNG，无人物、文字或格线。', '```', '', '对应物品：'.implode('、', $set['names'])]);
    $outfits = array_merge($outfits, ['', '## '.$set['names'][2], '', '```text', '复古RPG像素法师衣服，'.$set['theme'].'参考已选定的'.$elements[$set['element']].'系衣服，保持同款。完整主体、透明背景、无文字、无人物，适合64像素图标。', '```']);
}
$directory = dirname($destination);
if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
    throw new RuntimeException('无法创建目录: '.$directory);
}
foreach ([$destination => $lines, $directory.'/mage-outfits-prompts.md' => $outfits] as $file => $content) {
    if (file_put_contents($file, implode(PHP_EOL, $content).PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('无法写入: '.$file);
    }
}
echo '已导出七元素像素精灵图与衣服提示词: '.$destination.PHP_EOL;
