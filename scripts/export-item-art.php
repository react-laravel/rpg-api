<?php

declare(strict_types=1);

// 只读取种子定义，不启动应用、不连接数据库，也不读取任何密钥。
$root = dirname(__DIR__);
require_once $root.'/app/Support/Game/FixedEquipmentStats.php';
$items = require $root.'/database/seeders/Game/Data/items.php';
$destination = $argv[1] ?? $root.'/storage/app/item-art/catalogue.json';
$catalogue = [];
$names = [];
$keys = [];

foreach ($items as $item) {
    if (isset($names[$item['name']]) || isset($keys[$item['asset_key']])) {
        throw new RuntimeException('物品名称或资源标识重复: '.$item['asset_key']);
    }
    if (trim($item['icon_prompt'] ?? '') === '') {
        throw new RuntimeException('缺少图片提示词: '.$item['name']);
    }
    $names[$item['name']] = true;
    $keys[$item['asset_key']] = true;
    $catalogue[] = [
        'id' => $item['id'],
        'name' => $item['name'],
        'type' => $item['type'],
        'sub_type' => $item['sub_type'],
        'required_level' => $item['required_level'],
        'asset_key' => $item['asset_key'],
        'filename' => $item['asset_key'].'.png',
        'origin_filename' => $item['asset_key'].'_origin.png',
        'prompt' => $item['icon_prompt'],
    ];
}

$directory = dirname($destination);
if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
    throw new RuntimeException('无法创建目录: '.$directory);
}
$json = json_encode($catalogue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
if (file_put_contents($destination, $json.PHP_EOL, LOCK_EX) === false) {
    throw new RuntimeException('无法写入清单: '.$destination);
}
echo '已导出 '.count($catalogue).' 个物品及完整图片提示词: '.$destination.PHP_EOL;
