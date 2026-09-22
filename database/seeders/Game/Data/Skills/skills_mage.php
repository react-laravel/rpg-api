<?php

use Database\Seeders\Game\Data\Skills\SkillTreeBuilder;

return SkillTreeBuilder::merge(
    SkillTreeBuilder::line('mage', 'basic', 'mage_fireball', 'fireball', '小火球', [
        'description' => '唯一的无冷却输出。造成攻击力 160% 的火焰伤害，每拍都能放',
        'base_damage' => 160,
        'mana_cost' => 8,
        'cooldown' => 0,
        'icon_prompt' => 'RPG skill icon, fireball, flaming orb, magic projectile, wizard spell, detailed fantasy icon, square, dark background',
    ], '强化火球术', [
        'description' => '火球伤害 +30%（变为攻击力 208%）',
        'effects' => ['damage_bonus' => 0.3],
    ], [
        'name' => '灼烧火球',
        'description' => '命中后灼烧 3 次推进，每拍再掉一层火伤',
        'effects' => ['burn_duration' => 3],
    ], [
        'name' => '炽热聚焦',
        'description' => '火球伤害再 +40%（与强化叠加）',
        'effects' => ['damage_bonus' => 0.4],
    ]),
    SkillTreeBuilder::line('mage', 'basic', 'mage_ice_arrow', 'ice-arrow', '冰箭', [
        'description' => '单体控制箭。造成攻击力 130% 伤害。会反击、且还没被减速或冻结的怪物优先吃到这一发，反击减半，持续 2 次推进',
        'base_damage' => 130,
        'mana_cost' => 6,
        'cooldown' => 2,
        'effects' => ['slow_chance' => 1, 'slow_duration' => 2],
        'icon_prompt' => 'RPG skill icon, ice arrow, frost projectile, blue crystal shard, mage spell, detailed game icon, square, dark background',
    ], '强化冰箭', [
        'description' => '减速改为冻结 1 次推进：该怪本拍完全不能反击',
        'effects' => ['freeze_duration' => 1, 'boss_freeze_duration' => 1],
    ], [
        'name' => '碎冰箭',
        'description' => '对已减速或冻结的目标额外 +40% 伤害',
        'effects' => ['slowed_damage_bonus' => 0.4],
    ], [
        'name' => '穿透冰箭',
        'description' => '穿透最多 2 个目标，把减速带给后排',
        'effects' => ['pierce_count' => 2, 'pierce_falloff' => 0.2],
    ]),
    SkillTreeBuilder::line('mage', 'core', 'mage_frost_nova', 'frost-nova', '冰霜新星', [
        'description' => '群体控制。造成攻击力 150% 的全体冰伤，并冻结 1 次推进（被冻住的怪本拍不反击）',
        'base_damage' => 150,
        'mana_cost' => 16,
        'cooldown' => 5,
        'target_type' => 'all',
        'effects' => ['freeze_duration' => 1, 'boss_freeze_duration' => 1],
        'icon_prompt' => 'RPG skill icon, frost nova, ice explosion ring, frozen shards, mage AOE, detailed fantasy icon, square, dark background',
    ], '强化冰霜新星', [
        'description' => '冻结延长至 2 次推进',
        'effects' => ['freeze_duration' => 2, 'boss_freeze_duration' => 1],
    ], [
        'name' => '寒冰领域',
        'description' => '解冻后仍减速 4 次推进（反击继续减半）',
        'effects' => ['ground_slow_duration' => 4],
        'target_type' => 'all',
    ], [
        'name' => '冰霜尖刺',
        'description' => '主目标 300% 伤害，其余目标仍受全体冻结',
        'effects' => ['single_target_ratio' => 3.0],
    ]),
    SkillTreeBuilder::line('mage', 'core', 'mage_lightning', 'lightning', '雷击', [
        'description' => '短冷却单体爆发。造成攻击力 220% 的雷电伤害，冷却 3 次',
        'base_damage' => 220,
        'mana_cost' => 12,
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
        'description' => '弹跳清杂，不是全体陨石。造成攻击力 180% 伤害，弹跳 3 个目标，冷却 5 次',
        'base_damage' => 180,
        'mana_cost' => 20,
        'cooldown' => 5,
        'target_type' => 'single',
        'icon_prompt' => 'RPG skill icon, chain lightning, electric arcs between targets, mage spell, dynamic energy, square, dark background',
    ], '强化连锁闪电', [
        'description' => '弹跳次数 +1（共 4 个目标）',
        'effects' => ['bounce_count' => 4],
    ], [
        'name' => '雷暴扩散',
        'description' => '后续弹跳仍造成 80% 伤害',
        'effects' => ['bounce_ratio' => 0.8],
    ], [
        'name' => '集中导能',
        'description' => '只弹 2 个目标，但每次 130% 伤害',
        'effects' => ['bounce_count' => 2, 'bounce_ratio' => 1.3],
    ]),
    SkillTreeBuilder::line('mage', 'defensive', 'mage_shield', 'shield', '魔法护盾', [
        'description' => '获得吸收 100 点伤害的护盾，持续 8 次推进（保命技）',
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
        'description' => '真正的全体砸场。造成攻击力 320% 的火伤打到每一只怪，冷却 8 次',
        'base_damage' => 320,
        'mana_cost' => 36,
        'cooldown' => 8,
        'target_type' => 'all',
        'icon_prompt' => 'RPG skill icon, meteor strike, falling fire rock, massive explosion, mage ultimate spell, square, dark background',
    ], '强化陨石', [
        'description' => '落地后灼烧 4 次推进',
        'effects' => ['burn_duration' => 4],
    ], [
        'name' => '陨星雨',
        'description' => '额外陨石视觉，砸得更热闹',
        'effects' => ['extra_meteors' => 2],
        'target_type' => 'all',
    ], [
        'name' => '精准陨石',
        'description' => '血最低的那只吃 350% 伤害，其余仍吃全体',
        'effects' => ['single_target_ratio' => 3.5],
    ]),
    SkillTreeBuilder::line('mage', 'special', 'mage_arcane_missile', 'arcane-missile', '奥术飞弹', [
        'description' => '单体重击。造成攻击力 260% 的奥术伤害，冷却 6 次，用来点杀一只',
        'base_damage' => 260,
        'mana_cost' => 24,
        'cooldown' => 6,
        'effects' => [],
        'icon_prompt' => 'RPG skill icon, arcane missiles, purple magic bolts, channeled spell, mage fantasy icon, square, dark background',
    ], '强化奥术飞弹', [
        'description' => '奥术伤害 +20%',
        'effects' => ['damage_bonus' => 0.2],
    ], [
        'name' => '奥术分裂',
        'description' => '弹射最多 2 个额外目标，后续 -30% 伤害',
        'effects' => ['pierce_count' => 3, 'pierce_falloff' => 0.3],
        'target_type' => 'all',
    ], [
        'name' => '奥术穿透',
        'description' => '单体伤害再 +25%',
        'effects' => ['damage_bonus' => 0.25],
    ]),
    SkillTreeBuilder::line('mage', 'ultimate', 'mage_cataclysm', 'element-cataclysm', '元素灾变', [
        'description' => '终极演出。造成攻击力 450% 的全体伤害，冷却 30 次',
        'base_damage' => 450,
        'mana_cost' => 55,
        'cooldown' => 30,
        'target_type' => 'all',
        'icon_prompt' => 'RPG skill icon, elemental cataclysm, fire ice lightning fusion, epic mage ultimate, square, dark background',
    ], '强化元素灾变', [
        'description' => '命中附加灼烧、冻结与减速，持续 3 次推进',
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
