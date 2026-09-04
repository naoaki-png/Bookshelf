<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class GenreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255', 'unique:genres,name'],
        ];
        if ($this->route('genre')) {
            $rules['name'] = ['required', 'string', 'max:255', 'unique:genres,name,' . $this->route('genre')->id];

        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'name.required' => 'ジャンル名は必須項目です。',
            'name.unique' => 'このジャンル名は既に使用されています。',
        ];
    }
}
