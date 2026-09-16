<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $actives = [
            'mage_fireball' => [
                'base_damage' => 160,
                'mana_cost' => 8,
                'cooldown' => 0,
                'description' => '唯一的无冷却输出。造成攻击力 160% 的火焰伤害，每拍都能放',
            ],
            'mage_ice_arrow' => [
                'base_damage' => 130,
                'mana_cost' => 6,
                'cooldown' => 2,
                'description' => '单体控制箭。造成攻击力 130% 伤害，并减速 2 次推进：该怪反击伤害减半',
                'effects' => json_encode(['slow_chance' => 1, 'slow_duration' => 2], JSON_UNESCAPED_UNICODE),
            ],
            'mage_frost_nova' => [
                'base_damage' => 150,
                'mana_cost' => 16,
                'cooldown' => 5,
                'description' => '群体控制。造成攻击力 150% 的全体冰伤，并冻结 1 次推进（被冻住的怪本拍不反击）',
                'effects' => json_encode(['freeze_duration' => 1, 'boss_freeze_duration' => 1], JSON_UNESCAPED_UNICODE),
            ],
            'mage_lightning' => [
                'base_damage' => 220,
                'mana_cost' => 12,
                'cooldown' => 3,
                'description' => '短冷却单体爆发。造成攻击力 220% 的雷电伤害，冷却 3 次',
            ],
            'mage_chain_lightning' => [
                'base_damage' => 180,
                'mana_cost' => 20,
                'cooldown' => 5,
                'target_type' => 'single',
                'description' => '弹跳清杂，不是全体陨石。造成攻击力 180% 伤害，弹跳 3 个目标，冷却 5 次',
            ],
            'mage_meteor' => [
                'base_damage' => 320,
                'mana_cost' => 36,
                'cooldown' => 8,
                'description' => '真正的全体砸场。造成攻击力 320% 的火伤打到每一只怪，冷却 8 次',
            ],
            'mage_arcane_missile' => [
                'base_damage' => 260,
                'mana_cost' => 24,
                'cooldown' => 6,
                'description' => '单体重击。造成攻击力 260% 的奥术伤害，冷却 6 次，用来点杀一只',
            ],
            'mage_cataclysm' => [
                'base_damage' => 450,
                'mana_cost' => 55,
                'cooldown' => 30,
                'description' => '终极演出。造成攻击力 450% 的全体伤害，冷却 30 次',
            ],
        ];

        foreach ($actives as $skillLine => $payload) {
            DB::table('game_skill_definitions')
                ->where('skill_line', $skillLine)
                ->where('node_tier', 0)
                ->whereNull('spec_branch')
                ->update(array_merge($payload, ['updated_at' => now()]));
        }

        $passives = [
            ['mage_ice_arrow', 'b', '减速改为冻结 1 次推进：该怪本拍完全不能反击'],
            ['mage_ice_arrow', 'a', '对已减速或冻结的目标额外 +40% 伤害'],
            ['mage_frost_nova', null, '冻结延长至 2 次推进'],
            ['mage_chain_lightning', null, '弹跳次数 +1（共 4 个目标）'],
            ['mage_meteor', null, '落地后灼烧 4 次推进'],
        ];

        foreach ($passives as [$line, $branch, $description]) {
            $query = DB::table('game_skill_definitions')
                ->where('skill_line', $line)
                ->where('node_tier', 1);
            if ($branch === null) {
                $query->whereNull('spec_branch');
            } else {
                $query->where('spec_branch', $branch);
            }
            $query->update([
                'description' => $description,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // 技能数值与定位已整体替换，不回滚到加法伤害。
    }
};
