<?php

use Database\Seeders\Game\Data\Skills\SkillTreeBuilder;

return SkillTreeBuilder::merge(
    SkillTreeBuilder::line('ranger', 'basic', 'ranger_pierce', 'pierce', '穿刺射击', [
        'description' => '单体 100% 伤害，暴击率 +20%',
        'mana_cost' => 5,
        'cooldown' => 0,
        'effects' => ['crit_bonus' => 0.2],
        'icon_prompt' => 'RPG skill icon, piercing shot, arrow penetrating armor, ranger attack, detailed fantasy icon, square, dark background',
    ], '强化穿刺射击', [
        'description' => '暴击伤害 +30%',
        'effects' => ['crit_damage_bonus' => 0.3],
    ], [
        'name' => '弱点穿刺',
        'description' => '对高血目标暴击 +25%（单体爆发）',
        'effects' => ['high_hp_crit_bonus' => 0.25],
    ], [
        'name' => '连射穿刺',
        'description' => '暴击后 0.5 秒内再射 1 箭 50%（持续输出）',
        'effects' => ['follow_up_ratio' => 0.5, 'follow_up_window' => 0.5],
    ]),
    SkillTreeBuilder::line('ranger', 'basic', 'ranger_multi_shot', 'multi-shot', '多重射击', [
        'description' => '全体 3×40% 伤害',
        'mana_cost' => 12,
        'cooldown' => 4,
        'target_type' => 'all',
        'effects' => ['shot_count' => 3, 'shot_ratio' => 0.4],
        'icon_prompt' => 'RPG skill icon, multi shot, fan of arrows, ranger AOE, detailed game icon, square, dark background',
    ], '强化多重射击', [
        'description' => '箭数 +2',
        'effects' => ['shot_count' => 5],
    ], [
        'name' => '散射覆盖',
        'description' => '范围 +50%（群体）',
        'effects' => ['radius_bonus' => 0.5],
        'target_type' => 'all',
    ], [
        'name' => '集中齐射',
        'description' => '仅打单体但 6×50%（单体）',
        'effects' => ['shot_count' => 6, 'shot_ratio' => 0.5, 'single_target' => true],
    ]),
    SkillTreeBuilder::line('ranger', 'core', 'ranger_poison', 'poison', '毒箭', [
        'description' => '单体 80% + DoT 5 秒',
        'mana_cost' => 10,
        'cooldown' => 5,
        'effects' => ['dot_duration' => 5],
        'icon_prompt' => 'RPG skill icon, poison arrow, green toxic drip, ranger skill, detailed fantasy icon, square, dark background',
    ], '强化毒箭', [
        'description' => 'DoT 可叠加 2 层',
        'effects' => ['dot_stacks' => 2],
    ], [
        'name' => '毒云扩散',
        'description' => '死亡时毒云 AOE（群体持续）',
        'effects' => ['poison_cloud_on_kill' => true],
        'target_type' => 'all',
    ], [
        'name' => '剧毒专注',
        'description' => '单体 DoT 伤害 +60%（单体 DoT）',
        'effects' => ['dot_damage_bonus' => 0.6],
    ]),
    SkillTreeBuilder::line('ranger', 'core', 'ranger_gale_step', 'gale-step', '疾风步', [
        'description' => '下次攻击 +40%',
        'mana_cost' => 8,
        'cooldown' => 10,
        'effects' => ['next_attack_bonus' => 0.4],
        'icon_prompt' => 'RPG skill icon, gale step, wind speed, ranger mobility, swift movement trails, square, dark background',
    ], '强化疾风步', [
        'description' => '移速 +25% 3 秒',
        'effects' => ['move_speed_bonus' => 0.25, 'move_duration' => 3],
    ], [
        'name' => '风驰电掣',
        'description' => '可穿越敌人造成 50% 伤（群体路径）',
        'effects' => ['dash_damage_ratio' => 0.5],
        'target_type' => 'all',
    ], [
        'name' => '稳准狠',
        'description' => '不位移，但下次必暴击（单体爆发）',
        'effects' => ['guaranteed_crit' => true, 'no_dash' => true],
    ]),
    SkillTreeBuilder::line('ranger', 'core', 'ranger_shadow_step', 'shadow-step', '暗影步', [
        'description' => '背刺 150% 伤害',
        'mana_cost' => 15,
        'cooldown' => 8,
        'effects' => ['backstab_ratio' => 1.5],
        'icon_prompt' => 'RPG skill icon, shadow step, dark teleport backstab, ranger assassin, purple shadows, square, dark background',
    ], '强化暗影步', [
        'description' => '背刺后 2 秒闪避 +20%',
        'effects' => ['dodge_bonus' => 0.2, 'dodge_duration' => 2],
    ], [
        'name' => '影袭连击',
        'description' => '背刺后再打 1 次 80%（持续）',
        'effects' => ['combo_ratio' => 0.8],
    ], [
        'name' => '致命伏击',
        'description' => '背刺伤害 +80%，CD +2（爆发）',
        'effects' => ['backstab_bonus' => 0.8, 'cooldown_penalty' => 2],
    ]),
    SkillTreeBuilder::line('ranger', 'defensive', 'ranger_dodge', 'dodge', '闪避', [
        'description' => '6 秒内闪避 +30%',
        'mana_cost' => 10,
        'cooldown' => 12,
        'effects' => ['dodge_bonus' => 0.3, 'duration' => 6],
        'icon_prompt' => 'RPG skill icon, dodge roll, evasive maneuver, ranger defensive, motion blur, square, dark background',
    ], '强化闪避', [
        'description' => '触发闪避时回复 3% 生命',
        'effects' => ['heal_on_dodge' => 0.03],
    ], [
        'name' => '幻影步',
        'description' => '闪避成功留下幻影吸引（控制/生存）',
        'effects' => ['decoy_on_dodge' => true],
    ], [
        'name' => '铁壁闪避',
        'description' => '闪避率减半但减伤 +15%（稳定减伤）',
        'effects' => ['dodge_penalty' => 0.5, 'damage_reduction' => 0.15],
    ]),
    SkillTreeBuilder::line('ranger', 'special', 'ranger_trap_net', 'trap-net', '陷阱网', [
        'description' => '束缚 2 秒 + 60% 伤害',
        'mana_cost' => 18,
        'cooldown' => 14,
        'effects' => ['root_duration' => 2, 'damage_ratio' => 0.6],
        'icon_prompt' => 'RPG skill icon, trap net, binding ropes, ranger control skill, detailed fantasy icon, square, dark background',
    ], '强化陷阱网', [
        'description' => '陷阱持续 8 秒可触发 2 次',
        'effects' => ['trap_duration' => 8, 'trap_triggers' => 2],
    ], [
        'name' => '爆炸陷阱',
        'description' => '触发时小范围 AOE（群体）',
        'effects' => ['explosion_aoe' => true],
        'target_type' => 'all',
    ], [
        'name' => '剧毒陷阱',
        'description' => '束缚 + 毒 DoT 6 秒（持续控制）',
        'effects' => ['poison_dot_duration' => 6],
    ]),
    SkillTreeBuilder::line('ranger', 'special', 'ranger_hunters_mark', 'hunters-mark', '标记射击', [
        'description' => '标记 8 秒，受伤 +15%',
        'mana_cost' => 12,
        'cooldown' => 6,
        'effects' => ['mark_duration' => 8, 'damage_taken_bonus' => 0.15],
        'icon_prompt' => 'RPG skill icon, hunters mark, glowing target sigil, ranger debuff, detailed game icon, square, dark background',
    ], '强化标记射击', [
        'description' => '标记可传递 1 次',
        'effects' => ['mark_spread' => 1],
    ], [
        'name' => '群体标记',
        'description' => '同时标记 3 个（群体）',
        'effects' => ['mark_count' => 3],
        'target_type' => 'all',
    ], [
        'name' => '猎杀标记',
        'description' => '仅单体但受伤 +35%（单体）',
        'effects' => ['damage_taken_bonus' => 0.35, 'single_target' => true],
    ]),
    SkillTreeBuilder::line('ranger', 'ultimate', 'ranger_arrow_rain', 'arrow-rain', '箭雨', [
        'description' => '全体 220% 伤害',
        'mana_cost' => 45,
        'cooldown' => 10,
        'target_type' => 'all',
        'icon_prompt' => 'RPG skill icon, arrow rain, sky full of arrows, ranger ultimate AOE, epic fantasy icon, square, dark background',
    ], '强化箭雨', [
        'description' => '箭雨持续 3 秒',
        'effects' => ['rain_duration' => 3],
    ], [
        'name' => '天罚箭雨',
        'description' => '每秒全体 60%（群体持续）',
        'effects' => ['tick_ratio' => 0.6],
        'target_type' => 'all',
    ], [
        'name' => '贯穿箭雨',
        'description' => '单体主目标 8×40%（单体倾泻）',
        'effects' => ['shot_count' => 8, 'shot_ratio' => 0.4, 'single_target' => true],
    ]),
    [SkillTreeBuilder::keyPassive('ranger', 'ranger_key', 'ranger-key', '猎手本能', [
        'description' => '暴击后 4 秒内攻速 +5%（可叠 3 层）',
        'effects' => ['attack_speed_per_crit' => 0.05, 'buff_duration' => 4, 'max_stacks' => 3],
        'icon_prompt' => 'RPG skill icon, hunter instinct, ranger key passive, keen eye, predator focus, square, dark background',
    ])],
);
