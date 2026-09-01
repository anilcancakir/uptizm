<?php

namespace App\Models;

use App\Services\OnCall\EscalationDispatcher;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One push subscription a person's client has reported the delivery state of.
 *
 * A "device" here is exactly one push subscription and nothing more: this table
 * holds no device name, no platform and no token, because the only question it
 * exists to answer is whether a page sent now would arrive, and none of those
 * help answer it. The shape is the client's
 * `PushDeliverySnapshot.toMap()` (`magic_notifications`), stored verbatim
 * beside the two facts the server adds: who posted it, and when it arrived.
 *
 * Read by {@see EscalationDispatcher} through {@see canReachByPush()}, which is
 * the only sanctioned way to ask: the four conditions it applies are what keep
 * a row from vouching for more than it knows.
 *
 * @property string $user_id
 * @property string|null $external_id
 * @property string|null $subscription_id
 * @property string $reachability
 */
class PushDevice extends Model
{
    use ConditionallyUsesUuids;

    /**
     * The one reachability value that means a push sent now would arrive.
     *
     * The vocabulary is the client's `PushReachability` enum, whose other three
     * cases (`unavailable`, `blocked`, `off`) all mean the same thing here:
     * not now. Only the positive is named, because only the positive is acted
     * on, and a server-side enum mirroring a Dart one would be a second
     * definition of a value this side never derives.
     */
    public const string REACHABLE = 'on';

    /**
     * Every value the client is allowed to report, in the client's own
     * vocabulary.
     *
     * @var array<int, string>
     */
    public const array REACHABILITY_VALUES = [
        'unavailable',
        'blocked',
        'off',
        self::REACHABLE,
    ];

    /**
     * How long a device's report speaks for it, in hours.
     *
     * One day, and the number is chosen by which of the two errors is the cheap
     * one rather than by what an on-call rotation happens to be.
     *
     * The FALSE POSITIVE, a window that expires while a device is genuinely
     * fine, costs one log line and no operational change: the delivery is
     * recorded but sent regardless, the ladder walks on to somebody it can
     * prove, and mail and the in-app row are untouched. An engineer whose
     * laptop was shut over a long weekend reports a gap that was not really
     * there, and nothing about that reaches a customer.
     *
     * The FALSE NEGATIVE costs the whole feature. A phone wiped, reinstalled,
     * or with its notification permission revoked while the app was closed
     * reports nothing at all and goes on vouching `on` for the length of this
     * window, and every rung that pages it is recorded as having reached
     * somebody. That is a page nobody hears on a product whose entire promise
     * is that somebody hears it, and it is invisible from here: no delivery
     * failure comes back, because OneSignal accepts a push for an unreachable
     * subscription without complaint.
     *
     * A day is comfortably long for a client that reports on every launch,
     * every sign-in, and every permission or subscription change, so a device
     * in daily use refreshes far inside it. This was seven days once, defended
     * by the weekend gap above, which is the cheap error tuned against.
     */
    public const int FRESH_FOR_HOURS = 24;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'external_id',
        'subscription_id',
        'reachability',
        'captured_at',
        'reported_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'captured_at' => 'datetime',
        'reported_at' => 'datetime',
    ];

    /**
     * The person whose client posted this report.
     *
     * @return BelongsTo<User, self>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether a push sent to [$user] right now would reach at least one of
     * their devices.
     *
     * ANY device, not every one: a responder carrying a phone that rings and
     * two browser tabs that do not is reachable, and an "all devices" rule
     * would skip past somebody holding the page in their hand.
     *
     * Four conditions, each closing a way a row could vouch for more than it
     * knows:
     *
     * 1. The device said `on`. Its three siblings all mean not now.
     * 2. It holds a subscription id. That is the address a push is delivered
     *    to, and a body claiming `on` without one is claiming something the
     *    client's own derivation cannot produce.
     * 3. It is subscribed as THIS person. A device still carrying the previous
     *    user of a shared phone receives nothing addressed to this one.
     * 4. It was heard from inside {@see FRESH_FOR_HOURS}, measured on the
     *    server's clock. See that constant for the horizon and why.
     *
     * The absence of any row reads as false, which is the safe direction: an
     * unproven device leaves the rung recorded as having reached nobody and the
     * ladder walks on to somebody it can prove, where the opposite would leave
     * an outage with a page that silently went nowhere.
     */
    public static function canReachByPush(User $user): bool
    {
        $externalId = self::externalIdFor($user);

        if ($externalId === null) {
            return false;
        }

        return self::query()
            ->where('user_id', $user->getKey())
            ->where('reachability', self::REACHABLE)
            ->where('external_id', $externalId)
            ->whereNotNull('subscription_id')
            ->where('reported_at', '>=', now()->subHours(self::FRESH_FOR_HOURS))
            ->exists();
    }

    /**
     * Stop one of [$user]'s devices vouching for them, because the person on it
     * signed out.
     *
     * ## Why a sign-out needs its own write path at all
     *
     * {@see FRESH_FOR_HOURS} bounds the false negatives it knows about, and every
     * one of them is a device that went SILENT: wiped, reinstalled, or silenced
     * while the app was closed. A sign-out is not in that class. It is an event
     * the client OBSERVES, on a live session, with a valid token still in hand,
     * and OneSignal stops delivering this person's pushes to that subscription
     * the moment the client detaches it. Without this call the row kept its
     * `on`, its `reported_at` and the previous person's alias, so
     * {@see canReachByPush()} answered true for a day and every rung whose only
     * outward channel is push was recorded as having woken somebody.
     *
     * ## The row is removed rather than blanked
     *
     * This table answers exactly one question, "would a push sent to this
     * person right now arrive at this device", and a device nobody is signed
     * into on their behalf makes no statement about it. A row kept with a
     * cleared alias would still carry a `reachability` and a `reported_at`, and
     * every future reader of those two columns would have to remember that a
     * third one silently disqualifies them. Removing it also makes the operation
     * unambiguously idempotent, which a retried sign-out needs.
     *
     * ## Per device, and per session
     *
     * Addressed by (this user, this subscription id), which is the same key
     * `store` writes under. Two properties fall out of that and both are
     * load-bearing: a caller can only release a row of their own, so knowing
     * somebody else's subscription id buys nothing; and the caller's OTHER
     * devices are untouched, so an operator signing out of a browser tab is
     * still reachable on the phone in their pocket. An "all my devices" release
     * would strand that responder for the rest of the window, which is the same
     * harm in the other direction.
     *
     * @param  User  $user  The person signing out, from the session.
     * @param  string  $subscriptionId  The device this sign-out happened on.
     */
    public static function release(User $user, string $subscriptionId): void
    {
        $subscriptionId = trim($subscriptionId);

        if ($subscriptionId === '') {
            return;
        }

        self::query()
            ->where('user_id', $user->getKey())
            ->where('subscription_id', $subscriptionId)
            ->delete();
    }

    /**
     * The OneSignal alias a push to [$user] is addressed to, or null when this
     * notifiable has none.
     *
     * Read back through `routeNotificationForOneSignal()` rather than composed
     * from a `user_` literal, because that method is what the notification
     * channel actually targets: an app that overrides the alias format would
     * otherwise have this comparison quietly disagree with its own deliveries.
     */
    public static function externalIdFor(User $user): ?string
    {
        if (! method_exists($user, 'routeNotificationForOneSignal')) {
            return null;
        }

        $aliases = $user->routeNotificationForOneSignal();
        $externalIds = $aliases['external_id'] ?? [];
        $first = is_array($externalIds) ? ($externalIds[0] ?? null) : null;

        return is_string($first) && trim($first) !== '' ? $first : null;
    }
}
