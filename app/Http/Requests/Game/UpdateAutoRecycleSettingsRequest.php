<?php

namespace App\Http\Requests\Game;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAutoRecycleSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'auto_recycle_max_value' => 'nullable|integer|min:0|max:99999',
        ];
    }

    public function messages(): array
    {
        return [
            'auto_recycle_max_value.min' => '自动回收价值不能小于 0',
            'auto_recycle_max_value.max' => '自动回收价值不能超过 99999',
        ];
    }
}
