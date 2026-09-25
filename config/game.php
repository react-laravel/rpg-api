<?php

$maxCharacterLevel = 200;
$experienceFallbackMultiplier = 50;

/*
|--------------------------------------------------------------------------
| 经验值升级表(累计总经验阈值)
|--------------------------------------------------------------------------
| 每次升级需要经验 = 50 * 当前等级^2。
| 角色经验为累计值，所以到达某等级的阈值是之前每级升级需求之和：
|   experience_table[level] = sum_{i=1}^{level-1} (50 * i^2)
| 与前端 MAX_CHARACTER_LEVEL / 曲线保持一致，发布完整表到 max_character_level。
*/
$experienceTable = [1 => 0];
$cumulativeExperience = 0;
for ($level = 1; $level < $maxCharacterLevel; $level++) {
    $cumulativeExperience += $experienceFallbackMultiplier * ($level ** 2);
    $experienceTable[$level + 1] = $cumulativeExperience;
}

return [
    /*
    |--------------------------------------------------------------------------
    | 角色最高等级
    |--------------------------------------------------------------------------
    | 与前端 MAX_CHARACTER_LEVEL 对齐；升级循环在此封顶。
    */
    'max_character_level' => $maxCharacterLevel,

    /*
    |--------------------------------------------------------------------------
    | 经验值升级表(累计总经验阈值)
    |--------------------------------------------------------------------------
    | 每次升级需要经验 = 50 * 当前等级^2。
    | 角色经验为累计值，所以到达某等级的阈值是之前每级升级需求之和。
    */
    'experience_table' => $experienceTable,

    // 经验表未定义等级时的兜底：升级所需经验 = 50 * 当前等级^2
    'experience_fallback_multiplier' => $experienceFallbackMultiplier,

    // 每级属性点奖励
    'stat_points_per_level' => 1,

    // 每级技能点数
    'skill_points_per_level' => 1,

    /*
    |--------------------------------------------------------------------------
    | 角色基础属性(创建角色时的初始四维)
    |--------------------------------------------------------------------------
    */
    'character_base_stats' => [
        'strength' => 3,
        'dexterity' => 4,
        'vitality' => 3,
        'energy' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | 生命值计算(基础值 + 体力 * 系数)
    |--------------------------------------------------------------------------
    */
    'hp' => [
        'base' => 10,
        'vitality_multiplier' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | 法力值计算(基础值 + 能量 * 系数)
    |--------------------------------------------------------------------------
    */
    'mana' => [
        'base' => 20,
        // 最大法力 = base + 能量属性 × energy_multiplier
        'energy_multiplier' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | 难度倍率(普通/困难/高手/大师/痛苦 1-6)
    | 怪物生命、怪物伤害、金币与经验分别使用对应加成。
    |--------------------------------------------------------------------------
    */
    'difficulty_multipliers' => [
        0 => ['monster_hp' => 1.0, 'monster_damage' => 1.0, 'reward' => 1.0],      // 普通
        1 => ['monster_hp' => 1.6, 'monster_damage' => 1.4, 'reward' => 1.5],      // 困难
        2 => ['monster_hp' => 2.2, 'monster_damage' => 1.75, 'reward' => 2.0],     // 高手
        3 => ['monster_hp' => 3.0, 'monster_damage' => 2.15, 'reward' => 2.8],     // 大师
        4 => ['monster_hp' => 4.0, 'monster_damage' => 2.6, 'reward' => 3.8],      // 痛苦 1
        5 => ['monster_hp' => 5.3, 'monster_damage' => 3.1, 'reward' => 5.0],      // 痛苦 2
        6 => ['monster_hp' => 7.0, 'monster_damage' => 3.7, 'reward' => 6.5],      // 痛苦 3
        7 => ['monster_hp' => 9.2, 'monster_damage' => 4.4, 'reward' => 8.5],      // 痛苦 4
        8 => ['monster_hp' => 12.0, 'monster_damage' => 5.2, 'reward' => 11.0],    // 痛苦 5
        9 => ['monster_hp' => 15.5, 'monster_damage' => 6.1, 'reward' => 14.0],    // 痛苦 6
    ],

    /*
    |--------------------------------------------------------------------------
    | 怪物类型倍率(普通/精英/Boss，用于掉落计算)
    |--------------------------------------------------------------------------
    */
    'monster_type_multipliers' => [
        'normal' => 1,
        'elite' => 1.8,
        'boss' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | 怪物属性递进（按地图层，3 只怪一层）
    |--------------------------------------------------------------------------
    | HP   = round((hp_base + (layer-1) * hp_growth) * hp_type_multiplier[type])
    | 攻击 = round((layer-1) * attack_growth * attack_scale * attack_type_multiplier[type])
    | 防御 = round((defense_base + (layer-1) * defense_growth) * defense_scale * defense_type_multiplier[type])
    | 经验 = max(1, round(layer^2 * exp_type_multiplier[type] * experience_scale))
    | 类型：新手营地全普通；其余地图槽 2=精英；章节最后一张槽 2=Boss（精英/Boss 三项属性均按坦克原型计算）。
    | 槽 0/1 始终普通，避免精英/Boss 倍率让相邻图变成血墙。
    */
    'monster_progression' => [
        // Combat values use the same small-number scale as fixed equipment.
        'attack_scale' => 0.2,
        'defense_scale' => 0.35,
        'experience_scale' => 0.1,
        'attack_type_multiplier' => ['normal' => 1.0, 'elite' => 1.25, 'boss' => 1.5],
        'defense_type_multiplier' => ['normal' => 1.0, 'elite' => 1.1, 'boss' => 1.2],
        'archetypes' => [
            [
                'key' => 'boar',
                'name' => '野猪',
                'hp_base' => 3,
                'hp_growth' => 6,
                'defense_base' => 1,
                'defense_growth' => 1,
                'attack_growth' => 2,
            ],
            [
                'key' => 'deer',
                'name' => '鹿',
                'hp_base' => 2,
                'hp_growth' => 4,
                'defense_base' => 2,
                'defense_growth' => 2,
                'attack_growth' => 3,
            ],
            [
                'key' => 'rabbit',
                'name' => '兔子',
                'hp_base' => 1,
                'hp_growth' => 2,
                'defense_base' => 3,
                'defense_growth' => 3,
                'attack_growth' => 1,
            ],
        ],
        'hp_type_multiplier' => [
            'normal' => 1.0,
            'elite' => 1.5,
            'boss' => 2.5,
        ],
        'exp_type_multiplier' => [
            'normal' => 1.0,
            'elite' => 2.0,
            'boss' => 4.0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 装备槽位
    |--------------------------------------------------------------------------
    */
    'slots' => [
        'weapon',
        'helmet',
        'armor',
        'gloves',
        'boots',
        'belt',
        'ring',
        'amulet',
    ],

    // 装备宝石孔位上限
    'max_item_sockets' => 3,

    /*
    |--------------------------------------------------------------------------
    | 装备属性分类（按部位限制可生成的属性）
    |--------------------------------------------------------------------------
    | 防御部位（helmet/armor/gloves/boots/belt）：仅生成 defense 类属性
    | 攻击部位（weapon/ring/amulet）：仅生成 attack 类属性
    | all_stats 等通用属性不受此限制
    |--------------------------------------------------------------------------
    */
    'defense_stat_categories' => ['defense', 'max_hp', 'max_mana'],
    'offense_stat_categories' => ['attack', 'crit_rate', 'crit_damage', 'strength', 'dexterity', 'energy'],

    /*
    |--------------------------------------------------------------------------
    | 战斗属性计算(攻击/防御/暴击)
    |--------------------------------------------------------------------------
    | 基础攻击由 strength 字段决定（前端显示为“攻击力”）：
    | - 攻击 = strength × attack.multiplier。
    | - 普攻命中 = max(0, 攻击 − 防御×defense_reduction)。
    | - 技能命中 = max(0, 攻击 × 技能倍率 − 防御×defense_reduction)。技能 base_damage 为百分数，160 = 160%。
    | - 怪物反击 = max(攻击×5%, 攻击−防御×0.3)。大于 0 时四舍五入，不足 1 点记为 1。攻击为 0 不反击。
    | - 减速：反击先减半再取整，可以变成 0。冻结：该怪本拍不反击。
    | - 防御 = 体力×vitality_multiplier + 敏捷×dexterity_multiplier。
    | - 基础暴击 = 敏捷×dexterity_multiplier，总暴击率有 cap 上限。
    |--------------------------------------------------------------------------
    */
    'combat' => [
        // 怪物属性刷新间隔(秒)，定期从数据库重新读取怪物属性
        'monster_refresh_interval' => env('COMBAT_MONSTER_REFRESH_INTERVAL', 60),
        // 攻击：使用 strength 字段；前端显示为“攻击力”
        'attack' => [
            'stat' => 'strength',
            'multiplier' => 1,
        ],
        // 防御：体力与敏捷系数
        'defense' => [
            'vitality_multiplier' => 0.35,
            'dexterity_multiplier' => 0.2,
        ],
        // 暴击率：敏捷系数，每点敏捷增加 dexterity_multiplier(如 0.01 = 1%)；cap 为总暴击率上限。
        // 达到 cap 后，敏捷再堆也不会提高暴击率；若希望敏捷长期有用可提高 cap 或设为 1.0 取消上限。
        'crit_rate' => [
            'dexterity_multiplier' => 0.002,
            'cap' => 0.30,
        ],
        // 暴击伤害：基础倍率(1.5 = 150%)
        'crit_damage' => [
            'base' => 1.5,
        ],
        // 战斗计算补充配置
        'defense_reduction' => 0.5,
        'aoe_damage_multiplier' => 0.7,
        'monster_defense_reduction' => 0.3,
        'minimum_monster_damage_ratio' => 0.05,
        // 每次战斗推进后的资源恢复：HP = 体力 × 系数，MP = 能量 × 系数
        'hp_regen_per_vitality' => 0.25,
        'mp_regen_per_energy' => 0.5,
        // 刷怪类型概率：普通 95%，剩余 5% 在地图已有的精英/Boss 类型间均分
        'monster_spawn' => [
            'normal_chance' => 95,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 物品品质概率(掉落时的品质判定)
    |--------------------------------------------------------------------------
    | 从高到低依次判断，roll 值 0-100
    | 配置的概率之和应 <= 100，剩余为 common 品质
    */
    'item_quality_chances' => [
        'mythic' => 0.01,
        'legendary' => 0.1,
        'rare' => 1,
        'magic' => 18.89,
        // 剩余 80% 为 common
    ],

    /*
    |--------------------------------------------------------------------------
    | 铜币掉落配置
    |--------------------------------------------------------------------------
    | 怪物死亡时的铜币掉落：概率 10%，数量 = 层数 × per_layer
    */
    'copper_drop' => [
        // 掉落概率(0-1)，0.1 = 10%
        'chance' => 0.1,
        // 铜币数量 = 怪物层数 × per_layer（层数通常等于怪物 level）
        'per_layer' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | 装备掉落配置
    |--------------------------------------------------------------------------
    */
    'equipment_drop' => [
        // 掉落概率(0-1)，0.01 = 1%
        'chance' => 0.01,
    ],

    /*
    |--------------------------------------------------------------------------
    | 宝石掉落
    |--------------------------------------------------------------------------
    | 装备没掉出来时再判定。用已有宝石定义，不另造新宝石。
    */
    'gem_drop' => [
        'chance' => 0.05,
    ],

    /*
    |--------------------------------------------------------------------------
    | 战斗计算配置
    |--------------------------------------------------------------------------
    */
    // (已合并到 'combat')

    /*
    |--------------------------------------------------------------------------
    | 离线收益配置
    |--------------------------------------------------------------------------
    */
    'offline_rewards' => [
        // 最大离线时间(秒)，默认 24 小时
        'max_seconds' => 86400,
        // 每级每秒经验值
        'experience_per_level' => 0.02,
        // 每级每秒铜币系数
        'copper_per_level' => 0.2,
    ],

    /*
    |--------------------------------------------------------------------------
    | 测试模式概率加成
    |--------------------------------------------------------------------------
    | 当 GAME_TEST_MODE=true 或 APP_ENV=testing/sandbox 时启用
    | 各概率在原基础上乘以对应倍数
    */
    'test_mode' => [
        // 启用测试模式
        'enabled' => env('GAME_TEST_MODE', false),
        // 物品品质概率加成(值越大高品质装备概率越高)
        'quality_multiplier' => [
            'mythic' => 10, // 神话装备概率 ×10
            'legendary' => 10, // 传奇装备概率 ×10
            'rare' => 5, // 稀有装备概率 ×5
            'magic' => 2, // 魔法装备概率 ×2
        ],
        // 铜币掉落概率加成
        'copper_drop_chance_multiplier' => 10, // 掉落概率 ×10(原本 10%变成 100%)
        // 装备掉落概率加成
        'equipment_drop_chance_multiplier' => 10,
        // 宝石掉落概率加成
        'gem_drop_chance_multiplier' => 10,
        // 铜币掉落数量加成
        'copper_amount_multiplier' => 10,
        // 经验获取加成
        'experience_multiplier' => 10,
    ],
];
