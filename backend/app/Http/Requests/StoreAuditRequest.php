<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'url' => [
                'required',
                'url',
                'max:2048',
                'regex:/^https?:\/\//i',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'url.required' => 'L’URL du site à auditer est obligatoire.',
            'url.url' => 'L’URL fournie n’est pas valide.',
            'url.regex' => 'L’URL doit commencer par http:// ou https://.',
        ];
    }
}