<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWebAnalyticsPageViewRequest extends FormRequest
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
            'visitor_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'session_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'path' => ['required', 'string', 'max:512', 'regex:/^(?:\/|https?:\/\/)/i'],
            'page_title' => ['nullable', 'string', 'max:200'],
            'referrer' => ['nullable', 'string', 'url:http,https', 'max:1024'],
        ];
    }
}
