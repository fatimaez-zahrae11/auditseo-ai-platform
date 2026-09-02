<?php

namespace App\Http\Requests\Admin;

use App\Models\ActionLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAdminActionLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role' => [
                'sometimes',
                'string',
                Rule::in(['all', ActionLog::ROLE_ADMIN, ActionLog::ROLE_USER, ActionLog::ROLE_SYSTEM]),
            ],
            'actor_user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'q' => ['sometimes', 'string', 'max:255'],
            'action' => ['sometimes', 'string', 'max:100'],
            'entity_type' => ['sometimes', 'string', 'max:100'],
            'status' => [
                'sometimes',
                'string',
                Rule::in([ActionLog::STATUS_SUCCESS, ActionLog::STATUS_FAILURE]),
            ],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
