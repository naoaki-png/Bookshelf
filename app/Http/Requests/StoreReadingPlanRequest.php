<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReadingPlanRequest extends FormRequest
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
            'book_id' => ['required', 'exists:books,id', Rule::unique('reading_plans')->where('user_id', $this->user()->id)],
            'target_date' => ['required', 'date', 'after_or_equal:today'],
        ];
    }
    public function messages(): array
    {
        return [
            'book_id.required' => '読書計画を立てる書籍を選択してください。',
            'book_id.unique' => 'この書籍はすでに読書計画に追加されています。',
            'book_id.exists' => '選択された書籍は存在しないか、すでに削除されています。',
            'target_date.required' => '期日を入力してください。',
            'target_date.date' => '期日は有効な日付を入力してください。',
            'target_date.after_or_equal' => '期日は今日以降の日付を入力してください。',
        ];
    }
}
