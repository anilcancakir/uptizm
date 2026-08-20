<?php

namespace App\Support;

use App\Http\Controllers\Api\V1\StatusPageLogoImageController;
use App\Models\StatusPage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Mints the signed URLs that stand in for authorisation on the unauthenticated
 * asset routes.
 *
 * One definition, because a status page's logo is now minted from two places
 * that must agree: the API resource the editor reads, and the assembled public
 * read model the Blade page renders. A second copy of the expiry rule would drift
 * silently, and the failure mode of drift here is an image that 403s in one
 * surface and not the other.
 */
final class SignedAssetUrl
{
    /**
     * Quantisation of every expiry handed out here, in seconds.
     */
    public const EXPIRY_BUCKET_SECONDS = 900;

    /**
     * Expiry quantised to the next-but-one bucket boundary.
     *
     * Rounding DOWN to the current boundary and then adding two buckets, rather
     * than rounding up to the next one, is deliberate: rounding up alone hands
     * out a URL with seconds of validity left to a caller that arrives at the end
     * of a bucket. The cost of the extra bucket is that a URL's real lifetime is
     * between one and two buckets (15 to 30 minutes) instead of exactly one, so
     * the leak window is up to the wider figure. That is the honest number for a
     * URL that must also stay stable.
     *
     * The public read model is cached for 60 seconds and holds a minted URL, so
     * the lower bound is what makes that safe: a URL served at the end of its
     * cache lifetime still has at least fourteen minutes left.
     */
    public static function expiresAt(): Carbon
    {
        $bucket = self::EXPIRY_BUCKET_SECONDS;

        return Carbon::createFromTimestamp(
            intdiv(Carbon::now()->getTimestamp(), $bucket) * $bucket + (2 * $bucket),
        );
    }

    /**
     * Signed URL for a status page's uploaded logo, or null when it has none.
     *
     * The URL must be STABLE while the logo is unchanged, or the brand mark
     * would visibly reload on every read, and it must CHANGE when the logo does,
     * or a replaced image would keep serving the previous bytes out of a cache
     * pointed at one unchanged URL. `updated_at` is the version: a logo write
     * always touches the row, and an unrelated edit costs one silent refetch of
     * an image already cached under a different key.
     */
    public static function forStatusPageLogo(StatusPage $page): ?string
    {
        $path = $page->logo_path;

        if (! is_string($path) || $path === '') {
            return null;
        }

        return URL::temporarySignedRoute(
            StatusPageLogoImageController::ROUTE_NAME,
            self::expiresAt(),
            [
                'statusPage' => $page->getKey(),
                'v' => $page->updated_at?->getTimestamp(),
            ],
        );
    }
}
