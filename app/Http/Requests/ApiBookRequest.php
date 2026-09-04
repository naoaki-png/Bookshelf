<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'author' => ['required', 'string', 'max:255'],
            'isbn' => ['nullable', 'digits:13', 'unique:books,isbn'],
            'description' => ['nullable', 'string'],
            'published_date' => ['nullable', 'date'],
            'image_url' => ['nullable', 'string', 'url', 'max:255'],
            'genres' => ['required', 'array'],
            'genres.*' => ['exists:genres,id'],
        ];
        if ($this->route('book')) {
            $rules['isbn'] = ['nullable', 'digits:13', 'unique:books,isbn,' . $this->route('book')->id];
        }

        return $rules;
    }

    //
    public function messages(): array
    {
        return [
            'title.required' => 'タイトルは必須項目です。',
            'title.string' => 'タイトルは文字列で入力してください。',
            'title.max' => 'タイトルは255文字以内で入力してください。',
            'author.required' => '著者は必須項目です。',
            'author.string' => '著者は文字列で入力してください。',
            'author.max' => '著者は255文字以内で入力してください。',
            'description.string' => '説明は文字列で入力してください。',
            'isbn.digits' => 'ISBN-13は13桁の整数で入力してください。',
            'isbn.unique' => 'このISBN-13は既に使用されています。',
            'published_date.date' => '出版日は日付形式（年/月/日）で入力してください。',
            'image_url.url' => '画像URLには正しいURL形式（http:// または https:// から始まる形式）で入力してください。',
            'image_url.string' => '画像URLは文字列で入力してください。',
            'image_url.max' => '画像URLは255文字以内で入力してください。',
            'genres.required' => 'ジャンルは1つ以上選択してください。',
            'genres.array' => 'ジャンルは配列形式で送信してください。',
            'genres.*.exists' => '選択されたジャンルは存在しません。',
        ];
    }
}
