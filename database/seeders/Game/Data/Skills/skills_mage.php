<?php

use Database\Seeders\Game\Data\Skills\SkillTreeBuilder;

return SkillTreeBuilder::merge(
    SkillTreeBuilder::line('mage', 'basic', 'mage_fireball', 'fireball', '小火球', [
        'description' => '中耗单体火焰弹，伤害高于冰箭；强化后保持单体爆发定位',
        'base_damage' => 16,
        'mana_cost' => 10,
        'cooldown' => 1,
        'icon_prompt' => 'RPG skill icon, fireball, flaming orb, magic projectile, wizard spell, detailed fantasy icon, square, dark background',
    ], '强化火球术', [
        'description' => '单体伤害 +30%',
        'effects' => ['damage_bonus' => 0.3],
    ], [
        'name' => '灼烧火球',
        'description' => '命中主目标后附加 3 秒灼烧（单体持续）',
        'effects' => ['burn_duration' => 3],
    ], [
        'name' => '炽热聚焦',
        'description' => '单体伤害 +40%（单体爆发）',
        'effects' => ['damage_bonus' => 0.4],
    ]),
    SkillTreeBuilder::line('mage', 'basic', 'mage_ice_arrow', 'ice-arrow', '冰箭', [
        'description' => '低耗单体冰伤，偏续航/控制，伤害低于小火球',
        'base_damage' => 8,
        'mana_cost' => 5,
        'cooldown' => 0,
        'icon_prompt' => 'RPG skill icon, ice arrow, frost projectile, blue crystal shard, mage spell, detailed game icon, square, dark background',
    ], '强化冰箭', [
        'description' => '20% 减速 2 秒',
        'effects' => ['slow_chance' => 0.2, 'slow_duration' => 2],
    ], [
        'name' => '碎冰箭',
        'description' => '减速目标额外 +30% 伤害（控制）',
        'effects' => ['slowed_damage_bonus' => 0.3],
    ], [
        'name' => '穿透冰箭',
        'description' => '穿透 2 个目标，后续 -20% 伤害（群体穿透）',
        'effects' => ['pierce_count' => 2, 'pierce_falloff' => 0.2],
    ]),
    SkillTreeBuilder::line('mage', 'core', 'mage_frost_nova', 'frost-nova', '冰霜新星', [
        'description' => '中耗全体冰伤 + 控制，适合 2+ 怪物',
        'base_damage' => 45,
        'mana_cost' => 18,
        'cooldown' => 4,
        'target_type' => 'all',
        'icon_prompt' => 'RPG skill icon, frost nova, ice explosion ring, frozen shards, mage AOE, detailed fantasy icon, square, dark background',
    ], '强化冰霜新星', [
        'description' => '冻结 1 秒（BOSS 0.5 秒）',
        'effects' => ['freeze_duration' => 1, 'boss_freeze_duration' => 0.5],
    ], [
        'name' => '寒冰领域',
        'description' => '冻结后地面减速 4 秒（群体控制）',
        'effects' => ['ground_slow_duration' => 4],
        'target_type' => 'all',
    ], [
        'name' => '冰霜尖刺',
        'description' => '冻结改为单体 300% 伤害（单体爆发）',
        'effects' => ['single_target_ratio' => 3.0],
    ]),
    SkillTreeBuilder::line('mage', 'core', 'mage_lightning', 'lightning', '雷击', [
        'description' => '高效单体雷伤，介于小火球和奥术飞弹之间',
        'base_damage' => 34,
        'mana_cost' => 14,
        'cooldown' => 3,
        'icon_prompt' => 'RPG skill icon, lightning bolt, electric strike, mage spell, bright yellow energy, detailed game icon, square, dark background',
    ], '强化雷击', [
        'description' => '暴击率 +15%',
        'effects' => ['crit_bonus' => 0.15],
    ], [
        'name' => '过载雷击',
        'description' => '暴击时连锁 1 个 50%（群体连锁）',
        'effects' => ['chain_on_crit' => true, 'chain_ratio' => 0.5],
    ], [
        'name' => '精准雷击',
        'description' => '非暴击伤害 +25%，CD -1（稳定单体）',
        'effects' => ['non_crit_bonus' => 0.25, 'cooldown_reduction' => 1],
    ]),
    SkillTreeBuilder::line('mage', 'core', 'mage_chain_lightning', 'chain-lightning', '连锁闪电', [
        'description' => '连锁多目标雷伤，2+ 怪物时优先级高于单体基础法术',
        'base_damage' => 70,
        'mana_cost' => 24,
        'cooldown' => 5,
        'target_type' => 'all',
        'icon_prompt' => 'RPG skill icon, chain lightning, electric arcs between targets, mage spell, dynamic energy, square, dark background',
    ], '强化连锁闪电', [
        'description' => '弹跳 +1 次',
        'effects' => ['bounce_count' => 4],
    ], [
        'name' => '雷暴扩散',
        'description' => '每次弹跳范围扩大（群体覆盖）',
        'effects' => ['bounce_radius_growth' => true],
        'target_type' => 'all',
    ], [
        'name' => '集中导能',
        'description' => '仅弹 2 次但每次 120%（高伤单体链）',
        'effects' => ['bounce_count' => 2, 'bounce_ratio' => 1.2],
    ]),
    SkillTreeBuilder::line('mage', 'defensive', 'mage_shield', 'shield', '魔法护盾', [
        'description' => '吸收 100 点伤害，持续 8 秒（防御技能，不作为伤害技能优先选择）',
        'base_damage' => 0,
        'mana_cost' => 20,
        'cooldown' => 15,
        'effects' => ['shield_amount' => 100, 'duration' => 8],
        'icon_prompt' => 'RPG skill icon, magic shield, arcane barrier, glowing blue dome, mage defensive spell, square, dark background',
    ], '强化护盾', [
        'description' => '吸收 150，破盾时反弹 30%',
        'effects' => ['shield_amount' => 150, 'reflect_on_break' => 0.3],
    ], [
        'name' => '奥术护壳',
        'description' => '护盾存在时魔伤 +10%（输出向）',
        'effects' => ['spell_damage_bonus' => 0.1],
    ], [
        'name' => '能量转换',
        'description' => '破盾回复 15% 法力（生存/续航）',
        'effects' => ['mana_restore_on_break' => 0.15],
    ]),
    SkillTreeBuilder::line('mage', 'special', 'mage_meteor', 'meteor', '陨石术', [
        'description' => '高耗全体火伤，大波次清场技能',
        'base_damage' => 150,
        'mana_cost' => 42,
        'cooldown' => 8,
        'target_type' => 'all',
        'icon_prompt' => 'RPG skill icon, meteor strike, falling fire rock, massive explosion, mage ultimate spell, square, dark background',
    ], '强化陨石', [
        'description' => '落地燃烧 4 秒',
        'effects' => ['burn_duration' => 4],
    ], [
        'name' => '陨星雨',
        'description' => '额外 2 颗小陨石随机落点（群体随机）',
        'effects' => ['extra_meteors' => 2],
        'target_type' => 'all',
    ], [
        'name' => '精准陨石',
        'description' => '单体主目标 350%（单体聚焦）',
        'effects' => ['single_target_ratio' => 3.5],
    ]),
    SkillTreeBuilder::line('mage', 'special', 'mage_arcane_missile', 'arcane-missile', '奥术飞弹', [
        'description' => '高效单体持续输出，适合单个高血量目标',
        'base_damage' => 85,
        'mana_cost' => 28,
        'cooldown' => 6,
        'effects' => ['channel_duration' => 3, 'missiles_per_second' => 3, 'missile_ratio' => 0.4],
        'icon_prompt' => 'RPG skill icon, arcane missiles, purple magic bolts, channeled spell, mage fantasy icon, square, dark background',
    ], '强化奥术飞弹', [
        'description' => '引导时可移动',
        'effects' => ['channel_mobile' => true],
    ], [
        'name' => '奥术分裂',
        'description' => '每发分裂为 2 发 -30% 伤（群体）',
        'effects' => ['split_count' => 2, 'split_penalty' => 0.3],
        'target_type' => 'all',
    ], [
        'name' => '奥术穿透',
        'description' => '对同一目标叠加 +10%/发（单体持续）',
        'effects' => ['stack_bonus_per_hit' => 0.1],
    ]),
    SkillTreeBuilder::line('mage', 'ultimate', 'mage_cataclysm', 'element-cataclysm', '元素灾变', [
        'description' => '终极全体爆发，长冷却，适合高血量多目标',
        'base_damage' => 240,
        'mana_cost' => 60,
        'cooldown' => 30,
        'target_type' => 'all',
        'icon_prompt' => 'RPG skill icon, elemental cataclysm, fire ice lightning fusion, epic mage ultimate, square, dark background',
    ], '强化元素灾变', [
        'description' => '每段附加对应异常（燃烧/冻结/感电）',
        'effects' => ['apply_burn' => true, 'apply_freeze' => true, 'apply_shock' => true],
    ], [
        'name' => '灾变延宕',
        'description' => '异常持续 6 秒（持续/群体）',
        'effects' => ['ailment_duration' => 6],
        'target_type' => 'all',
    ], [
        'name' => '灾变聚焦',
        'description' => '三段合并为单体 450%（单体终极）',
        'effects' => ['single_target_ratio' => 4.5],
    ]),
    [SkillTreeBuilder::keyPassive('mage', 'mage_key', 'mage-key', '奥术共鸣', [
        'description' => '每学习 1 条已点满的技能线，最大法力 +5%、魔伤 +2%（最多 6 线）',
        'effects' => ['mana_per_line' => 0.05, 'spell_damage_per_line' => 0.02, 'max_lines' => 6],
        'icon_prompt' => 'RPG skill icon, arcane resonance, mage key passive, glowing runes, magical harmony, square, dark background',
    ])],
);
