<?php

namespace App\Http\Requests\Game;

use Illuminate\Foundation\Http\FormRequest;

class LearnSkillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'skill_id' => 'required|integer|exists:game_skill_definitions,id',
        ];
    }

    public function messages(): array
    {
        return [
            'skill_id.required' => '技能 ID 不能为空',
            'skill_id.exists' => '技能不存在',
        ];
    }
}
