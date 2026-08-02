<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApiBookRequest extends FormRequest
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
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'author' => ['required', 'string', 'max:255'],
            'isbn' => ['required', 'digits:13', 'unique:books,isbn'],
            'description' => ['nullable', 'string'],
            'published_date' => ['required', 'date'],
            'image_url' => ['nullable', 'string', 'url', 'max:255'],
            'genres' => ['required', 'array'],
            'genres.*' => ['exists:genres,id'],
        ];
        if ($this->route('book')) {
            $rules['isbn'] = ['required', 'digits:13', 'unique:books,isbn,' . $this->route('book')->id];
        }
        return $rules;
    }
    //
    public function messages(): array
    {
        return [
            'title.required' => 'タイトルは必須項目です。',
            'author.required' => '著者は必須項目です。',
            'isbn.required' => 'ISBN-13は必須項目です。',
            'isbn.unique' => 'このISBN-13は既に使用されています。',
            'published_date.date' => '出版日は日付形式（年/月/日）で入力してください。',
            'image_url.url' => '画像URLには正しいURL形式（http:// または https:// から始まる形式）で入力してください。',
            'genres.required' => 'ジャンルは1つ以上選択してください。',
        ];
    }

}
