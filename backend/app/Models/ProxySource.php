<?php

namespace App\Models;

use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The provider a region's proxy pool is fetched from, mirroring
 * `config/proxy.php`'s `sources` map (one row per configured region).
 *
 * `kind` is either `url` (fetched over HTTP on the `refresh_minutes`
 * cadence) or `file` (a static local list); `location` is the fetch target
 * or file path for that kind. `last_refreshed_at` and `last_error` are
 * written by a later step's refresh job so an operator panel can tell a
 * healthy source from one whose last fetch failed, without ever deleting
 * the source itself.
 *
 * `region` is unique: this design allows at most one source per region (see
 * the config file's docblock for why a second source would leave no rule
 * for which one to trust).
 *
 * @property string $id
 * @property string $region
 * @property string $kind
 * @property string $location
 * @property Carbon|null $last_refreshed_at
 * @property string|null $last_error
 */
class ProxySource extends Model
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
        'last_refreshed_at' => 'immutable_datetime',
    ];

    /**
     * The individual exits this source has upserted into the pool.
     *
     * @return HasMany<Proxy>
     */
    public function proxies(): HasMany
    {
        return $this->hasMany(Proxy::class);
    }
}
