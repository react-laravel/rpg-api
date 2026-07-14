<?php

namespace App\Http\Requests\Game;

use Illuminate\Foundation\Http\FormRequest;

class UnequipItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'slot' => 'required|in:weapon,helmet,armor,gloves,boots,belt,ring,amulet',
        ];
    }

    public function messages(): array
    {
        return [
            'slot.required' => '装备槽位不能为空',
            'slot.in' => '装备槽位无效',
        ];
    }
}
