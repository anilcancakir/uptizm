<?php

namespace App\Http\Requests\Concerns;

use App\Enums\HttpAuthType;
use App\Http\Requests\StoreMonitorRequest;
use App\Http\Requests\UpdateMonitorRequest;
use Illuminate\Validation\Rule;

/**
 * Inner-shape validation for the `auth_config` credential map.
 *
 * Shared by every request that accepts `auth_config`:
 * {@see StoreMonitorRequest} (via `use`) and
 * {@see UpdateMonitorRequest} (by inheriting from
 * `StoreMonitorRequest`). A `FormRequest` that cannot extend
 * `StoreMonitorRequest` without inheriting its twenty unrelated monitor
 * field rules pulls in this trait directly instead.
 */
trait ValidatesAuthConfig
{
    /**
     * Inner-shape rules for the `auth_config` credential map.
     *
     * The `type` selects the auth flow and each flow requires its own
     * secret fields: `basic` needs username + password, `bearer` a token,
     * `api_key` a key + header, and `none` nothing. These conditional rules
     * are identical on create and edit, so both requests share them; only
     * the top-level `auth_config` presence rule differs (create requires it
     * to be present-or-null, an edit may omit it entirely).
     *
     * Every credential field carries a `max:` bound: these values travel
     * inside the HMAC-signed relay spec sent to a third-party worker, and
     * were unbounded before this bound was added. `token` and `key` get
     * `max:2048` because a JWT is routinely over 1KB; `username`, `password`
     * and `header` get `max:255`, the conventional bound for a credential
     * field of that shape.
     *
     * @param  bool  $partial  True for a partial (update) request.
     * @return array<string, mixed>
     */
    protected function authConfigRules(bool $partial): array
    {
        return [
            'auth_config' => $partial
                ? ['sometimes', 'nullable', 'array']
                : ['nullable', 'array'],
            'auth_config.type' => [
                'required_with:auth_config',
                Rule::enum(HttpAuthType::class),
            ],
            'auth_config.username' => [
                'nullable',
                'string',
                'max:255',
                'required_if:auth_config.type,basic',
            ],
            'auth_config.password' => [
                'nullable',
                'string',
                'max:255',
                'required_if:auth_config.type,basic',
            ],
            'auth_config.token' => [
                'nullable',
                'string',
                'max:2048',
                'required_if:auth_config.type,bearer',
            ],
            'auth_config.key' => [
                'nullable',
                'string',
                'max:2048',
                'required_if:auth_config.type,api_key',
            ],
            'auth_config.header' => [
                'nullable',
                'string',
                'max:255',
                'required_if:auth_config.type,api_key',
            ],
        ];
    }
}
