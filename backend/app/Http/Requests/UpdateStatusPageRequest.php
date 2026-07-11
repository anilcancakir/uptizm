<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Validation rules for PATCH/PUT /status-pages/{statusPage}.
 *
 * Mirrors {@see StoreStatusPageRequest} but makes every field optional via
 * `sometimes`, so a partial edit only validates the keys it sends. The
 * `slug` uniqueness check ignores the routed page itself so re-saving a
 * page's own unchanged slug never 422s.
 */
class UpdateStatusPageRequest extends StoreStatusPageRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:200',
            ],
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                Rule::unique('status_pages', 'slug')->ignore($this->route('statusPage')),
            ],
            'domain_mode' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in([
                    'path',
                    'custom',
                ]),
            ],
            'custom_domain' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],
            'brand_color' => [
                'sometimes',
                'nullable',
                'string',
                'max:9',
                'regex:/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/',
            ],
            'logo_path' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],
            'logo_text' => [
                'sometimes',
                'nullable',
                'string',
                'max:8',
            ],
            'description' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
            ],
            'is_public' => [
                'sometimes',
                'boolean',
            ],
            'subscriptions_enabled' => [
                'sometimes',
                'boolean',
            ],
        ];
    }
}
