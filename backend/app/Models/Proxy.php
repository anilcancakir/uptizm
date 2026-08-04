<?php

namespace App\Models;

use DateTimeInterface;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single exit the local probe engine egresses through, upserted from its
 * owning {@see ProxySource}'s fetched list.
 *
 * `region` is denormalised from the source so region-scoped selection needs
 * no join; see {@see self::scopeHealthy()} for the exact predicate this
 * table's composite index (`region`, `enabled`, `available_at`) exists for.
 *
 * `credentials` holds the exit's username and password as a single
 * encrypted-at-rest array, following {@see Monitor::$casts}'s `auth_config`
 * cast: there is no separate plaintext `username`/`password` column,
 * because this table holds live provider passwords.
 *
 * `failed_attempts` + `available_at` implement exponential backoff: a proxy
 * that transport-fails is penalised by pushing `available_at` into the
 * future rather than being deleted, so it reanimates on its own once the
 * backoff elapses. `removed_at` is a DISTINCT, later-step sweep marker for
 * an exit that vanished from its source's list entirely; it is not
 * Eloquent's soft-delete column, this model does not use the `SoftDeletes`
 * trait, and `scopeHealthy()` checks it explicitly.
 *
 * @property string $id
 * @property string $proxy_source_id
 * @property string $region
 * @property string $host
 * @property int $port
 * @property array<string, string> $credentials
 * @property bool $enabled
 * @property int $failed_attempts
 * @property Carbon|null $available_at
 * @property Carbon $last_refreshed_at
 * @property Carbon|null $removed_at
 */
class Proxy extends Model
{
    use ConditionallyUsesUuids;

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'port' => 'integer',
        // Credentials are encrypted at rest (column is `text`); the cast reads
        // back the decrypted array transparently, mirroring Monitor::$casts's
        // `auth_config`.
        'credentials' => 'encrypted:array',
        'enabled' => 'boolean',
        'failed_attempts' => 'integer',
        'available_at' => 'immutable_datetime',
        'last_refreshed_at' => 'immutable_datetime',
        'removed_at' => 'immutable_datetime',
    ];

    /**
     * The source this proxy's exit list was fetched from.
     *
     * @return BelongsTo<ProxySource, self>
     */
    public function proxySource(): BelongsTo
    {
        return $this->belongsTo(ProxySource::class);
    }

    /**
     * Scope to the exits a check may actually select: enabled, not swept as
     * removed, and either never penalised or past its backoff window.
     *
     * The `available_at` predicate is grouped in its own closure: without
     * the grouping, `orWhere` would OR against the whole preceding chain
     * instead of just this one condition, and a disabled or removed proxy
     * whose `available_at` happens to be null would leak back into
     * selection.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeHealthy(Builder $query, ?DateTimeInterface $at = null): Builder
    {
        return $query
            ->where('enabled', true)
            ->whereNull('removed_at')
            ->where(function (Builder $query) use ($at): void {
                $query
                    ->whereNull('available_at')
                    ->orWhere('available_at', '<=', $at ?? now());
            });
    }

    /**
     * Scope to the exits denormalised for a given region.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRegion(Builder $query, string $region): Builder
    {
        return $query->where('region', $region);
    }
}
