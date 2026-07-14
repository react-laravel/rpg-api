<?php

namespace App\Http\Requests\Game;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDifficultyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'character_id' => 'sometimes|integer|min:1|exists:game_characters,id',
            'difficulty_tier' => 'required|integer|min:0|max:9',
        ];
    }

    public function messages(): array
    {
        return [
            'character_id.min' => '角色 ID 必须大于 0',
            'difficulty_tier.required' => '难度等级不能为空',
            'difficulty_tier.min' => '难度等级不能小于 0',
            'difficulty_tier.max' => '难度等级不能大于 9',
        ];
    }
}
