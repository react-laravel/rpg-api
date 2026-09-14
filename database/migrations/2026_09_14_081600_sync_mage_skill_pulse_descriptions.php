<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 同步法师技能描述与拍制语义（冷却/异常单位为战斗推进次数）。
 */
return new class extends Migration
{
    public function up(): void
    {
        $updates = [
            ['skill_line' => 'mage_fireball', 'node_tier' => 0, 'spec_branch' => null, 'description' => '中耗单体火焰弹，无冷却，可每拍释放；伤害高于冰箭', 'cooldown' => 0, 'effects' => null],
            ['skill_line' => 'mage_fireball', 'node_tier' => 1, 'spec_branch' => null, 'description' => '单体伤害 +30%', 'effects' => ['damage_bonus' => 0.3]],
            ['skill_line' => 'mage_fireball', 'node_tier' => 2, 'spec_branch' => 'a', 'description' => '命中后附加灼烧，持续 3 次战斗推进', 'effects' => ['burn_duration' => 3]],
            ['skill_line' => 'mage_fireball', 'node_tier' => 2, 'spec_branch' => 'b', 'description' => '单体伤害再 +40%（与强化叠加）', 'effects' => ['damage_bonus' => 0.4]],
            ['skill_line' => 'mage_ice_arrow', 'node_tier' => 0, 'spec_branch' => null, 'description' => '低耗单体冰伤，无冷却；伤害低于小火球，偏续航'],
            ['skill_line' => 'mage_ice_arrow', 'node_tier' => 1, 'spec_branch' => null, 'description' => '20% 概率减速目标 2 次推进（反击伤害减半）', 'effects' => ['slow_chance' => 0.2, 'slow_duration' => 2]],
            ['skill_line' => 'mage_ice_arrow', 'node_tier' => 2, 'spec_branch' => 'a', 'description' => '对已减速目标额外 +30% 伤害', 'effects' => ['slowed_damage_bonus' => 0.3]],
            ['skill_line' => 'mage_ice_arrow', 'node_tier' => 2, 'spec_branch' => 'b', 'description' => '穿透最多 2 个目标，后续目标伤害 -20%', 'effects' => ['pierce_count' => 2, 'pierce_falloff' => 0.2]],
            ['skill_line' => 'mage_frost_nova', 'node_tier' => 0, 'spec_branch' => null, 'description' => '全体冰伤，冷却 4 次；适合 2 只及以上怪物'],
            ['skill_line' => 'mage_frost_nova', 'node_tier' => 1, 'spec_branch' => null, 'description' => '冻结目标 1 次推进（BOSS 同样冻结 1 次）', 'effects' => ['freeze_duration' => 1, 'boss_freeze_duration' => 1]],
            ['skill_line' => 'mage_frost_nova', 'node_tier' => 2, 'spec_branch' => 'a', 'description' => '命中后地面减速 4 次推进（群体控制）', 'effects' => ['ground_slow_duration' => 4]],
            ['skill_line' => 'mage_frost_nova', 'node_tier' => 2, 'spec_branch' => 'b', 'description' => '改为单体，伤害变为 300%', 'effects' => ['single_target_ratio' => 3.0]],
            ['skill_line' => 'mage_lightning', 'node_tier' => 0, 'spec_branch' => null, 'description' => '高效单体雷伤，冷却 3 次；伤害介于小火球与奥术飞弹之间'],
            ['skill_line' => 'mage_lightning', 'node_tier' => 1, 'spec_branch' => null, 'description' => '施放时暴击率 +15%', 'effects' => ['crit_bonus' => 0.15]],
            ['skill_line' => 'mage_lightning', 'node_tier' => 2, 'spec_branch' => 'a', 'description' => '暴击时连锁 1 个目标，造成 50% 伤害', 'effects' => ['chain_on_crit' => true, 'chain_ratio' => 0.5]],
            ['skill_line' => 'mage_lightning', 'node_tier' => 2, 'spec_branch' => 'b', 'description' => '非暴击伤害 +25%，冷却 -1 次', 'effects' => ['non_crit_bonus' => 0.25, 'cooldown_reduction' => 1]],
            ['skill_line' => 'mage_chain_lightning', 'node_tier' => 0, 'spec_branch' => null, 'description' => '弹跳多目标雷伤，默认弹 3 次，冷却 5 次；2 只及以上怪物时优先于单体基础法术'],
            ['skill_line' => 'mage_chain_lightning', 'node_tier' => 1, 'spec_branch' => null, 'description' => '弹跳次数 +1（共 4 次）', 'effects' => ['bounce_count' => 4]],
            ['skill_line' => 'mage_chain_lightning', 'node_tier' => 2, 'spec_branch' => 'a', 'description' => '弹跳时后续目标仍造成 70% 伤害（群体覆盖）', 'effects' => ['bounce_radius_growth' => true, 'bounce_ratio' => 0.7]],
            ['skill_line' => 'mage_chain_lightning', 'node_tier' => 2, 'spec_branch' => 'b', 'description' => '仅弹 2 次，但每次 120% 伤害', 'effects' => ['bounce_count' => 2, 'bounce_ratio' => 1.2]],
            ['skill_line' => 'mage_shield', 'node_tier' => 0, 'spec_branch' => null, 'description' => '获得吸收 100 点伤害的护盾，持续 8 次推进（防御技，自动战斗不优先选用）', 'effects' => ['shield_amount' => 100, 'duration' => 8]],
            ['skill_line' => 'mage_shield', 'node_tier' => 1, 'spec_branch' => null, 'description' => '吸收提升至 150，破盾时反弹 30% 已吸收伤害', 'effects' => ['shield_amount' => 150, 'reflect_on_break' => 0.3]],
            ['skill_line' => 'mage_shield', 'node_tier' => 2, 'spec_branch' => 'a', 'description' => '护盾存在期间魔伤 +10%', 'effects' => ['spell_damage_bonus' => 0.1]],
            ['skill_line' => 'mage_shield', 'node_tier' => 2, 'spec_branch' => 'b', 'description' => '破盾时回复 15% 最大法力', 'effects' => ['mana_restore_on_break' => 0.15]],
            ['skill_line' => 'mage_meteor', 'node_tier' => 0, 'spec_branch' => null, 'description' => '高耗全体火伤，冷却 8 次；适合清场'],
            ['skill_line' => 'mage_meteor', 'node_tier' => 1, 'spec_branch' => null, 'description' => '落地后附加灼烧，持续 4 次推进', 'effects' => ['burn_duration' => 4]],
            ['skill_line' => 'mage_meteor', 'node_tier' => 2, 'spec_branch' => 'a', 'description' => '额外陨石视觉与多段落点表现（群体）', 'effects' => ['extra_meteors' => 2]],
            ['skill_line' => 'mage_meteor', 'node_tier' => 2, 'spec_branch' => 'b', 'description' => '改为单体，主目标伤害 350%', 'effects' => ['single_target_ratio' => 3.5]],
            ['skill_line' => 'mage_arcane_missile', 'node_tier' => 0, 'spec_branch' => null, 'description' => '高效单体奥术伤害，冷却 6 次；一次结算，多弹特效', 'effects' => []],
            ['skill_line' => 'mage_arcane_missile', 'node_tier' => 1, 'spec_branch' => null, 'description' => '奥术伤害 +20%', 'effects' => ['damage_bonus' => 0.2]],
            ['skill_line' => 'mage_arcane_missile', 'node_tier' => 2, 'spec_branch' => 'a', 'description' => '弹射最多 2 个额外目标，后续目标 -30% 伤害', 'effects' => ['pierce_count' => 3, 'pierce_falloff' => 0.3]],
            ['skill_line' => 'mage_arcane_missile', 'node_tier' => 2, 'spec_branch' => 'b', 'description' => '单体伤害再 +25%', 'effects' => ['damage_bonus' => 0.25]],
            ['skill_line' => 'mage_cataclysm', 'node_tier' => 0, 'spec_branch' => null, 'description' => '终极全体爆发，冷却 30 次；适合高血量多目标'],
            ['skill_line' => 'mage_cataclysm', 'node_tier' => 1, 'spec_branch' => null, 'description' => '命中附加灼烧、冻结与感电（减速）', 'effects' => ['apply_burn' => true, 'apply_freeze' => true, 'apply_shock' => true, 'ailment_duration' => 3]],
            ['skill_line' => 'mage_cataclysm', 'node_tier' => 2, 'spec_branch' => 'a', 'description' => '异常持续延长至 6 次推进', 'effects' => ['ailment_duration' => 6]],
            ['skill_line' => 'mage_cataclysm', 'node_tier' => 2, 'spec_branch' => 'b', 'description' => '改为单体，伤害变为 450%', 'effects' => ['single_target_ratio' => 4.5]],
            ['skill_line' => 'mage_key', 'node_tier' => 0, 'spec_branch' => null, 'description' => '每学习 1 条已点满的技能线（含专精），最大法力 +5%、魔伤 +2%（最多 6 线）', 'effects' => ['mana_per_line' => 0.05, 'spell_damage_per_line' => 0.02, 'max_lines' => 6]],
        ];

        foreach ($updates as $row) {
            $query = DB::table('game_skill_definitions')
                ->where('skill_line', $row['skill_line'])
                ->where('node_tier', $row['node_tier']);

            if (($row['spec_branch'] ?? null) === null) {
                $query->whereNull('spec_branch');
            } else {
                $query->where('spec_branch', $row['spec_branch']);
            }

            $payload = [
                'description' => $row['description'],
                'updated_at' => now(),
            ];
            if (array_key_exists('cooldown', $row)) {
                $payload['cooldown'] = $row['cooldown'];
            }
            if (array_key_exists('effects', $row)) {
                $payload['effects'] = $row['effects'] === null
                    ? null
                    : json_encode($row['effects'], JSON_UNESCAPED_UNICODE);
            }

            $query->update($payload);
        }
    }

    public function down(): void
    {
        // 描述性迁移，不回滚文案
    }
};
