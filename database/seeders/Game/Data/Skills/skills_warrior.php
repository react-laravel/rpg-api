<?php

use Database\Seeders\Game\Data\Skills\SkillTreeBuilder;

return SkillTreeBuilder::merge(
    // Basic ① 破甲斩
    SkillTreeBuilder::line('warrior', 'basic', 'warrior_slash', 'slash', '破甲斩', [
        'description' => '单体 100% 物理伤害',
        'mana_cost' => 0,
        'cooldown' => 0,
        'icon_prompt' => 'RPG skill icon, heavy strike, sword slash motion blur, warrior attack, metallic gleam, impact lines, detailed game UI icon, square, dark background',
    ], '强化破甲斩', [
        'description' => '附加流血 3 秒，每秒 20% 攻击伤害，流血可暴击',
        'effects' => ['bleed_duration' => 3, 'bleed_ratio' => 0.2, 'bleed_can_crit' => true],
        'mana_cost' => 5,
    ], [
        'name' => '顺势猛击',
        'description' => '对生命>70% 敌人额外 +50% 伤害（单体爆发）',
        'effects' => ['high_hp_bonus' => 0.5, 'high_hp_threshold' => 0.7],
        'mana_cost' => 8,
    ], [
        'name' => '横扫余波',
        'description' => '30% 伤害溅射至相邻目标（小范围群体）',
        'effects' => ['splash_ratio' => 0.3],
        'target_type' => 'all',
        'mana_cost' => 8,
    ]),
    // Basic ② 盾击
    SkillTreeBuilder::line('warrior', 'basic', 'warrior_shield_bash', 'shield-bash', '盾击', [
        'description' => '格挡下 3 秒内下次受击反击 80% 伤害',
        'effects' => ['block_window' => 3, 'counter_ratio' => 0.8],
        'mana_cost' => 5,
        'cooldown' => 4,
        'icon_prompt' => 'RPG skill icon, shield bash, warrior block counter, metallic shield impact, fantasy combat icon, square, dark background',
    ], '强化盾击', [
        'description' => '反击附带 1 秒眩晕',
        'effects' => ['stun_duration' => 1],
    ], [
        'name' => '震荡盾击',
        'description' => '反击变为小范围冲击波（控制+群体）',
        'effects' => ['aoe_counter' => true],
        'target_type' => 'all',
    ], [
        'name' => '铁壁反击',
        'description' => '反击伤害 +100%，仅单体',
        'effects' => ['counter_bonus' => 1.0],
    ]),
    // Core ① 冲锋
    SkillTreeBuilder::line('warrior', 'core', 'warrior_charge', 'charge', '冲锋', [
        'description' => '冲向敌人造成 120% 伤害',
        'mana_cost' => 10,
        'cooldown' => 3,
        'icon_prompt' => 'RPG skill icon, shield bash charge, armored warrior rushing forward, impact sparks, motion streaks, fantasy combat icon, square, dark background',
    ], '强化冲锋', [
        'description' => '冲锋路径上敌人受到 50% 伤害',
        'effects' => ['path_damage_ratio' => 0.5],
    ], [
        'name' => '横扫冲锋',
        'description' => '到达时周围 AOE 80% 伤害（群体）',
        'effects' => ['arrival_aoe_ratio' => 0.8],
        'target_type' => 'all',
    ], [
        'name' => '贯穿冲锋',
        'description' => '对直线首个目标 3 段 60% 伤害（单体多段）',
        'effects' => ['hit_count' => 3, 'hit_ratio' => 0.6],
    ]),
    // Core ② 旋风斩
    SkillTreeBuilder::line('warrior', 'core', 'warrior_whirlwind', 'whirlwind', '旋风斩', [
        'description' => '旋转攻击周围所有敌人 90% 伤害',
        'mana_cost' => 25,
        'cooldown' => 6,
        'target_type' => 'all',
        'icon_prompt' => 'RPG skill icon, whirlwind slash, spinning sword arc, AOE attack, dynamic motion trail, warrior skill, detailed game art, square, dark background',
    ], '强化旋风斩', [
        'description' => '施放后 2 秒内移速 +20%，可边跑边打',
        'effects' => ['move_speed_bonus' => 0.2, 'move_duration' => 2],
    ], [
        'name' => '血刃风暴',
        'description' => '每击回复 2% 最大生命（持续/生存）',
        'effects' => ['lifesteal_per_hit' => 0.02],
    ], [
        'name' => '钢铁旋风',
        'description' => 'CD 降至 4，伤害 +30% 但无吸血（爆发/短 CD）',
        'effects' => ['cooldown_override' => 4, 'damage_bonus' => 0.3, 'no_lifesteal' => true],
    ]),
    // Core ③ 战吼
    SkillTreeBuilder::line('warrior', 'core', 'warrior_roar', 'battle-roar', '战吼', [
        'description' => '6 秒内攻击 +15',
        'mana_cost' => 15,
        'cooldown' => 10,
        'effects' => ['duration' => 6, 'buff_attack' => 15],
        'icon_prompt' => 'RPG skill icon, battle roar, war cry sound waves, warrior buff, radiating energy, fierce aura, detailed fantasy icon, square frame, dark background',
    ], '强化战吼', [
        'description' => '持续时间 8 秒，法力消耗降低',
        'effects' => ['duration' => 8, 'mana_reduction' => 0.2],
    ], [
        'name' => '破胆战吼',
        'description' => '敌人攻防 -10%（减益控制）',
        'effects' => ['enemy_debuff_attack' => 10, 'enemy_debuff_defense' => 10],
    ], [
        'name' => '战意昂扬',
        'description' => '自身暴击率 +8%（爆发输出）',
        'effects' => ['crit_bonus' => 0.08],
    ]),
    // Defensive 铁壁姿态
    SkillTreeBuilder::line('warrior', 'defensive', 'warrior_iron_wall', 'iron-wall', '铁壁姿态', [
        'description' => '开启后 8 秒内减伤 25%',
        'mana_cost' => 10,
        'cooldown' => 15,
        'effects' => ['duration' => 8, 'damage_reduction' => 0.25],
        'icon_prompt' => 'RPG skill icon, iron wall shield, defense barrier, metallic texture, sturdy design, passive skill, game icon with depth, square, dark background',
    ], '强化铁壁', [
        'description' => '减伤 30%，期间反伤 15%',
        'effects' => ['damage_reduction' => 0.3, 'thorns_ratio' => 0.15],
    ], [
        'name' => '壁垒共享',
        'description' => '减伤效果减半但延长 12 秒（长持续）',
        'effects' => ['damage_reduction' => 0.125, 'duration' => 12],
    ], [
        'name' => '孤注一掷',
        'description' => '生命<40% 时减伤 45%（低血生存）',
        'effects' => ['low_hp_reduction' => 0.45, 'low_hp_threshold' => 0.4],
    ]),
    // Special ① 斩杀
    SkillTreeBuilder::line('warrior', 'special', 'warrior_execute', 'execute', '斩杀', [
        'description' => '生命<30% 敌人 200% 伤害',
        'mana_cost' => 20,
        'cooldown' => 8,
        'effects' => ['execute_threshold' => 0.3, 'execute_ratio' => 2.0],
        'icon_prompt' => 'RPG skill icon, execute finisher, low HP target, dramatic sword strike, crimson glow, warrior ultimate move, square, dark background',
    ], '强化斩杀', [
        'description' => '斩杀阈值提升至 40%',
        'effects' => ['execute_threshold' => 0.4],
    ], [
        'name' => '断头台',
        'description' => '<20% 直接斩杀（BOSS 5%）（单体收割）',
        'effects' => ['instant_kill_threshold' => 0.2, 'boss_kill_threshold' => 0.05],
    ], [
        'name' => '恐吓斩',
        'description' => '命中全体敌人 60% 伤 + 恐惧 1 秒（群体控制）',
        'effects' => ['fear_duration' => 1, 'aoe_ratio' => 0.6],
        'target_type' => 'all',
    ]),
    // Special ② 狂暴
    SkillTreeBuilder::line('warrior', 'special', 'warrior_rage', 'rage', '狂暴', [
        'description' => '10 秒内攻击 +50%',
        'mana_cost' => 40,
        'cooldown' => 30,
        'effects' => ['duration' => 10, 'buff_attack_percent' => 0.5],
        'icon_prompt' => 'RPG skill icon, berserk rage, red fury flames, warrior buff, intense glow, anger aura, dramatic lighting, square frame, dark background',
    ], '强化狂暴', [
        'description' => '狂暴期间攻速 +15%',
        'effects' => ['attack_speed_bonus' => 0.15],
    ], [
        'name' => '嗜血狂怒',
        'description' => '攻击吸血 8%（持续输出）',
        'effects' => ['lifesteal' => 0.08],
    ], [
        'name' => '毁灭之怒',
        'description' => '狂暴结束释放一次 150% AOE（爆发收尾）',
        'effects' => ['finale_aoe_ratio' => 1.5],
        'target_type' => 'all',
    ]),
    // Ultimate 泰坦降临
    SkillTreeBuilder::line('warrior', 'ultimate', 'warrior_titan', 'titan-fall', '泰坦降临', [
        'description' => '全体 250% 伤害',
        'mana_cost' => 60,
        'cooldown' => 45,
        'target_type' => 'all',
        'icon_prompt' => 'RPG skill icon, titan fall, giant warrior slam, earthquake impact, epic ultimate, fantasy game icon, square, dark background',
    ], '强化泰坦降临', [
        'description' => '施放后 5 秒免疫控制',
        'effects' => ['cc_immunity_duration' => 5],
    ], [
        'name' => '大地震颤',
        'description' => '全体眩晕 2 秒 + 120% 伤害（群体控制）',
        'effects' => ['stun_duration' => 2, 'damage_ratio' => 1.2],
        'target_type' => 'all',
    ], [
        'name' => '泰坦之拳',
        'description' => '单体 400% 伤害（单体核弹）',
        'effects' => ['damage_ratio' => 4.0],
    ]),
    [SkillTreeBuilder::keyPassive('warrior', 'warrior_key', 'warrior-key', '怒火中烧', [
        'description' => '生命每降低 10%，攻击 +3%、减伤 +2%（最多 5 层）',
        'effects' => ['hp_step' => 0.1, 'attack_per_step' => 0.03, 'reduction_per_step' => 0.02, 'max_stacks' => 5],
        'icon_prompt' => 'RPG skill icon, warrior key passive, burning fury, low health power, red aura, fantasy passive icon, square, dark background',
    ])],
);
