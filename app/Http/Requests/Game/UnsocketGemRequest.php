<?php

namespace App\Http\Requests\Game;

use Illuminate\Foundation\Http\FormRequest;

class UnsocketGemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => 'required|integer|min:1|exists:game_items,id',
            'socket_index' => 'required|integer|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'item_id.required' => '装备 ID 不能为空',
            'item_id.min' => '装备 ID 必须大于 0',
            'item_id.exists' => '装备不存在',
            'socket_index.required' => '插槽索引不能为空',
            'socket_index.min' => '插槽索引无效',
        ];
    }
}
