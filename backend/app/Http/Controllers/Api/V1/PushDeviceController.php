<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePushDeviceStateRequest;
use App\Models\PushDevice;
use App\Models\User;
use App\Services\OnCall\EscalationDispatcher;
use Illuminate\Http\Request;
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
     * Stop the caller's device vouching for them, because the person on it is
     * signing out.
     *
     * ## Why this is a verb of its own and not another report
     *
     * A report describes a READING the device took: the permission, the opt-in
     * flag and the subscription id, as `PushDeliverySnapshot` found them. A
     * sign-out is not a reading, it is an EVENT, and the only way to express it
     * as a report would be for the client to describe a device state it has not
     * observed yet, taken from an SDK that is mid-logout. That is precisely the
     * kind of claim this table exists because the server cannot check. So the
     * client states the fact it actually holds ("the person on this device
     * signed out"), and the server decides what that means for the row, which
     * is where the vouching rule already lives ({@see PushDevice::release()}).
     *
     * ## Authorisation, and the one thing it must not allow
     *
     * `user_id` comes from the SESSION, exactly as it does in {@see store()}, and
     * the body carries the device and nothing else. A subscription id is not a
     * secret (it travels in every report, and two people share a handset), so if
     * a release were keyed on the id alone, knowing one would be enough to make
     * another team's on-call responder unreachable for a day.
     *
     * `subscription_id` is REQUIRED rather than nullable, unlike the report's
     * copy of the same field: a row with no subscription id can never vouch for
     * anybody, so a body without one names no device this endpoint could act on,
     * and the alternative reading of it ("release whatever I have") is the
     * all-devices release that would strand a responder's other handset.
     *
     * Validated inline rather than through a FormRequest, which is this app's
     * pattern everywhere else: one required field, and the reasoning above is
     * about authorisation rather than shape.
     *
     * Answers 204 like {@see store()}, and for the same reason. Also 204 when
     * there was nothing to remove: a sign-out can be retried, and a client that
     * cannot tell a timeout from a refusal must be able to say this twice.
     */
    public function release(Request $request): Response
    {
        $validated = $request->validate([
            'subscription_id' => [
                'required',
                'string',
                'max:255',
            ],
        ]);

        /** @var User $user */
        $user = $request->user();

        PushDevice::release($user, $validated['subscription_id']);

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
