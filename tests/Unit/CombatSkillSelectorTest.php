<?php

namespace Tests\Unit;

use App\Services\Game\Combat\CombatSkillSelector;
use PHPUnit\Framework\TestCase;

class CombatSkillSelectorTest extends TestCase
{
    public function test_empty_request_disables_all_active_skills(): void
    {
        $skills = collect([
            (object) ['skill' => (object) ['id' => 1]],
            (object) ['skill' => (object) ['id' => 2]],
        ]);

        $filtered = (new CombatSkillSelector)->restrictActiveSkills($skills, []);

        $this->assertCount(0, $filtered);
    }

    public function test_requested_ids_keep_only_allowed_skills(): void
    {
        $skills = collect([
            (object) ['skill' => (object) ['id' => 1]],
            (object) ['skill' => (object) ['id' => 2]],
        ]);

        $filtered = (new CombatSkillSelector)->restrictActiveSkills($skills, [2]);

        $this->assertSame([2], $filtered->pluck('skill.id')->all());
    }

    public function test_null_request_keeps_all_active_skills(): void
    {
        $skills = collect([
            (object) ['skill' => (object) ['id' => 1]],
            (object) ['skill' => (object) ['id' => 2]],
        ]);

        $filtered = (new CombatSkillSelector)->restrictActiveSkills($skills, null);

        $this->assertCount(2, $filtered);
    }

    public function test_remaining_cooldowns_keep_ticks_when_no_legacy_cursor(): void
    {
        $selector = new CombatSkillSelector;

        $this->assertSame(
            [7 => 3, 9 => 1],
            $selector->remainingCooldowns([7 => 3, 8 => 0, 9 => 1], 0)
        );
    }

    public function test_remaining_cooldowns_convert_legacy_end_round_values(): void
    {
        $selector = new CombatSkillSelector;

        $this->assertSame(
            [7 => 3],
            $selector->remainingCooldowns([7 => 8, 8 => 5], 5)
        );
    }

    public function test_tick_remaining_cooldowns_decrements_and_drops_ready_skills(): void
    {
        $selector = new CombatSkillSelector;

        $this->assertSame(
            [7 => 2],
            $selector->tickRemainingCooldowns([7 => 3, 8 => 1])
        );
    }

    public function test_remaining_cooldowns_treat_null_as_empty(): void
    {
        $selector = new CombatSkillSelector;

        $this->assertSame([], $selector->remainingCooldowns(null));
        $this->assertSame([], $selector->tickRemainingCooldowns(null));
    }

    public function test_cooldown_one_skips_the_next_pulse(): void
    {
        $selector = new CombatSkillSelector;

        $afterCast = $selector->cooldownsAfterPulse([], 7, 1);
        $this->assertSame([7 => 1], $afterCast);

        $afterWait = $selector->cooldownsAfterPulse($afterCast, null, 0);
        $this->assertSame([], $afterWait);

        $afterRecast = $selector->cooldownsAfterPulse($afterWait, 7, 1);
        $this->assertSame([7 => 1], $afterRecast);
    }

    public function test_damage_bonus_effects_stack_additively(): void
    {
        $selector = new CombatSkillSelector;
        $merged = $selector->mergeEffectMaps(
            ['damage_bonus' => 0.3],
            ['damage_bonus' => 0.4, 'burn_duration' => 3]
        );

        $this->assertEqualsWithDelta(0.7, $merged['damage_bonus'], 0.001);
        $this->assertSame(3, $merged['burn_duration']);
    }

    public function test_build_skill_candidate_applies_cooldown_reduction_and_single_target_ratio(): void
    {
        $selector = new CombatSkillSelector;
        $skill = (object) [
            'id' => 9,
            'name' => '冰霜新星',
            'type' => 'active',
            'effect_key' => 'frost-nova',
            'skill_line' => 'mage_frost_nova',
            'target_type' => 'all',
            'base_damage' => 150,
            'mana_cost' => 16,
            'cooldown' => 5,
            'effects' => [],
            'icon' => null,
        ];
        $passives = collect([
            (object) ['skill' => (object) [
                'skill_line' => 'mage_frost_nova',
                'effect_key' => 'frost-nova',
                'name' => '冰霜尖刺',
                'effects' => ['single_target_ratio' => 3.0, 'cooldown_reduction' => 1],
            ]],
        ]);

        $candidate = $selector->buildSkillCandidate($skill, $passives);

        $this->assertTrue($candidate['is_aoe']);
        $this->assertSame(150, $candidate['damage']);
        $this->assertEqualsWithDelta(3.0, $candidate['cast_effects']['single_target_ratio'], 0.001);
        $this->assertSame(4, $candidate['cooldown']);
        $this->assertFalse($candidate['is_defensive']);
    }

    public function test_shield_skill_is_marked_defensive_with_zero_damage(): void
    {
        $selector = new CombatSkillSelector;
        $skill = (object) [
            'id' => 11,
            'name' => '魔法护盾',
            'type' => 'active',
            'effect_key' => 'shield',
            'skill_line' => 'mage_shield',
            'target_type' => 'single',
            'base_damage' => 0,
            'mana_cost' => 20,
            'cooldown' => 15,
            'effects' => ['shield_amount' => 100, 'duration' => 8],
            'icon' => null,
        ];

        $candidate = $selector->buildSkillCandidate($skill, collect());

        $this->assertTrue($candidate['is_defensive']);
        $this->assertSame(0, $candidate['damage']);
        $this->assertSame(100, $candidate['cast_effects']['shield_amount']);
        $this->assertSame(8, $candidate['cast_effects']['shield_duration']);
    }

    public function test_select_optimal_skill_prefers_offensive_over_shield(): void
    {
        $selector = new CombatSkillSelector;
        $selected = $selector->selectOptimalSkill([
            [
                'damage' => 0,
                'mana_cost' => 20,
                'cooldown' => 15,
                'is_aoe' => false,
                'is_defensive' => true,
                'cast_effects' => ['shield_amount' => 100],
            ],
            [
                'damage' => 16,
                'mana_cost' => 10,
                'cooldown' => 0,
                'is_aoe' => false,
                'is_defensive' => false,
                'cast_effects' => [],
            ],
        ], 1, 0, 100, 20);

        $this->assertSame(16, $selected['damage']);
        $this->assertFalse($selected['is_defensive']);
    }

    public function test_charm_light_is_chosen_when_the_pet_is_down(): void
    {
        $selector = new CombatSkillSelector;
        $selected = $selector->preferSummonSkill([
            $this->skillChoice('fireball', 16),
            $this->skillChoice('charm-light', 0),
        ]);

        $this->assertSame(0, $selected['damage']);
        $this->assertSame('charm-light', $selected['skill']->effect_key);
    }

    public function test_ice_arrow_is_cast_when_a_monster_can_still_hit(): void
    {
        $selector = new CombatSkillSelector;
        $ice = $this->skillChoice('ice-arrow', 13);
        $fire = $this->skillChoice('fireball', 16);

        $selected = $selector->preferIncomingControlSkill(
            [$fire, $ice],
            [
                ['hp' => 4, 'attack' => 0],
                ['hp' => 20, 'attack' => 2],
            ]
        );

        $this->assertSame(13, $selected['damage']);
    }

    public function test_ice_arrow_waits_when_every_attacker_is_already_controlled(): void
    {
        $selector = new CombatSkillSelector;

        $this->assertNull($selector->preferIncomingControlSkill(
            [$this->skillChoice('fireball', 16), $this->skillChoice('ice-arrow', 13)],
            [
                ['hp' => 20, 'attack' => 2, 'slow_ticks' => 1],
                ['hp' => 20, 'attack' => 4, 'freeze_ticks' => 1],
                ['hp' => 8, 'attack' => 0],
            ]
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function skillChoice(string $effectKey, int $damage): array
    {
        return [
            'damage' => $damage,
            'skill' => (object) ['effect_key' => $effectKey],
        ];
    }
}
