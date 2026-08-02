<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApiBookIndexRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'genre' => ['nullable', 'string', 'exists:genres,name'],
            'keyword' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'string', 'in:latest,rating,reviews_count'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            //
        ];
    }
}
