<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAdminIpIntelligenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'period' => ['sometimes', 'string', Rule::in(['24h', '7d', '30d'])],
            'risk' => ['sometimes', 'string', Rule::in(['critical', 'high', 'medium', 'low'])],
            'country' => ['sometimes', 'string', 'max:100'],
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
