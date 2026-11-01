<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssistantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'store_owner';
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:500'],
        ];
    }
}
