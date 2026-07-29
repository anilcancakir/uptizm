<?php

namespace App\Http\Requests;

use App\Enums\AiMode;
use App\Enums\HttpAuthType;
use App\Enums\HttpMethod;
use App\Enums\MonitorRegion;
use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Billing\PlanGate;
use App\Support\Monitoring\HostGuard;
use Closure;
use Illuminate\Contracts\Validation\Validator;
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
     * Enforce the team's plan caps after field validation: the monitor-count
     * quota (create only) and the fastest-check-interval floor (create and
     * update). Both surface as 422 errors with an upgrade-oriented message the
     * client shows verbatim, so a Free team cannot silently exceed its tier.
     *
     * The count guard is skipped on an update ({@see UpdateMonitorRequest}
     * inherits this) because editing an existing monitor does not add one; only
     * the interval floor still applies, so a paid-tier interval cannot survive
     * a downgrade-then-edit.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $team = Team::find($this->user()?->current_team_id);
            if ($team === null) {
                return;
            }

            $gate = new PlanGate;
            $isCreate = ! ($this->route('monitor') instanceof Monitor);

            if ($isCreate) {
                $limit = $gate->monitorLimit($team);
                if ($limit !== null && $gate->monitorsUsed($team) >= $limit) {
                    $validator->errors()->add(
                        'plan',
                        "Your {$gate->planLabel($team)} plan is limited to {$limit} monitors. Upgrade to add more.",
                    );
                }
            }

            $floor = $gate->minCheckIntervalSec($team);
            $interval = (int) $this->input('check_interval_sec');
            if ($interval > 0 && $interval < $floor) {
                $validator->errors()->add(
                    'check_interval_sec',
                    "Your {$gate->planLabel($team)} plan checks at most every {$floor}s. Upgrade for faster checks.",
                );
            }
        });
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
            'url' => $this->targetRules(partial: false),
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
            // Load-bearing, not cosmetic: SweepAiSuggestions scans the fleet with
            // whereIn('ai_mode', ['suggest','auto']) and TriageAnomalyCandidate
            // gates on AiMode::Suggest, so an unvalidated (and therefore dropped)
            // ai_mode leaves every monitor at the `off` default and the AI
            // suggestion pipeline never runs for it.
            'ai_mode' => [
                'sometimes',
                Rule::enum(AiMode::class),
            ],
            // Scoped to the acting team, never a bare exists: this column is what
            // EscalationDispatcher::resolvePolicy() reads to choose the paging
            // ladder, so a cross-tenant id here would page another team's
            // responders during an outage.
            'escalation_policy_id' => [
                'nullable',
                Rule::exists('escalation_policies', 'id')
                    ->where('team_id', $this->user()?->current_team_id),
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
     * Build the rules for the `url` target field, conditional on monitor type.
     *
     * The `url` column holds two shapes depending on `type`: an HTTP monitor
     * targets a full URL (`https://host/path`), a TCP monitor targets a bare
     * `host` or `host:port`. Validating both with the `url` rule would reject
     * every TCP target (a `host:port` is not a URL), so the rule set switches
     * on the effective type. Both branches carry an SSRF host guard so a tenant
     * can never point a probe at the platform's own internal network.
     *
     * @param  bool  $partial  True for a partial (update) request; prefixes
     *                         `sometimes` so an edit only validates the key it
     *                         sends.
     * @return array<int, mixed>
     */
    protected function targetRules(bool $partial): array
    {
        $rules = $partial ? ['sometimes', 'required'] : ['required'];
        $rules[] = 'string';
        $rules[] = 'max:2048';

        if ($this->effectiveType() === MonitorType::Tcp->value) {
            // host:port, no scheme, no path. A TCP check connects to a specific
            // port, so the port is required (a bare host has nothing to probe).
            // The SSRF guard does the real host extraction + range check.
            $rules[] = 'regex:/^[^\s\/:]+:\d{1,5}$/';
            $rules[] = $this->noInternalTcpHost();

            return $rules;
        }

        $rules[] = 'url';
        $rules[] = $this->noInternalHost();

        return $rules;
    }

    /**
     * Resolve the monitor type this request should validate the target against.
     *
     * Prefers the submitted `type`; on a partial edit that omits it, falls back
     * to the bound monitor's current type; defaults to HTTP when neither is
     * available (a create always sends `type`, so this only guards edits).
     */
    protected function effectiveType(): string
    {
        $submitted = $this->input('type');
        if (is_string($submitted) && $submitted !== '') {
            return $submitted;
        }

        $monitor = $this->route('monitor');
        if ($monitor instanceof Monitor) {
            return $monitor->type->value;
        }

        return MonitorType::Http->value;
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
     * Build the SSRF guard closure for a bare `host` / `host:port` TCP target.
     *
     * `parse_url` cannot parse a scheme-less `host:port` (it reads the host as
     * the scheme), so the host is split off the last colon by hand: the segment
     * after a trailing all-digit `:port` is the port (validated 1-65535), the
     * rest is the host. The host then goes through the same {@see HostGuard}
     * range check as the HTTP path.
     *
     * @return Closure(string, mixed, Closure): void
     */
    protected function noInternalTcpHost(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $target = (string) $value;
            $host = $target;

            $colon = strrpos($target, ':');
            if ($colon !== false) {
                $portPart = substr($target, $colon + 1);
                if ($portPart !== '' && ctype_digit($portPart)) {
                    $port = (int) $portPart;
                    if ($port < 1 || $port > 65535) {
                        $fail('The :attribute port must be between 1 and 65535.');

                        return;
                    }
                    $host = substr($target, 0, $colon);
                }
            }

            if ($host === '') {
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
        return $this->hostGuard ??= new HostGuard;
    }
}
