<?php

namespace App\Http\Requests\Game;

use Illuminate\Foundation\Http\FormRequest;

class SellItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => 'required|integer|min:1|exists:game_items,id',
            'quantity' => 'sometimes|integer|min:1',
            'idempotency_key' => 'sometimes|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'item_id.required' => '物品 ID 不能为空',
            'item_id.min' => '物品 ID 必须大于 0',
            'item_id.exists' => '物品不存在',
            'quantity.min' => '数量不能小于 1',
        ];
    }
}
