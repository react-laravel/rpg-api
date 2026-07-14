<?php

namespace App\Http\Requests\Game;

use Illuminate\Foundation\Http\FormRequest;

class CreateCharacterRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'class' => is_string($this->input('class')) ? strtolower($this->input('class')) : $this->input('class'),
            'gender' => is_string($this->input('gender')) ? strtolower($this->input('gender')) : $this->input('gender'),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:32|alpha_num',
            'class' => 'required|in:warrior,mage,ranger',
            'gender' => 'nullable|in:male,female',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => '请输入角色名称',
            'name.max' => '角色名称不能超过 32 个字符',
            'name.alpha_num' => '角色名称只能包含字母和数字',
            'class.required' => '请选择职业',
            'class.in' => '职业选择无效',
            'gender.in' => '性别选择无效',
        ];
    }
}
