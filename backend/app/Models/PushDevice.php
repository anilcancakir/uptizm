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
     * Seven days, and the number is an on-call rotation rather than a round
     * one. A responder who has not had this app open once in a full rotation
     * period is somebody whose phone the server cannot vouch for: it may have
     * been wiped, reinstalled, or had its notification permission revoked, and
     * every one of those is silent from here. Treating a month-old `on` as
     * evidence is how a page goes nowhere and the ladder never moves.
     *
     * It is not shorter because it does not have to be: the client reports on
     * every launch, every sign-in and every permission or subscription change,
     * so a device in normal use refreshes far inside this window, and a window
     * that expired during an ordinary weekend would report a gap that is not
     * there.
     */
    public const int FRESH_FOR_HOURS = 168;

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
