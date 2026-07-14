<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Check if running on MySQL
     */
    private function isMySQL(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }

    /**
     * Add table comment (MySQL only)
     */
    private function setTableComment(string $table, string $comment): void
    {
        if ($this->isMySQL()) {
            DB::statement("ALTER TABLE {$table} COMMENT = '{$comment}'");
        }
    }

    public function up(): void
    {
        // ==================== 游戏角色表 ====================
        Schema::create('game_characters', function (Blueprint $table) {
            $table->id()->comment('角色 ID');
            $table->unsignedBigInteger('user_id')->index()->comment('所属用户 ID');
            $table->string('name', 32)->comment('角色名称');
            $table->enum('class', ['warrior', 'mage', 'ranger'])->default('warrior')->comment('职业：warrior 战士/mage 法师/ranger 游侠');
            $table->enum('gender', ['male', 'female'])->default('male')->comment('角色性别');
            $table->unsignedMediumInteger('level')->default(1)->comment('等级');
            $table->unsignedBigInteger('experience')->default(0)->comment('当前经验值');
            $table->unsignedBigInteger('copper')->default(0)->comment('铜币');
            $table->unsignedInteger('strength')->default(10)->comment('力量');
            $table->unsignedInteger('dexterity')->default(10)->comment('敏捷');
            $table->unsignedInteger('vitality')->default(10)->comment('体力');
            $table->unsignedInteger('energy')->default(10)->comment('能量');
            $table->unsignedMediumInteger('skill_points')->default(0)->comment('可用技能点数');
            $table->unsignedMediumInteger('stat_points')->default(0)->comment('可用属性点数');
            $table->unsignedSmallInteger('current_map_id')->nullable()->comment('当前所在地图 ID');
            $table->boolean('is_fighting')->default(false)->comment('是否正在战斗中');
            $table->timestamp('last_combat_at')->nullable()->comment('最后战斗时间');
            $table->timestamp('last_online')->nullable()->comment('最后在线时间');
            $table->timestamp('claimed_offline_at')->nullable()->comment('最后领取离线奖励时间');
            $table->unsignedTinyInteger('difficulty_tier')->default(0)->comment('0=普通 1=困难 2=高手 3=大师 4-9=痛苦 1-6');
            $table->unsignedInteger('current_hp')->nullable()->comment('当前生命值');
            $table->unsignedInteger('current_mana')->nullable()->comment('当前法力值');
            $table->unsignedBigInteger('combat_monster_id')->nullable()->comment('战斗中的怪物 ID');
            $table->json('combat_monsters')->nullable()->comment('战斗中的怪物列表(JSON)');
            $table->timestamp('combat_monsters_refreshed_at')->nullable()->comment('怪物刷新时间，用于定期刷新怪物属性');
            $table->json('discovered_items')->nullable()->comment('已发现的物品 ID 数组');
            $table->json('discovered_monsters')->nullable()->comment('已发现的怪物 ID 数组');
            $table->unsignedInteger('combat_monster_hp')->nullable()->comment('战斗中怪物总血量');
            $table->unsignedInteger('combat_monster_max_hp')->nullable()->comment('战斗中怪物总最大血量');
            $table->unsignedInteger('combat_total_damage_dealt')->default(0)->comment('战斗总伤害输出');
            $table->unsignedInteger('combat_total_damage_taken')->default(0)->comment('战斗总受到伤害');
            $table->unsignedInteger('combat_rounds')->default(0)->comment('战斗回合数');
            $table->json('combat_skills_used')->nullable()->comment('战斗使用的技能(JSON)');
            $table->json('combat_skill_cooldowns')->nullable()->comment('战斗技能冷却(JSON)');
            $table->timestamp('combat_started_at')->nullable()->comment('战斗开始时间');
            $table->boolean('auto_use_hp_potion')->default(false)->comment('自动使用生命药水');
            $table->integer('hp_potion_threshold')->default(30)->comment('生命药水使用阈值百分比');
            $table->boolean('auto_use_mp_potion')->default(false)->comment('自动使用法力药水');
            $table->integer('mp_potion_threshold')->default(30)->comment('法力药水使用阈值百分比');
            $table->unsignedInteger('auto_recycle_max_value')->nullable()->comment('自动回收价值上限(铜)，单价≤此值的背包装备将自动出售');
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });
        $this->setTableComment('game_characters', '游戏角色表');

        // ==================== 游戏物品定义表 ====================
        Schema::create('game_item_definitions', function (Blueprint $table) {
            $table->id()->comment('物品定义 ID');
            $table->string('name', 64)->comment('物品名称');
            $table->enum('type', [
                'weapon', 'helmet', 'armor', 'gloves', 'boots',
                'belt', 'ring', 'amulet', 'potion', 'gem',
            ])->comment('物品类型');
            $table->enum('sub_type', [
                'sword', 'axe', 'mace', 'staff', 'bow', 'dagger',
                'cloth', 'leather', 'mail', 'plate', 'hp', 'mp',
            ])->nullable()->comment('物品子类型');
            $table->unsignedInteger('sockets')->default(0)->comment('宝石插槽数量');
            $table->json('gem_stats')->nullable()->comment('宝石属性(JSON)');
            $table->json('base_stats')->nullable()->comment('基础属性(JSON)');
            $table->unsignedMediumInteger('required_level')->default(1)->comment('需求等级');
            $table->string('icon', 64)->nullable()->comment('图标');
            $table->text('icon_prompt')->nullable()->comment('AI 生成物品图标提示词');
            $table->text('description')->nullable()->comment('描述');
            $table->integer('buy_price')->nullable()->default(null)->comment('购买价格');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->timestamps();
        });
        $this->setTableComment('game_item_definitions', '物品定义表(装备、药水等的模板)');

        // ==================== 游戏物品实例表 ====================
        Schema::create('game_items', function (Blueprint $table) {
            $table->id()->comment('物品实例 ID');
            $table->unsignedBigInteger('character_id')->index()->comment('所属角色 ID');
            $table->unsignedBigInteger('definition_id')->index()->comment('物品定义 ID');
            $table->enum('quality', ['common', 'magic', 'rare', 'legendary', 'mythic'])->default('common')->comment('品质');
            $table->json('stats')->nullable()->comment('物品属性(JSON)');
            $table->json('affixes')->nullable()->comment('词缀(JSON)');
            $table->boolean('is_in_storage')->default(false)->comment('是否在仓库中');
            $table->boolean('is_equipped')->default(false)->comment('是否已装备');
            $table->unsignedSmallInteger('quantity')->default(1)->comment('堆叠数量');
            $table->unsignedTinyInteger('slot_index')->nullable()->comment('背包格子索引');
            $table->unsignedInteger('sockets')->default(0)->comment('宝石插槽数量');
            $table->unsignedInteger('sell_price')->nullable()->comment('出售价格');
            $table->timestamps();

            $table->unique(['character_id', 'is_in_storage', 'slot_index'], 'game_items_character_storage_slot_unique');
            $table->index(['character_id', 'is_equipped'], 'game_items_character_equipped_idx');
            $table->index(['character_id', 'definition_id'], 'game_items_character_definition_idx');
        });
        $this->setTableComment('game_items', '角色背包物品表');

        // ==================== 角色装备槽位表 ====================
        Schema::create('game_equipment', function (Blueprint $table) {
            $table->id()->comment('装备槽 ID');
            $table->unsignedBigInteger('character_id')->index()->comment('所属角色 ID');
            $table->enum('slot', [
                'weapon', 'helmet', 'armor', 'gloves', 'boots',
                'belt', 'ring', 'amulet',
            ])->comment('装备槽位：weapon 武器/helmet 头盔/armor 盔甲/gloves 手套/boots 靴子/belt 腰带/ring 戒指/amulet 护符');
            $table->unsignedBigInteger('item_id')->nullable()->index()->comment('装备的物品 ID');
            $table->timestamps();

            $table->unique(['character_id', 'slot']);
            $table->unique('item_id', 'game_equipment_item_unique');
        });
        $this->setTableComment('game_equipment', '角色装备表');

        // ==================== 宝石镶嵌表 ====================
        Schema::create('game_item_gems', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('item_id')->comment('装备物品 ID');
            $table->unsignedBigInteger('gem_definition_id')->comment('宝石定义 ID');
            $table->unsignedInteger('socket_index')->comment('插槽位置(0 开始)');
            $table->timestamps();

            $table->index('item_id');
            $table->unique(['item_id', 'socket_index'], 'game_item_gems_item_socket_unique');
        });
        $this->setTableComment('game_item_gems', '宝石镶嵌表');

        // ==================== 技能定义表 ====================
        Schema::create('game_skill_definitions', function (Blueprint $table) {
            $table->id()->comment('技能定义 ID');
            $table->string('name', 64)->comment('技能名称');
            $table->text('description')->nullable()->comment('技能描述');
            $table->enum('type', ['active', 'passive'])->default('active')->comment('技能类型：active 主动/passive 被动');
            $table->enum('class_restriction', ['warrior', 'mage', 'ranger', 'all'])->default('all')->comment('职业限制');
            $table->string('skill_stage', 32)->nullable()->comment('阶段：basic/core/defensive/special/ultimate/key_passive');
            $table->string('skill_line', 64)->nullable()->comment('技能线标识，同线节点共享');
            $table->unsignedTinyInteger('node_tier')->nullable()->comment('节点层级：0本体/1强化/2专精');
            $table->string('spec_branch', 1)->nullable()->comment('专精分支：a/b');
            $table->unsignedTinyInteger('unlock_level')->default(1)->comment('阶段解锁所需角色等级');
            $table->string('branch', 32)->nullable()->comment('技能分支/流派：fire/ice/lightning/warrior/ranger/passive');
            $table->unsignedTinyInteger('tier')->default(1)->comment('技能层级：1 基础/2 中级/3 高级');
            $table->unsignedBigInteger('prerequisite_skill_id')->nullable()->comment('前置技能 ID');
            $table->string('prerequisite_effect_key', 64)->nullable()->comment('前置技能效果键');
            $table->unsignedSmallInteger('mana_cost')->default(0)->comment('法力消耗');
            $table->unsignedTinyInteger('cooldown')->default(0)->comment('冷却时间(秒)');
            $table->unsignedTinyInteger('skill_points_cost')->default(1)->comment('学习消耗技能点数');
            $table->unsignedSmallInteger('base_damage')->default(10)->comment('基础伤害');
            $table->string('icon', 64)->nullable()->comment('图标');
            $table->string('effect_key', 32)->nullable()->comment('前端技能特效标识');
            $table->text('icon_prompt')->nullable()->comment('AI生成技能图标提示词');
            $table->json('effects')->nullable()->comment('效果(JSON)');
            $table->string('target_type', 16)->default('single')->comment('目标类型：single 单体/all 全体');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->timestamps();
        });
        $this->setTableComment('game_skill_definitions', '技能定义表');

        // ==================== 角色已学技能表 ====================
        Schema::create('game_character_skills', function (Blueprint $table) {
            $table->id()->comment('角色技能 ID');
            $table->unsignedBigInteger('character_id')->index()->comment('所属角色 ID');
            $table->unsignedBigInteger('skill_id')->index()->comment('技能定义 ID');
            $table->unsignedTinyInteger('slot_index')->nullable()->comment('技能栏索引');
            $table->timestamps();

            $table->unique(['character_id', 'skill_id']);
            $table->unique(['character_id', 'slot_index'], 'game_character_skills_slot_unique');
        });
        $this->setTableComment('game_character_skills', '角色已学技能表');

        // ==================== 地图定义表 ====================
        Schema::create('game_map_definitions', function (Blueprint $table) {
            $table->id()->comment('地图 ID');
            $table->string('name', 64)->comment('地图名称');
            $table->unsignedTinyInteger('act')->default(1)->comment('所属章节');
            $table->unsignedMediumInteger('min_level')->default(1)->comment('最低等级要求');
            $table->unsignedMediumInteger('max_level')->default(100)->comment('最高等级');
            $table->json('monster_ids')->nullable()->comment('怪物 ID 列表(JSON)');
            $table->string('background', 128)->nullable()->comment('背景图');
            $table->text('icon_prompt')->nullable()->comment('AI生成地图背景提示词');
            $table->text('description')->nullable()->comment('地图描述');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->timestamps();

            $table->unique(['name', 'act']);
        });
        $this->setTableComment('game_map_definitions', '地图定义表');

        // ==================== 怪物定义表 ====================
        Schema::create('game_monster_definitions', function (Blueprint $table) {
            $table->id()->comment('怪物 ID');
            $table->string('name', 64)->comment('怪物名称');
            $table->enum('type', ['normal', 'elite', 'boss'])->default('normal')->comment('怪物类型：normal 普通/elite 精英/boss 首领');
            $table->unsignedMediumInteger('level')->default(1)->comment('怪物等级');
            $table->unsignedInteger('hp_base')->default(100)->comment('基础生命值');
            $table->unsignedInteger('hp_per_level')->default(10)->comment('每级生命值加成');
            $table->unsignedInteger('attack_base')->default(10)->comment('基础攻击力');
            $table->unsignedInteger('attack_per_level')->default(2)->comment('每级攻击力加成');
            $table->unsignedInteger('defense_base')->default(5)->comment('基础防御力');
            $table->unsignedInteger('defense_per_level')->default(1)->comment('每级防御力加成');
            $table->unsignedInteger('experience_base')->default(10)->comment('基础经验值');
            $table->unsignedInteger('experience_per_level')->default(5)->comment('每级经验值加成');
            $table->json('drop_table')->nullable()->comment('掉落表(JSON)');
            $table->string('icon', 64)->nullable()->comment('图标');
            $table->text('icon_prompt')->nullable()->comment('AI生成怪物图标提示词');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->timestamps();
        });
        $this->setTableComment('game_monster_definitions', '怪物定义表');

        // ==================== 战斗日志表 ====================
        Schema::create('game_combat_logs', function (Blueprint $table) {
            $table->id()->comment('日志 ID');
            $table->unsignedBigInteger('character_id')->index()->comment('所属角色 ID');
            $table->unsignedBigInteger('map_id')->index()->comment('地图 ID');
            $table->unsignedBigInteger('monster_id')->nullable()->index()->comment('怪物 ID');
            $table->unsignedInteger('damage_dealt')->default(0)->comment('造成的伤害');
            $table->unsignedInteger('damage_taken')->default(0)->comment('受到的伤害');
            $table->boolean('victory')->default(true)->comment('是否胜利');
            $table->json('loot_dropped')->nullable()->comment('掉落物品(JSON)');
            $table->unsignedInteger('experience_gained')->default(0)->comment('获得经验值');
            $table->unsignedBigInteger('copper_gained')->default(0)->comment('获得铜币');
            $table->unsignedMediumInteger('duration_seconds')->default(0)->comment('战斗时长(秒)');
            $table->json('skills_used')->nullable()->comment('使用的技能(JSON)');
            $table->json('potion_used')->nullable()->comment('使用的药水(JSON)');

            $table->unsignedTinyInteger('character_level')->nullable()->comment('角色等级');
            $table->string('character_class', 20)->nullable()->comment('角色职业');
            $table->unsignedInteger('character_attack')->nullable()->comment('角色攻击力');
            $table->unsignedInteger('character_defense')->nullable()->comment('角色防御力');
            $table->decimal('character_crit_rate', 5, 2)->nullable()->comment('角色暴击率(%)');
            $table->decimal('character_crit_damage', 5, 2)->nullable()->comment('角色暴击伤害(倍数)');

            $table->unsignedTinyInteger('monster_level')->nullable()->comment('怪物等级');
            $table->unsignedInteger('monster_hp')->nullable()->comment('怪物当前血量');
            $table->unsignedInteger('monster_max_hp')->nullable()->comment('怪物最大血量');
            $table->unsignedInteger('monster_attack')->nullable()->comment('怪物攻击力');
            $table->unsignedInteger('monster_defense')->nullable()->comment('怪物防御力');
            $table->unsignedInteger('monster_experience')->nullable()->comment('怪物经验值');
            $table->unsignedInteger('monster_copper')->nullable()->comment('怪物铜币掉落');

            $table->unsignedInteger('base_attack_damage')->nullable()->comment('基础/技能伤害');
            $table->unsignedInteger('skill_damage')->nullable()->comment('技能额外伤害');
            $table->unsignedInteger('crit_damage')->nullable()->comment('暴击额外伤害');
            $table->unsignedInteger('aoe_damage')->nullable()->comment('AOE 伤害减免');
            $table->unsignedInteger('total_damage_to_monsters')->nullable()->comment('本回合总伤害');
            $table->decimal('monster_defense_reduction', 5, 2)->nullable()->comment('怪物防御减伤(%)');
            $table->unsignedInteger('monster_counter_damage')->nullable()->comment('怪物反击伤害');

            $table->unsignedTinyInteger('monsters_alive_count')->nullable()->comment('存活怪物数');
            $table->unsignedTinyInteger('monsters_killed_count')->nullable()->comment('杀死怪物数');
            $table->boolean('is_crit')->nullable()->comment('本回合是否暴击');

            $table->unsignedTinyInteger('difficulty_tier')->nullable()->comment('难度等级');
            $table->decimal('difficulty_multiplier', 5, 2)->nullable()->comment('难度系数');
            $table->timestamps();

            $table->index(['character_id', 'created_at']);
            $table->index(['character_id', 'victory', 'created_at'], 'game_combat_logs_character_result_idx');
        });
        $this->setTableComment('game_combat_logs', '战斗日志表');
    }

    public function down(): void
    {
        Schema::dropIfExists('game_combat_logs');
        Schema::dropIfExists('game_monster_definitions');
        Schema::dropIfExists('game_map_definitions');
        Schema::dropIfExists('game_character_skills');
        Schema::dropIfExists('game_skill_definitions');
        Schema::dropIfExists('game_item_gems');
        Schema::dropIfExists('game_equipment');
        Schema::dropIfExists('game_items');
        Schema::dropIfExists('game_item_definitions');
        Schema::dropIfExists('game_characters');
    }
};
