<?php

namespace App\Support\Game;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class BalanceUpgradeBackup
{
    public const TABLES = ['game_item_definitions', 'game_items', 'game_item_gems', 'game_equipment', 'game_characters', 'game_map_definitions', 'game_monster_definitions'];

    public function create(?string $directory = null): string
    {
        $directory ??= storage_path('app/upgrade-backups');
        File::ensureDirectoryExists($directory, 0700);
        $path = $directory.'/fixed-gear-v1-'.now()->format('Ymd-His').'-'.Str::uuid().'.json.gz';
        $temporary = $path.'.tmp';
        $stream = gzopen($temporary, 'wb9');
        if ($stream === false) {
            throw new \RuntimeException('无法创建固定装备升级备份');
        }
        chmod($temporary, 0600);
        $write = static function (string $value) use ($stream): void {
            if (gzwrite($stream, $value) !== strlen($value)) {
                throw new \RuntimeException('固定装备升级备份写入失败');
            }
        };
        try {
            $write('{');
            foreach (self::TABLES as $index => $table) {
                $write(($index ? ',' : '').json_encode($table).':[');
                $first = true;
                foreach (DB::table($table)->orderBy('id')->cursor() as $row) {
                    $write(($first ? '' : ',').json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                    $first = false;
                }
                $write(']');
            }
            $write('}');
        } finally {
            gzclose($stream);
        }
        if (! rename($temporary, $path)) {
            throw new \RuntimeException('无法完成固定装备升级备份');
        }

        return $path;
    }
}
