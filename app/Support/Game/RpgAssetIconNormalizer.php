<?php

namespace App\Support\Game;

class RpgAssetIconNormalizer
{
    /** @var list<string>|null */
    private static ?array $monsterLegacyFiles = null;

    /** @var list<string>|null */
    private static ?array $skillLegacyFiles = null;

    /** @var list<string>|null */
    private static ?array $itemLegacyFiles = null;

    /** @var list<string>|null */
    private static ?array $mapLegacyFiles = null;

    public static function normalizeMonster(?string $icon): ?string
    {
        return self::normalizeIndexedAsset($icon, 'monster', self::monsterLegacyFiles());
    }

    public static function normalizeSkill(?string $icon): ?string
    {
        return self::normalizeIndexedAsset($icon, 'skill', self::skillLegacyFiles());
    }

    public static function normalizeItem(?string $icon): ?string
    {
        return self::normalizeIndexedAsset($icon, 'item', self::itemLegacyFiles());
    }

    public static function normalizeMapBackground(?string $background): ?string
    {
        return self::normalizeIndexedAsset($background, 'map', self::mapLegacyFiles());
    }

    /**
     * @param  array<string, mixed>  $monster
     * @return array<string, mixed>
     */
    public static function normalizeMonsterCombatPayload(array $monster): array
    {
        if (array_key_exists('icon', $monster)) {
            $monster['icon'] = self::normalizeMonster(
                is_string($monster['icon']) ? $monster['icon'] : null
            );
        }

        return $monster;
    }

    /**
     * @param  array<int, array<string, mixed>|null>|null  $monsters
     * @return array<int, array<string, mixed>|null>
     */
    public static function normalizeMonsterCombatList(?array $monsters): array
    {
        if ($monsters === null) {
            return [];
        }

        return array_map(
            fn (mixed $monster): mixed => is_array($monster)
                ? self::normalizeMonsterCombatPayload($monster)
                : $monster,
            $monsters
        );
    }

    /**
     * @param  list<string>  $legacyFiles
     */
    private static function normalizeIndexedAsset(?string $asset, string $kind, array $legacyFiles): ?string
    {
        if ($asset === null || $asset === '') {
            return $asset;
        }

        if (str_starts_with($asset, '/')) {
            return $asset;
        }

        $replaceBasename = static function (string $basename) use ($legacyFiles, $kind): string {
            if (! preg_match('/^' . $kind . '_(\\d+)\\.(png|jpe?g|webp|gif|svg|jpg)$/i', $basename, $match)) {
                return $basename;
            }

            $index = (int) $match[1] - 1;

            return $legacyFiles[$index] ?? $basename;
        };

        if (preg_match('#^https?://#i', $asset) === 1) {
            $parts = parse_url($asset);
            $path = $parts['path'] ?? '';
            $basename = basename($path);
            $newBasename = $replaceBasename($basename);
            if ($newBasename === $basename) {
                return $asset;
            }

            $dir = dirname($path);
            $newPath = ($dir === '.' || $dir === '/' ? '' : rtrim($dir, '/') . '/') . $newBasename;
            $scheme = $parts['scheme'] ?? 'https';
            $host = $parts['host'] ?? '';

            return "{$scheme}://{$host}{$newPath}";
        }

        return $replaceBasename($asset);
    }

    /**
     * @return list<string>
     */
    private static function monsterLegacyFiles(): array
    {
        if (self::$monsterLegacyFiles !== null) {
            return self::$monsterLegacyFiles;
        }

        /** @var list<array<string, mixed>> $monsters */
        $monsters = require database_path('seeders/Game/Data/monsters.php');

        self::$monsterLegacyFiles = array_map(
            static function (array $monster): string {
                $assetKey = $monster['asset_key']
                    ?? ('monster_' . strtolower(str_replace(' ', '_', (string) $monster['name'])));

                return $assetKey . '.png';
            },
            $monsters
        );

        return self::$monsterLegacyFiles;
    }

    /**
     * @return list<string>
     */
    private static function skillLegacyFiles(): array
    {
        if (self::$skillLegacyFiles !== null) {
            return self::$skillLegacyFiles;
        }

        $skillsDir = database_path('seeders/Game/Data/Skills');
        $skillFiles = [
            'skills_warrior.php',
            'skills_mage.php',
            'skills_ranger.php',
            'skills_common.php',
        ];
        $skills = [];
        foreach ($skillFiles as $file) {
            $path = $skillsDir . '/' . $file;
            if (file_exists($path)) {
                $skills = array_merge($skills, require $path);
            }
        }

        self::$skillLegacyFiles = array_map(
            static function (array $skill): string {
                if (! empty($skill['icon']) && is_string($skill['icon'])) {
                    return $skill['icon'];
                }

                if (! empty($skill['effect_key']) && is_string($skill['effect_key'])) {
                    return $skill['effect_key'] . '.png';
                }

                return 'skill_' . strtolower(str_replace(' ', '_', (string) $skill['name'])) . '.png';
            },
            $skills
        );

        return self::$skillLegacyFiles;
    }

    /**
     * @return list<string>
     */
    private static function itemLegacyFiles(): array
    {
        if (self::$itemLegacyFiles !== null) {
            return self::$itemLegacyFiles;
        }

        /** @var list<array<string, mixed>> $items */
        $items = require database_path('seeders/Game/Data/items.php');

        self::$itemLegacyFiles = array_map(
            static function (array $item): string {
                $assetKey = $item['asset_key'] ?? ('item_' . $item['id']);

                return $assetKey . '.png';
            },
            $items
        );

        return self::$itemLegacyFiles;
    }

    /**
     * @return list<string>
     */
    private static function mapLegacyFiles(): array
    {
        if (self::$mapLegacyFiles !== null) {
            return self::$mapLegacyFiles;
        }

        /** @var list<array<string, mixed>> $maps */
        $maps = require database_path('seeders/Game/Data/maps.php');

        self::$mapLegacyFiles = array_map(
            static function (array $map, int $index): string {
                $assetKey = $map['asset_key'] ?? ('map_' . ($index + 1));

                return $assetKey . '.jpg';
            },
            $maps,
            array_keys($maps)
        );

        return self::$mapLegacyFiles;
    }
}
