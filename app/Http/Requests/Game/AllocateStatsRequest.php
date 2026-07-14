<?php

namespace App\Http\Requests\Game;

use Illuminate\Foundation\Http\FormRequest;

class AllocateStatsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'character_id' => 'required|integer|exists:game_characters,id',
            'strength' => 'sometimes|integer|min:0',
            'dexterity' => 'sometimes|integer|min:0',
            'vitality' => 'sometimes|integer|min:0',
            'energy' => 'sometimes|integer|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'character_id.required' => '角色 ID 不能为空',
            'character_id.exists' => '角色不存在',
            'strength.min' => '攻击力不能为负数',
            'dexterity.min' => '敏捷不能为负数',
            'vitality.min' => '体力不能为负数',
            'energy.min' => '能量不能为负数',
        ];
    }
}
