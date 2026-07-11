<?php

namespace App\Http\Requests;

use App\Models\StatusPage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation rules for POST /status-pages.
 *
 * Mirrors {@see StoreMonitorRequest}'s authorization shape (an authenticated
 * user with a current team). `slug` is globally unique across all teams
 * because it addresses the public page on a shared path-based host
 * ({@see StatusPage::getRouteKeyName()}), so the uniqueness check
 * is not team-scoped.
 */
class StoreStatusPageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->current_team_id !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:200',
            ],
            'slug' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                Rule::unique('status_pages', 'slug'),
            ],
            'domain_mode' => [
                'nullable',
                'string',
                Rule::in([
                    'path',
                    'custom',
                ]),
            ],
            'custom_domain' => [
                'nullable',
                'string',
                'max:255',
            ],
            'brand_color' => [
                'nullable',
                'string',
                'max:9',
                'regex:/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/',
            ],
            'logo_path' => [
                'nullable',
                'string',
                'max:255',
            ],
            'logo_text' => [
                'nullable',
                'string',
                'max:8',
            ],
            'description' => [
                'nullable',
                'string',
                'max:500',
            ],
            'is_public' => [
                'boolean',
            ],
            'subscriptions_enabled' => [
                'boolean',
            ],
        ];
    }
}
