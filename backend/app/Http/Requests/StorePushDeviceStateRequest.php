<?php

namespace App\Http\Requests;

use App\Http\Controllers\Api\V1\PushDeviceController;
use App\Models\PushDevice;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation rules for POST /devices/push-state.
 *
 * The body is `PushDeliverySnapshot.toMap()` from `magic_notifications`,
 * unchanged: `external_id`, `subscription_id`, `reachability`, `captured_at`.
 * The package keeps its nulls rather than dropping the keys, because "this
 * device holds no subscription id" is the fact being reported and an absent key
 * is indistinguishable from a client too old to send it, so both nullable
 * fields are `present` rather than merely optional.
 *
 * ## The identity in the body is checked, never believed
 *
 * `external_id` is the alias the DEVICE reports carrying, so it is genuinely
 * the client's to state. What it may not do is name somebody else: the row is
 * always written under the session's own user
 * ({@see PushDeviceController}), and a body naming
 * another person's alias is refused here rather than silently reattributed. The
 * two halves are deliberate: reattributing would make a responder's own
 * reachability depend on a device they do not hold, and refusing alone would
 * still leave the write trusting the body.
 */
class StorePushDeviceStateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();
        $callerAlias = $user instanceof User ? PushDevice::externalIdFor($user) : null;

        return [
            'external_id' => [
                'present',
                'nullable',
                'string',
                'max:255',
                // A device subscribed as nobody passes on `nullable`: that is a
                // fresh install, and it is reportable. A device subscribed as
                // somebody else is not this caller's to report.
                Rule::in(array_filter([$callerAlias])),
            ],
            'subscription_id' => [
                'present',
                'nullable',
                'string',
                'max:255',
            ],
            'reachability' => [
                'required',
                'string',
                Rule::in(PushDevice::REACHABILITY_VALUES),
            ],
            'captured_at' => [
                'required',
                'date',
            ],
        ];
    }
}
