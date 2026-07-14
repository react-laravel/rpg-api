<?php

namespace App\Http\Requests\Game;

use Illuminate\Foundation\Http\FormRequest;

class EquipItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => 'required|integer|min:1|exists:game_items,id',
        ];
    }

    public function messages(): array
    {
        return [
            'item_id.required' => '物品 ID 不能为空',
            'item_id.min' => '物品 ID 必须大于 0',
            'item_id.exists' => '物品不存在',
        ];
    }
}
