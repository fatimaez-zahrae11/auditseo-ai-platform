<?php

namespace App\Http\Requests;

use App\Security\PublicUrlPolicy;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

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
                'bail',
                'required',
                'url',
                'max:2048',
                'regex:/^https?:\/\//i',
                $this->publicHttpUrlRule(),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'url.required' => 'The website URL is required.',
            'url.url' => 'The website URL is not valid.',
            'url.max' => 'The website URL may not be longer than 2048 characters.',
            'url.regex' => 'The website URL must begin with http:// or https://.',
        ];
    }

    private function publicHttpUrlRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            try {
                app(PublicUrlPolicy::class)->validate((string) $value);
            } catch (ValidationException) {
                $fail(PublicUrlPolicy::VALIDATION_MESSAGE);
            }
        };
    }
}
