<?php

use Database\Seeders\Game\Data\Skills\SkillTreeBuilder;

return SkillTreeBuilder::merge(
    SkillTreeBuilder::line('mage', 'basic', 'mage_fireball', 'fireball', '小火球', [
        'description' => '中耗单体火焰弹，无冷却，可每拍释放；伤害高于冰箭',
        'base_damage' => 16,
        'mana_cost' => 10,
        'cooldown' => 0,
        'icon_prompt' => 'RPG skill icon, fireball, flaming orb, magic projectile, wizard spell, detailed fantasy icon, square, dark background',
    ], '强化火球术', [
        'description' => '单体伤害 +30%',
        'effects' => ['damage_bonus' => 0.3],
    ], [
        'name' => '灼烧火球',
        'description' => '命中后附加灼烧，持续 3 次战斗推进',
        'effects' => ['burn_duration' => 3],
    ], [
        'name' => '炽热聚焦',
        'description' => '单体伤害再 +40%（与强化叠加）',
        'effects' => ['damage_bonus' => 0.4],
    ]),
    SkillTreeBuilder::line('mage', 'basic', 'mage_ice_arrow', 'ice-arrow', '冰箭', [
        'description' => '低耗单体冰伤，无冷却；伤害低于小火球，偏续航',
        'base_damage' => 8,
        'mana_cost' => 5,
        'cooldown' => 0,
        'icon_prompt' => 'RPG skill icon, ice arrow, frost projectile, blue crystal shard, mage spell, detailed game icon, square, dark background',
    ], '强化冰箭', [
        'description' => '20% 概率减速目标 2 次推进（反击伤害减半）',
        'effects' => ['slow_chance' => 0.2, 'slow_duration' => 2],
    ], [
        'name' => '碎冰箭',
        'description' => '对已减速目标额外 +30% 伤害',
        'effects' => ['slowed_damage_bonus' => 0.3],
    ], [
        'name' => '穿透冰箭',
        'description' => '穿透最多 2 个目标，后续目标伤害 -20%',
        'effects' => ['pierce_count' => 2, 'pierce_falloff' => 0.2],
    ]),
    SkillTreeBuilder::line('mage', 'core', 'mage_frost_nova', 'frost-nova', '冰霜新星', [
        'description' => '全体冰伤，冷却 4 次；适合 2 只及以上怪物',
        'base_damage' => 45,
        'mana_cost' => 18,
        'cooldown' => 4,
        'target_type' => 'all',
        'icon_prompt' => 'RPG skill icon, frost nova, ice explosion ring, frozen shards, mage AOE, detailed fantasy icon, square, dark background',
    ], '强化冰霜新星', [
        'description' => '冻结目标 1 次推进（BOSS 同样冻结 1 次）',
        'effects' => ['freeze_duration' => 1, 'boss_freeze_duration' => 1],
    ], [
        'name' => '寒冰领域',
        'description' => '命中后地面减速 4 次推进（群体控制）',
        'effects' => ['ground_slow_duration' => 4],
        'target_type' => 'all',
    ], [
        'name' => '冰霜尖刺',
        'description' => '主目标 300% 伤害，其余目标仍受全体伤害',
        'effects' => ['single_target_ratio' => 3.0],
    ]),
    SkillTreeBuilder::line('mage', 'core', 'mage_lightning', 'lightning', '雷击', [
        'description' => '高效单体雷伤，冷却 3 次；伤害介于小火球与奥术飞弹之间',
        'base_damage' => 34,
        'mana_cost' => 14,
        'cooldown' => 3,
        'icon_prompt' => 'RPG skill icon, lightning bolt, electric strike, mage spell, bright yellow energy, detailed game icon, square, dark background',
    ], '强化雷击', [
        'description' => '施放时暴击率 +15%',
        'effects' => ['crit_bonus' => 0.15],
    ], [
        'name' => '过载雷击',
        'description' => '暴击时连锁 1 个目标，造成 50% 伤害',
        'effects' => ['chain_on_crit' => true, 'chain_ratio' => 0.5],
    ], [
        'name' => '精准雷击',
        'description' => '非暴击伤害 +25%，冷却 -1 次',
        'effects' => ['non_crit_bonus' => 0.25, 'cooldown_reduction' => 1],
    ]),
    SkillTreeBuilder::line('mage', 'core', 'mage_chain_lightning', 'chain-lightning', '连锁闪电', [
        'description' => '弹跳多目标雷伤，默认弹 3 次，冷却 5 次；2 只及以上怪物时优先于单体基础法术',
        'base_damage' => 70,
        'mana_cost' => 24,
        'cooldown' => 5,
        'target_type' => 'all',
        'icon_prompt' => 'RPG skill icon, chain lightning, electric arcs between targets, mage spell, dynamic energy, square, dark background',
    ], '强化连锁闪电', [
        'description' => '弹跳次数 +1（共 4 次）',
        'effects' => ['bounce_count' => 4],
    ], [
        'name' => '雷暴扩散',
        'description' => '弹跳时后续目标仍造成 70% 伤害（群体覆盖）',
        'effects' => ['bounce_radius_growth' => true, 'bounce_ratio' => 0.7],
        'target_type' => 'all',
    ], [
        'name' => '集中导能',
        'description' => '仅弹 2 次，但每次 120% 伤害',
        'effects' => ['bounce_count' => 2, 'bounce_ratio' => 1.2],
    ]),
    SkillTreeBuilder::line('mage', 'defensive', 'mage_shield', 'shield', '魔法护盾', [
        'description' => '获得吸收 100 点伤害的护盾，持续 8 次推进（防御技，自动战斗不优先选用）',
        'base_damage' => 0,
        'mana_cost' => 20,
        'cooldown' => 15,
        'effects' => ['shield_amount' => 100, 'duration' => 8],
        'icon_prompt' => 'RPG skill icon, magic shield, arcane barrier, glowing blue dome, mage defensive spell, square, dark background',
    ], '强化护盾', [
        'description' => '吸收提升至 150，破盾时反弹 30% 已吸收伤害',
        'effects' => ['shield_amount' => 150, 'reflect_on_break' => 0.3],
    ], [
        'name' => '奥术护壳',
        'description' => '护盾存在期间魔伤 +10%',
        'effects' => ['spell_damage_bonus' => 0.1],
    ], [
        'name' => '能量转换',
        'description' => '破盾时回复 15% 最大法力',
        'effects' => ['mana_restore_on_break' => 0.15],
    ]),
    SkillTreeBuilder::line('mage', 'special', 'mage_meteor', 'meteor', '陨石术', [
        'description' => '高耗全体火伤，冷却 8 次；适合清场',
        'base_damage' => 150,
        'mana_cost' => 42,
        'cooldown' => 8,
        'target_type' => 'all',
        'icon_prompt' => 'RPG skill icon, meteor strike, falling fire rock, massive explosion, mage ultimate spell, square, dark background',
    ], '强化陨石', [
        'description' => '落地后附加灼烧，持续 4 次推进',
        'effects' => ['burn_duration' => 4],
    ], [
        'name' => '陨星雨',
        'description' => '额外陨石视觉与多段落点表现（群体）',
        'effects' => ['extra_meteors' => 2],
        'target_type' => 'all',
    ], [
        'name' => '精准陨石',
        'description' => '主目标 350% 伤害，其余目标仍受全体伤害',
        'effects' => ['single_target_ratio' => 3.5],
    ]),
    SkillTreeBuilder::line('mage', 'special', 'mage_arcane_missile', 'arcane-missile', '奥术飞弹', [
        'description' => '高效单体奥术伤害，冷却 6 次；一次结算，多弹特效',
        'base_damage' => 85,
        'mana_cost' => 28,
        'cooldown' => 6,
        'effects' => [],
        'icon_prompt' => 'RPG skill icon, arcane missiles, purple magic bolts, channeled spell, mage fantasy icon, square, dark background',
    ], '强化奥术飞弹', [
        'description' => '奥术伤害 +20%',
        'effects' => ['damage_bonus' => 0.2],
    ], [
        'name' => '奥术分裂',
        'description' => '弹射最多 2 个额外目标，后续目标 -30% 伤害',
        'effects' => ['pierce_count' => 3, 'pierce_falloff' => 0.3],
        'target_type' => 'all',
    ], [
        'name' => '奥术穿透',
        'description' => '单体伤害再 +25%',
        'effects' => ['damage_bonus' => 0.25],
    ]),
    SkillTreeBuilder::line('mage', 'ultimate', 'mage_cataclysm', 'element-cataclysm', '元素灾变', [
        'description' => '终极全体爆发，冷却 30 次；适合高血量多目标',
        'base_damage' => 240,
        'mana_cost' => 60,
        'cooldown' => 30,
        'target_type' => 'all',
        'icon_prompt' => 'RPG skill icon, elemental cataclysm, fire ice lightning fusion, epic mage ultimate, square, dark background',
    ], '强化元素灾变', [
        'description' => '命中附加灼烧、冻结与感电（减速）',
        'effects' => ['apply_burn' => true, 'apply_freeze' => true, 'apply_shock' => true, 'ailment_duration' => 3],
    ], [
        'name' => '灾变延宕',
        'description' => '异常持续延长至 6 次推进',
        'effects' => ['ailment_duration' => 6],
        'target_type' => 'all',
    ], [
        'name' => '灾变聚焦',
        'description' => '主目标 450% 伤害，其余目标仍受全体伤害',
        'effects' => ['single_target_ratio' => 4.5],
    ]),
    [SkillTreeBuilder::keyPassive('mage', 'mage_key', 'mage-key', '奥术共鸣', [
        'description' => '每学习 1 条已点满的技能线（含专精），最大法力 +5%、魔伤 +2%（最多 6 线）',
        'effects' => ['mana_per_line' => 0.05, 'spell_damage_per_line' => 0.02, 'max_lines' => 6],
        'icon_prompt' => 'RPG skill icon, arcane resonance, mage key passive, glowing runes, magical harmony, square, dark background',
    ])],
);
