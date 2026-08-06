<?php

namespace App\Http\Requests;

use App\Enums\MonitorRegion;
use App\Support\Monitoring\HostGuard;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation rules for POST /monitors/analyze.
 *
 * The analyze endpoint probes a not-yet-created URL, so it carries the same
 * SSRF guard as {@see StoreMonitorRequest}: the target host is rejected when
 * it resolves to a loopback, RFC1918, link-local, IPv6 ULA, or reserved
 * `.internal` address, so a tenant can never turn the analyze probe into a
 * reach into the platform's own internal network. The host-resolution logic
 * lives in the shared {@see HostGuard} service; this request only wires it
 * onto the `url` field (see {@see self::noInternalHost()}).
 */
class AnalyzeMonitorRequest extends FormRequest
{
    /**
     * Shared, stateless SSRF host guard, memoized per request instance.
     */
    protected ?HostGuard $hostGuard = null;

    /**
     * Only an authenticated user acting on a team may analyze a target: the
     * per-team AI budget is spent against that team, so a team is mandatory.
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
            'url' => [
                'required',
                'string',
                'url',
                'max:2048',
                $this->noEmbeddedCredential(),
                $this->noInternalHost(),
            ],
            'region' => [
                'sometimes',
                'string',
                Rule::enum(MonitorRegion::class),
            ],
        ];
    }

    /**
     * The region the exploratory probe runs from, defaulting to US East when
     * the caller does not pin one.
     */
    public function probeRegion(): string
    {
        $region = $this->validated('region');

        return is_string($region) ? $region : MonitorRegion::USEast->value;
    }

    /**
     * Refuse a URL carrying its credential in the userinfo component.
     *
     * Laravel's `url` rule accepts `https://user:s3cr3t@example.com/health`
     * (measured), and this endpoint hands the URL to the analysis prompt as a
     * TRUSTED fact, on both the suggestion turn and the research turn that
     * holds the web-search tool. The whole reason a free-text search query is
     * safe here is that nothing secret is in the model's context, so a userinfo
     * URL is not an inconvenience, it is the one inlet that premise cannot
     * survive. Refused rather than stripped: an operator who pasted a
     * credential should be told, not quietly have it removed and then probed
     * without it.
     *
     * A monitor that genuinely needs credentials gets them through
     * `auth_config`, which is encrypted at rest and never reaches a prompt.
     *
     * @return Closure(string, mixed, Closure): void
     */
    protected function noEmbeddedCredential(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($this->hostGuard()->carriesCredentials((string) $value)) {
                $fail('The :attribute must not embed a username or password. Use the monitor\'s authentication settings instead.');
            }
        };
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
        return $this->hostGuard ??= new HostGuard;
    }
}
