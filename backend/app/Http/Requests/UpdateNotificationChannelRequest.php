<?php

namespace App\Http\Requests;

use App\Enums\NotificationChannelSeverity;
use App\Enums\NotificationChannelType;
use Illuminate\Validation\Rule;

/**
 * Validation rules for PATCH/PUT /notification-channels/{channel}.
 *
 * Mirrors {@see StoreNotificationChannelRequest} but makes every field optional
 * via `sometimes`, so a partial edit only validates the keys it sends. The
 * webhook-url SSRF guard is reused verbatim: it resolves the effective channel
 * type from the bound model when the payload omits `channel_type`, so a
 * credentials-only edit that changes the webhook url is still re-validated.
 */
class UpdateNotificationChannelRequest extends StoreNotificationChannelRequest
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
            'channel_type' => [
                'sometimes',
                'required',
                Rule::enum(NotificationChannelType::class),
            ],
            'credentials' => [
                'sometimes',
                'required',
                'array',
            ],
            'credentials.token' => [
                'nullable',
                'string',
                'max:255',
            ],
            'credentials.channel' => [
                'nullable',
                'string',
                'max:200',
            ],
            'credentials.routing_key' => [
                'nullable',
                'string',
                'max:64',
            ],
            'credentials.url' => [
                'nullable',
                'string',
                'max:2048',
                $this->webhookUrlRule(),
            ],
            'credentials.secret' => [
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
}
