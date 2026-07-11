<?php

namespace App\Http\Requests;

use App\Enums\HttpAuthType;
use App\Enums\HttpMethod;
use App\Enums\MonitorRegion;
use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Support\Monitoring\HostGuard;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation rules for POST /monitors.
 *
 * Beyond the standard field validation, this request carries the SSRF
 * guard: the target URL is rejected when its host resolves to a loopback,
 * RFC1918, link-local, IPv6 ULA, or reserved `.internal` address, so a
 * tenant can never point a probe at the platform's own internal network.
 * The host-resolution logic lives in the shared {@see HostGuard} service;
 * this request only wires it onto the `url` field (see
 * {@see self::noInternalHost()}).
 */
class StoreMonitorRequest extends FormRequest
{
    /**
     * Shared, stateless SSRF host guard, memoized per request instance.
     */
    protected ?HostGuard $hostGuard = null;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->current_team_id !== null;
    }

    /**
     * Normalize the payload before validation.
     *
     * Drops an explicit `expected_status_code: null` so the request never
     * carries it into {@see Monitor::create()}: the column is
     * NOT NULL DEFAULT 200, so persisting a literal null would 500. Removing
     * the key lets the database default apply.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('expected_status_code') === null) {
            $this->getInputSource()->remove('expected_status_code');
        }
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
            'url' => [
                'required',
                'string',
                'url',
                'max:2048',
                $this->noInternalHost(),
            ],
            'type' => [
                'required',
                Rule::enum(MonitorType::class),
            ],
            'method' => [
                'required',
                Rule::enum(HttpMethod::class),
            ],
            'check_interval_sec' => [
                'required',
                'integer',
                'min:30',
                'max:86400',
            ],
            'timeout_sec' => [
                'required',
                'integer',
                'min:1',
                'max:120',
            ],
            'regions' => [
                'required',
                'array',
                'min:1',
            ],
            'regions.*' => [
                Rule::enum(MonitorRegion::class),
            ],
            'expected_status_code' => [
                'nullable',
                'integer',
                'min:100',
                'max:599',
            ],
            'request_headers' => [
                'nullable',
                'array',
            ],
            'request_body' => [
                'nullable',
                'string',
            ],
            'slo_target' => [
                'nullable',
                'numeric',
                'min:0',
                'max:100',
            ],
            'tags' => [
                'nullable',
                'array',
            ],
            'show_on_status_page' => [
                'boolean',
            ],
            'only_show_if_degraded' => [
                'boolean',
            ],
            'alert_on_down' => [
                'boolean',
            ],
            'alert_on_recover' => [
                'boolean',
            ],
            'ssl_tracking' => [
                'boolean',
            ],
            'ssl_alert_threshold_days' => [
                'nullable',
                'integer',
                'min:1',
                'max:365',
            ],
            ...$this->authConfigRules(partial: false),
        ];
    }

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
     * @param  bool  $partial  True for a partial (update) request.
     *
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
                'required_if:auth_config.type,basic',
            ],
            'auth_config.password' => [
                'nullable',
                'string',
                'required_if:auth_config.type,basic',
            ],
            'auth_config.token' => [
                'nullable',
                'string',
                'required_if:auth_config.type,bearer',
            ],
            'auth_config.key' => [
                'nullable',
                'string',
                'required_if:auth_config.type,api_key',
            ],
            'auth_config.header' => [
                'nullable',
                'string',
                'required_if:auth_config.type,api_key',
            ],
        ];
    }

    /**
     * Build the SSRF guard closure for the `url` field.
     *
     * Extracts the host from the URL, then delegates to {@see HostGuard} to
     * reject reserved names and hosts that resolve into a blocked range. A
     * URL with no parseable host fails outright.
     *
     * @return Closure(string, mixed, Closure): void
     */
    protected function noInternalHost(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $host = parse_url((string) $value, PHP_URL_HOST);

            if (! is_string($host) || $host === '') {
                $fail('The :attribute must contain a valid host.');

                return;
            }

            if ($this->hostGuard()->isBlockedHost($host)) {
                $fail('The :attribute host is not allowed.');
            }
        };
    }

    /**
     * Resolve the shared SSRF host guard, memoized for this request.
     */
    protected function hostGuard(): HostGuard
    {
        return $this->hostGuard ??= new HostGuard();
    }
}
