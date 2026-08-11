<?php

namespace App\Http\Requests;

use App\Enums\DomainMode;
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
                // A slug doubles as a hostname label under
                // `status_pages.subdomain_host`, so a reserved word here would
                // claim a subdomain we serve ourselves. See config/status_pages.php.
                Rule::notIn(config('status_pages.reserved_slugs')),
                Rule::unique('status_pages', 'slug')->ignore($this->route('statusPage')),
            ],
            // See StoreStatusPageRequest: not `nullable`, the column is NOT NULL.
            'domain_mode' => [
                'sometimes',
                Rule::enum(DomainMode::class),
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
            // See StoreStatusPageRequest for what this decides. Constrained to the
            // locales this app ships a catalogue for: an unlisted code renders
            // dotted keys on a page a customer publishes.
            'locale' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in((array) config('magic-starter.supported_locales', [])),
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
