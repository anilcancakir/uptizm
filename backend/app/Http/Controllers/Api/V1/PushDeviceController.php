<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePushDeviceStateRequest;
use App\Models\PushDevice;
use App\Services\OnCall\EscalationDispatcher;
use Illuminate\Http\Response;

/**
 * Receives what a client's device knows about its own push delivery state, so
 * {@see EscalationDispatcher} can tell a responder whose phone rings from one
 * whose phone cannot.
 *
 * The permission, the opt-in flag and the subscription id live on the device
 * and nowhere else, and OneSignal accepts a push for an unreachable
 * subscription without complaint, so this report is the only evidence the
 * server will ever have.
 */
class PushDeviceController extends Controller
{
    /**
     * Record one device's push delivery state for the signed-in user.
     *
     * Upserted per device, keyed by the subscription id: one person may carry
     * several, and a single row per person would let a laptop that cannot be
     * paged overwrite the phone that can.
     *
     * `user_id` comes from the SESSION, never from the body. The body's
     * `external_id` describes the device and is validated against the caller's
     * own alias in {@see StorePushDeviceStateRequest}, so by the time it is
     * stored it can only be the caller's or nobody's.
     *
     * Answers 204: the client posts this as a side effect of a lifecycle event
     * and reads nothing back, so returning the row would be a payload nobody
     * consumes.
     */
    public function store(StorePushDeviceStateRequest $request): Response
    {
        $attributes = $request->validated();

        PushDevice::query()->updateOrCreate(
            [
                'user_id' => $request->user()->getKey(),
                // Normalised before it is keyed on: a blank string and a null
                // are the same fact (this device holds no address), and leaving
                // them distinct would give one device two rows depending on
                // which the client happened to send.
                'subscription_id' => self::blankToNull($attributes['subscription_id']),
            ],
            [
                'external_id' => self::blankToNull($attributes['external_id']),
                'reachability' => $attributes['reachability'],
                'captured_at' => $attributes['captured_at'],
                // The server's own clock, and the one freshness is measured on.
                // `captured_at` above is the device's claim about when it read
                // itself, which is worth keeping for diagnosis and worth
                // nothing as proof of age: a device with a wrong clock would
                // otherwise decide how long it stays trusted.
                'reported_at' => now(),
            ],
        );

        return response()->noContent();
    }

    /**
     * An empty string is the same absence a null is, and is stored as one.
     */
    private static function blankToNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
