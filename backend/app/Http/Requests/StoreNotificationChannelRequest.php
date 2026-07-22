<?php

namespace App\Http\Requests;

use App\Enums\NotificationChannelSeverity;
use App\Enums\NotificationChannelType;
use App\Models\NotificationChannel;
use App\Support\Monitoring\HostGuard;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validation rules for POST /notification-channels.
 *
 * The `credentials` map is shape-checked per `channel_type`: a Slack channel
 * needs a `token` (`channel` is optional, Slack posts to the app default when
 * absent), a webhook channel needs a `url` and a signing `secret`. The webhook
 * `url` runs through the same SSRF guard as a monitor target, only stricter:
 * {@see HostGuard::resolveAndAssertAllowed()} rejects a non-https scheme,
 * embedded credentials/ports, and any host that resolves into the internal
 * denylist, so a private/loopback/metadata URL is refused with a 422 and never
 * stored (see {@see self::webhookUrlRule()}).
 */
class StoreNotificationChannelRequest extends FormRequest
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
            'channel_type' => [
                'required',
                Rule::enum(NotificationChannelType::class),
            ],
            'credentials' => [
                'required',
                'array',
            ],
            'credentials.token' => [
                'required_if:channel_type,slack',
                'nullable',
                'string',
                'max:255',
            ],
            'credentials.channel' => [
                'nullable',
                'string',
                'max:200',
            ],
            'credentials.url' => [
                'required_if:channel_type,webhook',
                'nullable',
                'string',
                'max:2048',
                $this->webhookUrlRule(),
            ],
            'credentials.secret' => [
                'required_if:channel_type,webhook',
                'nullable',
                'string',
                'max:255',
            ],
            'is_enabled' => [
                'sometimes',
                'boolean',
            ],
            'severity' => [
                'sometimes',
                Rule::enum(NotificationChannelSeverity::class),
            ],
        ];
    }

    /**
     * Build the SSRF guard closure for the webhook `credentials.url` field.
     *
     * The check only runs for a webhook channel that actually carries a url;
     * a Slack channel (or an update that omits the url) skips it. The guard
     * throws a {@see ValidationException} keyed on `url`; its message is
     * surfaced on the nested `credentials.url` attribute so the client can
     * bind the error to the right field.
     *
     * @return Closure(string, mixed, Closure): void
     */
    protected function webhookUrlRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($this->effectiveChannelType() !== NotificationChannelType::Webhook->value) {
                return;
            }

            if (! is_string($value) || $value === '') {
                return;
            }

            try {
                $this->hostGuard()->resolveAndAssertAllowed($value);
            } catch (ValidationException $exception) {
                $fail((string) collect($exception->errors())->flatten()->first());
            }
        };
    }

    /**
     * Resolve the channel type this request should validate against.
     *
     * Prefers the submitted `channel_type`; on a partial edit that omits it,
     * falls back to the bound channel's current type so a credentials-only
     * update that changes the webhook url still runs the SSRF guard.
     */
    protected function effectiveChannelType(): ?string
    {
        $submitted = $this->input('channel_type');
        if (is_string($submitted) && $submitted !== '') {
            return $submitted;
        }

        $channel = $this->route('channel');

        return $channel instanceof NotificationChannel ? $channel->channel_type->value : null;
    }

    /**
     * Resolve the shared SSRF host guard, memoized for this request.
     */
    protected function hostGuard(): HostGuard
    {
        return $this->hostGuard ??= new HostGuard;
    }
}
