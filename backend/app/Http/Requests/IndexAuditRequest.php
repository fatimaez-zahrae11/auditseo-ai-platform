<?php

namespace App\Http\Requests;

use App\Models\Audit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAuditRequest extends FormRequest
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
            'search' => ['sometimes', 'string', 'max:500'],
            'status' => [
                'sometimes',
                Rule::in([
                    Audit::STATUS_PENDING,
                    Audit::STATUS_RUNNING,
                    Audit::STATUS_COMPLETED,
                    Audit::STATUS_FAILED,
                ]),
            ],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
