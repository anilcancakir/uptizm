<?php

namespace App\Notifications\Channels;

use Illuminate\Http\Client\Response;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * What one team-channel `send()` attempt amounted to, in a shape safe to hand
 * to a listener.
 *
 * The four outbound channels used to return `void`, which left
 * {@see NotificationSent::$response} null and made a success
 * indistinguishable from a reported failure to anything downstream. This is
 * that missing answer, and it is deliberately NOT the
 * {@see Response} itself: the event travels to every registered listener, and a
 * live HTTP response carries the request it was made from, headers included,
 * which is how a signing secret or a bot token ends up somewhere nobody
 * intended.
 *
 * So the result carries four scalars and nothing else. Never the target URL,
 * the credential, the request body or the raw response body.
 *
 * `outcome` is a two-value vocabulary held as constants rather than an enum
 * because the enum's home would be `app/Enums`, and the column it is written
 * into (`notification_deliveries.outcome`) is what makes the pair closed.
 */
readonly class ChannelDeliveryResult
{
    /**
     * The provider accepted the send.
     */
    public const string OUTCOME_DELIVERED = 'delivered';

    /**
     * Nothing was delivered: a refused target, a missing credential, a non-2xx
     * answer, or a vendor body reporting its own failure.
     */
    public const string OUTCOME_FAILED = 'failed';

    /**
     * Longest vendor code kept, matching the `error_code` column's width.
     */
    public const int MAX_ERROR_CODE_LENGTH = 64;

    /**
     * @param  string  $outcome  One of the two OUTCOME_* constants.
     * @param  int|null  $statusCode  The HTTP status, when a response existed.
     * @param  string|null  $errorCode  An allowlisted vendor code, or null.
     * @param  class-string|null  $exceptionClass  The class of a caught exception.
     */
    private function __construct(
        public string $outcome,
        public ?int $statusCode,
        public ?string $errorCode,
        public ?string $exceptionClass,
    ) {}

    /**
     * The provider accepted the send.
     */
    public static function delivered(?int $statusCode = null): self
    {
        return new self(self::OUTCOME_DELIVERED, $statusCode, null, null);
    }

    /**
     * Nothing was delivered, for the reason the arguments describe.
     *
     * @param  int|null  $statusCode  Null when no response was ever received.
     * @param  string|null  $errorCode  A vendor code, filtered before it is kept.
     * @param  class-string|null  $exceptionClass  The class of a caught exception.
     */
    public static function failed(
        ?int $statusCode = null,
        ?string $errorCode = null,
        ?string $exceptionClass = null,
    ): self {
        return new self(
            self::OUTCOME_FAILED,
            $statusCode,
            self::allowlistedErrorCode($errorCode),
            $exceptionClass,
        );
    }

    /**
     * Keep a vendor code only when it looks like a code.
     *
     * The allowlist has two halves and needs both. The caller reads one NAMED
     * vendor field (Slack's `error`, PagerDuty's `message`), never the raw
     * body; this filter then bounds what that field is allowed to be. Letters,
     * digits, spaces, `_`, `.` and `-`, up to the column's width: a URL, a
     * `?sig=` query, an HTML error page and a JSON dump all carry a character
     * outside that set (`:` `/` `?` `=` `{` `"` or a newline) and become null
     * rather than being truncated, because half a leaked value is still a
     * leaked value.
     *
     * What it does not cover, and cannot: a provider that echoes OUR
     * credential back inside its own error code would pass the shape. Neither
     * Slack nor PagerDuty does, and no shape test could tell such an echo from
     * a legitimate code.
     */
    private static function allowlistedErrorCode(?string $errorCode): ?string
    {
        if ($errorCode === null) {
            return null;
        }

        $code = trim($errorCode);

        return preg_match('/^[A-Za-z0-9 ._-]{1,'.self::MAX_ERROR_CODE_LENGTH.'}$/', $code) === 1
            ? $code
            : null;
    }
}
