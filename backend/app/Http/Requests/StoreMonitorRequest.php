<?php

namespace App\Http\Requests;

use App\Enums\AiMode;
use App\Enums\HttpMethod;
use App\Enums\MonitorRegion;
use App\Enums\MonitorType;
use App\Http\Requests\Concerns\ValidatesAuthConfig;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Billing\PlanGate;
use App\Services\Monitoring\MetricCandidateExtractor;
use App\Support\Monitoring\HostGuard;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
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
 *
 * It also carries the optional bulk `metrics[]` the AI create flow submits with
 * the monitor. Those rows are validated by
 * {@see StoreMonitorMetricRequest::metricFieldRules()} under a `metrics.*.`
 * prefix, which is the same definition the single-metric endpoint enforces, and
 * they are written inside the monitor's own transaction by
 * {@see MonitorController::store()}.
 */
class StoreMonitorRequest extends FormRequest
{
    use ValidatesAuthConfig;

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
     * quota (create only), the fastest-check-interval floor, and the
     * regions-per-monitor allowance (both create and update). All three
     * surface as 422 errors with an upgrade-oriented message the client shows
     * verbatim, so a Free team cannot silently exceed its tier.
     *
     * The count guard is skipped on an update ({@see UpdateMonitorRequest}
     * inherits this) because editing an existing monitor does not add one.
     *
     * The region allowance AND the interval floor are enforced on the DELTA, not
     * on the payload: each refuses only when the submission is worse than both
     * the plan allowance and the value already stored on the monitor. The client posts the full field
     * map on every edit, so a payload-only gate would refuse a downgraded team
     * fixing a typo on a grandfathered multi-region monitor. Gating the delta
     * keeps that monitor editable at its stored count and still refuses any
     * increase; on create nothing is stored, so the allowance binds normally.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $team = Team::find($this->user()?->current_team_id);
            if ($team === null) {
                return;
            }

            $gate = new PlanGate;
            $monitor = $this->route('monitor');
            $isCreate = ! ($monitor instanceof Monitor);

            if ($isCreate) {
                $limit = $gate->monitorLimit($team);
                if ($limit !== null && $gate->monitorsUsed($team) >= $limit) {
                    $validator->errors()->add(
                        'plan',
                        "Your {$gate->planLabel($team)} plan is limited to {$limit} monitors. Upgrade to add more.",
                    );
                }
            }

            // Gated on the DELTA, for the same reason the region allowance below
            // is: the client posts the full field map on every edit, so a
            // payload-only gate refused a downgraded team RENAMING a grandfathered
            // monitor, with an error about a field they never touched. Refusing
            // only a submission faster than both the floor and the stored value
            // keeps the monitor editable and still refuses any speed-up.
            //
            // This replaces an explicit decision the other way ("only the interval
            // floor still applies, so a paid-tier interval cannot survive a
            // downgrade-then-edit"), because the premise does not hold: nothing
            // clamps the interval where the scheduler arms `next_check_at`, so the
            // paid cadence survives the downgrade regardless. Measured on a 30s
            // monitor under a 180s Free floor, consecutive checks landed 31s, 59s,
            // 32s, 58s and 59s apart. The payload gate never clawed anything back;
            // it only blocked edits. Enforcing the floor at SCHEDULE time is the
            // fix for that half, and it changes a paying customer's monitoring
            // cadence, so it is a maintainer's call and not this one.
            $floor = $gate->minCheckIntervalSec($team);
            $interval = (int) $this->input('check_interval_sec');
            $storedInterval = $isCreate ? null : (int) $monitor->check_interval_sec;
            $exceedsStored = $storedInterval === null || $interval < $storedInterval;

            if ($interval > 0 && $interval < $floor && $exceedsStored) {
                $validator->errors()->add(
                    'check_interval_sec',
                    "Your {$gate->planLabel($team)} plan checks at most every {$floor}s. Upgrade for faster checks.",
                );
            }

            // `regions` is attacker-controlled and reaches this callback even
            // when the `array` rule already rejected it, so a non-array counts
            // as zero rather than being counted: counting a scalar raises a
            // TypeError here, which answers 500 on an authenticated endpoint.
            $submitted = $this->input('regions');
            $submittedCount = is_array($submitted) ? count($submitted) : 0;
            $stored = ($isCreate || ! is_array($monitor->regions)) ? [] : $monitor->regions;
            $allowance = $gate->maxRegionsPerMonitor($team);

            if ($submittedCount > $allowance && $submittedCount > count($stored)) {
                $noun = Str::plural('region', $allowance);
                $validator->errors()->add(
                    'regions',
                    "Your {$gate->planLabel($team)} plan checks from at most {$allowance} {$noun} per monitor. Upgrade to add more.",
                );
            }

            // The bulk metric rows carry the same three cross-field checks
            // the single-metric endpoint enforces (see
            // StoreMonitorMetricRequest::withValidator()), so an inverted
            // warn/critical pair, an overlapping band value or an unmatched
            // band with no list is refused here exactly as it is there.
            //
            // Gated on `rules()` declaring `metrics`, not merely on the input
            // being an array: `UpdateMonitorRequest` overrides `rules()` and
            // never declares `metrics` there, so this loop is inherited but
            // permanently dormant on a PUT, even one carrying a stray
            // `metrics[]` the update endpoint never reads. On `POST /monitors`
            // the bare `metrics` key in `rules()` below is what arms it, and
            // dropping that key while keeping the `metrics.*` rules would
            // disarm all three checks without a single field rule changing.
            if (array_key_exists('metrics', $this->rules())) {
                $metrics = $this->input('metrics');

                if (is_array($metrics)) {
                    foreach ($metrics as $index => $row) {
                        if (! is_array($row)) {
                            continue;
                        }

                        StoreMonitorMetricRequest::validateMetricRowCrossFields(
                            $validator,
                            $row,
                            "metrics.{$index}.",
                        );
                    }
                }
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
            // A separate consent from `ai_mode`, deliberately: that one answers
            // "may you decide there is an incident?", this one answers "may you
            // speak to my customers about one?". A dropped value here leaves the
            // monitor at the `false` default and PublishAiIncidentUpdate never
            // posts for it, which is the safe direction for a field whose true
            // value publishes to a public page.
            'ai_auto_updates' => [
                'sometimes',
                'boolean',
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
            // Whether a 3xx is the answer or a step on the way to it. Neither is
            // right for every monitor: a login page answering 302 instead of 200
            // is a regression, and a homepage behind a geo redirect is working.
            // Opt-in, so no existing monitor changes what it measures.
            'follow_redirects' => [
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
            // The bulk metric rows, written with the monitor in one
            // transaction by MonitorController::store(). The BARE key is
            // load-bearing beyond the `array` type and the cap: it is what
            // self::withValidator() gates its per-row cross-field loop on, so
            // declaring only the `metrics.*` rules leaves an inverted
            // warn/critical pair, an overlapping band value and an unmatched
            // band with no list all unrefused on this path while every field
            // rule still fires. Measured, not assumed: with this key removed
            // the rows still validate, still reach `validated()` and still
            // persist, and only the three cross-field checks go quiet.
            //
            // Capped at the number of candidates discovery can ever propose,
            // because that is the only producer of a bulk row today and no
            // plan tier gates metric COUNT (`backend/config/plans.php`).
            'metrics' => [
                'sometimes',
                'array',
                'max:'.MetricCandidateExtractor::MAX_CANDIDATES,
            ],
            // Reached as `metrics.*.<field>` from the ONE definition of what a
            // metric may contain, prefix and all, rather than a second copy:
            // a rule that drifts between two copies governs a persisted metric
            // on one path and not the other. `rules()` itself is unusable here,
            // route-bound through its `key` uniqueness, so the static
            // route-free half is what this composes.
            ...StoreMonitorMetricRequest::metricFieldRules('metrics.*.'),
            ...$this->authConfigRules(partial: false),
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
