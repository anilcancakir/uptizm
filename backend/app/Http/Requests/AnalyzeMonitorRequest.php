<?php

namespace App\Http\Requests;

use App\Enums\MonitorRegion;
use App\Http\Requests\Concerns\ValidatesAuthConfig;
use App\Support\Monitoring\CredentialRedactor;
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
 *
 * It also accepts an OPTIONAL `auth_config`, so a protected endpoint can be
 * analyzed at all. The credential is sent to the target on the probe and can
 * therefore come back in the target's own body, which is why the controller
 * runs {@see CredentialRedactor} over the probe result before anything reads
 * it (see {@see self::noEmbeddedCredential()} for what that does and does not
 * buy).
 */
class AnalyzeMonitorRequest extends FormRequest
{
    use ValidatesAuthConfig;

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
            // Optional here, unlike on create: an analyze of a public target
            // carries no credential at all, and `partial: true` is what makes
            // the key omissible rather than required-and-nullable.
            ...$this->authConfigRules(partial: true),
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
     * The validated credential map the probe should authenticate with, or null.
     *
     * Null covers both an omitted key and an explicit null, which is the same
     * thing to every consumer: {@see CredentialRedactor::for()} answers a no-op
     * redactor for it and the transient monitor carries no `auth_config`, so
     * the whole path behaves exactly as it did before credentials existed.
     *
     * @return array<string, mixed>|null
     */
    public function authConfig(): ?array
    {
        $authConfig = $this->validated('auth_config');

        return is_array($authConfig) ? $authConfig : null;
    }

    /**
     * Refuse a URL carrying its credential in the userinfo component.
     *
     * Laravel's `url` rule accepts `https://user:s3cr3t@example.com/health`
     * (measured), and this endpoint hands the URL to the analysis prompt as a
     * TRUSTED fact, on both the suggestion turn and the research turn that
     * holds the web-search tool. Refused rather than stripped: an operator who
     * pasted a credential should be told, not quietly have it removed and then
     * probed without it.
     *
     * The reason held to be "nothing secret is in the model's context" and that
     * is no longer true: `auth_config` above sends the operator's own
     * credential to the target, and a target that echoes its request headers
     * puts it in the probe body. What keeps it out of the two prompts is the
     * {@see CredentialRedactor} seam the controller runs over the probe result,
     * a control on the DATA path with a known residual (a value under eight
     * characters, a decoded or derived echo).
     *
     * The refusal here stands on a narrower and still-intact reason: a URL is
     * handed to the model as a TRUSTED fact, with no redaction seam in front of
     * it. `AnalysisPayload::displayUrl()` drops the query for exactly that
     * reason, and userinfo is the other half of the same hole. So the rule is
     * not redundant with the redactor; it covers the one inlet the redactor
     * never sees.
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
