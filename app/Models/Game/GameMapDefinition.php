<?php

namespace App\Models\Game;

use App\Support\Game\RpgAssetIconNormalizer;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property array<int, int>|null $monster_ids
 * @property string|null $background
 */
class GameMapDefinition extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'act',
        'monster_ids',
        'background',
        'icon_prompt',
        'description',
        'is_active',
    ];

    protected $hidden = [
        'min_level',
        'max_level',
    ];

    protected $casts = [
        'monster_ids' => 'array',
        'is_active' => 'boolean',
        'act' => 'integer',
        'min_level' => 'integer',
        'max_level' => 'integer',
    ];

    protected function background(): Attribute
    {
        return Attribute::get(
            fn (?string $value): ?string => RpgAssetIconNormalizer::normalizeMapBackground($value)
        );
    }

    /**
     * 获取地图进度记录
     */
    public function characterMaps(): HasMany
    {
        return $this->hasMany(GameCharacterMap::class, 'map_id');
    }

    /**
     * 获取地图中的怪物列表
     *
     * @return array<int, GameMonsterDefinition>
     */
    public function getMonsters(): array
    {
        if ($this->relationLoaded('preloadedMonsters')) {
            /** @var EloquentCollection<int, GameMonsterDefinition> $monsters */
            $monsters = $this->getRelation('preloadedMonsters');

            return $monsters->all();
        }

        $ids = self::normalizeMonsterIds($this->monster_ids);
        if ($ids === []) {
            return [];
        }

        return GameMonsterDefinition::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->get()
            ->all();
    }

    /**
     * 批量预加载多张地图的怪物，避免 N+1 查询。
     *
     * @param  Collection<int, self>|array<int, self>  $maps
     */
    public static function preloadMonsters(Collection|array $maps): void
    {
        $maps = collect($maps);
        if ($maps->isEmpty()) {
            return;
        }

        $allIds = $maps
            ->flatMap(fn (self $map): array => self::normalizeMonsterIds($map->monster_ids))
            ->unique()
            ->values()
            ->all();

        if ($allIds === []) {
            $maps->each(fn (self $map) => $map->setRelation('preloadedMonsters', new EloquentCollection));

            return;
        }

        $monstersById = GameMonsterDefinition::query()
            ->whereIn('id', $allIds)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $maps->each(function (self $map) use ($monstersById): void {
            $monsters = collect(self::normalizeMonsterIds($map->monster_ids))
                ->map(fn (int $id): ?GameMonsterDefinition => $monstersById->get($id))
                ->filter()
                ->values();

            $map->setRelation('preloadedMonsters', $monsters);
        });
    }

    /**
     * @return array<int, int>
     */
    private static function normalizeMonsterIds(?array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('intval', array_values($ids)),
            fn (int $id): bool => $id > 0
        )));
    }

    /**
     * 检查角色等级是否可以进入(无等级限制)
     */
    public function canEnter(int $level): bool
    {
        return true;
    }

    /**
     * 获取推荐等级描述(无等级限制)
     */
    public function getLevelRangeText(): string
    {
        return '无等级限制';
    }
}
