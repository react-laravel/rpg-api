<?php

namespace App\Http\Controllers\Api\Game;

use App\Http\Controllers\Concerns\CharacterConcern;
use App\Http\Controllers\Controller;
use App\Http\Requests\Game\LearnSkillRequest;
use App\Models\Game\GameCharacter;
use App\Models\Game\GameCharacterSkill;
use App\Models\Game\GameSkillDefinition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SkillController extends Controller
{
    use CharacterConcern;

    /** @var array<string, int> */
    private const STAGE_ORDER = [
        'basic' => 1,
        'core' => 2,
        'defensive' => 3,
        'special' => 4,
        'ultimate' => 5,
        'key_passive' => 6,
    ];

    /**
     * 获取技能列表(单一列表，每项含 is_learned 及已学时的 character_skill 信息)
     */
    public function index(Request $request): JsonResponse
    {
        $character = $this->getCharacter($request);

        $definitions = GameSkillDefinition::query()
            ->where('is_active', true)
            ->whereNotNull('skill_line')
            ->where(function ($query) use ($character) {
                $query->where('class_restriction', 'all')
                    ->orWhere('class_restriction', $character->class);
            })
            ->get()
            ->sortBy([
                fn (GameSkillDefinition $def) => self::STAGE_ORDER[$def->skill_stage ?? ''] ?? 99,
                fn (GameSkillDefinition $def) => $def->skill_line ?? '',
                fn (GameSkillDefinition $def) => $def->node_tier ?? 0,
                fn (GameSkillDefinition $def) => $def->spec_branch ?? '',
            ])
            ->values();

        $learnedBySkillId = $character->skills()->get()->keyBy('skill_id');

        $skills = $definitions->map(function (GameSkillDefinition $def) use ($learnedBySkillId) {
            $row = $def->toArray();
            /** @var GameCharacterSkill|null $characterSkill */
            $characterSkill = $learnedBySkillId->get($def->id);
            $row['is_learned'] = $characterSkill !== null;
            if ($characterSkill !== null) {
                $row['character_skill_id'] = $characterSkill->id;
                $row['slot_index'] = $characterSkill->slot_index;
            }

            return $row;
        });

        return $this->success([
            'skills' => $skills->values()->all(),
            'skill_points' => $character->skill_points,
        ]);
    }

    /**
     * 学习技能
     */
    public function learn(LearnSkillRequest $request): JsonResponse
    {
        $character = $this->getCharacter($request);

        $skill = GameSkillDefinition::findOrFail($request->input('skill_id'));

        if (! $skill->canLearnByClass($character->class)) {
            return $this->error('该技能不适合你的职业');
        }

        $existingSkill = $character->skills()->where('skill_id', $skill->id)->first();
        if ($existingSkill) {
            return $this->error('已经学习了该技能');
        }

        $unlockLevel = (int) ($skill->unlock_level ?? 1);
        if ($character->level < $unlockLevel) {
            return $this->error("需要达到 {$unlockLevel} 级才能学习该技能");
        }

        $prereqError = $this->validatePrerequisite($character, $skill);
        if ($prereqError !== null) {
            return $this->error($prereqError);
        }

        $cost = $skill->skill_points_cost ?? 1;
        $isSpecRespec = false;

        if ((int) ($skill->node_tier ?? 0) === 2 && $skill->spec_branch && $skill->skill_line) {
            $siblingSpec = GameSkillDefinition::query()
                ->where('skill_line', $skill->skill_line)
                ->where('node_tier', 2)
                ->where('spec_branch', '!=', $skill->spec_branch)
                ->where('class_restriction', $skill->class_restriction)
                ->first();

            if ($siblingSpec) {
                $learnedSibling = $character->skills()->where('skill_id', $siblingSpec->id)->first();
                if ($learnedSibling) {
                    $learnedSibling->delete();
                    $isSpecRespec = true;
                    $cost = 0;
                }
            }
        }

        if (! $isSpecRespec && $character->skill_points < $cost) {
            return $this->error("技能点不足，学习该技能需要 {$cost} 点");
        }

        $characterSkill = $character->skills()->create([
            'skill_id' => $skill->id,
        ]);
        $characterSkill->load('skill');

        if ($cost > 0) {
            $character->skill_points -= $cost;
            $character->save();
        }

        return $this->success([
            'character' => $character,
            'skill_points' => $character->skill_points,
            'character_skill' => $characterSkill,
            'respec' => $isSpecRespec,
        ], $isSpecRespec ? '专精切换成功' : '技能学习成功');
    }

    private function validatePrerequisite(GameCharacter $character, GameSkillDefinition $skill): ?string
    {
        if ($skill->prerequisite_skill_id) {
            $hasPrereq = $character->skills()->where('skill_id', $skill->prerequisite_skill_id)->exists();
            if (! $hasPrereq) {
                $prereqSkill = GameSkillDefinition::find($skill->prerequisite_skill_id);

                return '需要先学习前置技能: ' . ($prereqSkill !== null ? $prereqSkill->name : '未知');
            }

            return null;
        }

        if ($skill->prerequisite_effect_key) {
            $prereqSkill = GameSkillDefinition::where('effect_key', $skill->prerequisite_effect_key)
                ->where(function ($query) use ($character) {
                    $query->where('class_restriction', 'all')
                        ->orWhere('class_restriction', $character->class);
                })
                ->first();
            if ($prereqSkill) {
                $hasPrereq = $character->skills()->where('skill_id', $prereqSkill->id)->exists();
                if (! $hasPrereq) {
                    return '需要先学习前置技能: ' . $prereqSkill->name;
                }
            }
        }

        return null;
    }
}
