<?php

namespace App\Enums;

/**
 * Which rail granted the entitlement a subscriber currently holds.
 *
 * The vocabulary is deliberately neutral. Several payment rails carry the same
 * facts in their own dialects, and a wire that leaks one rail's words forces
 * every reader downstream to learn that dialect and then to relearn it when a
 * second rail arrives. A rail's own vocabulary therefore maps INTO this enum,
 * and the rail's raw word survives verbatim in a separate debug-only field
 * rather than in this one.
 *
 * This enum is the answer to "who is billing this subscriber", and nothing
 * more. It says nothing about WHEN a period ends or whether it renews; those
 * are their own fields, because a rail is not a lifecycle.
 */
enum BillingProvider: string
{
    /**
     * Nobody is billing. The value of a subscriber no rail has ever charged,
     * and also the safe landing place for a value this build does not know
     * (see {@see self::fromWire()}).
     */
    case None = 'none';

    /** The card rail, billed by us directly. */
    case Stripe = 'stripe';

    /**
     * The two mobile stores, which bill on our behalf and keep management of
     * the purchase inside their own account surface.
     */
    case AppStore = 'app_store';
    case PlayStore = 'play_store';

    /**
     * Granted by an operator rather than sold: a comp, a migration, a support
     * gesture. It entitles exactly like a paid rail and has no receipt.
     */
    case Manual = 'manual';

    /**
     * Resolve a provider from a raw wire or column value, defaulting to
     * {@see self::None} when the value is null or unrecognised.
     *
     * The fallback is what lets a newer backend ship a fourth rail without
     * crashing an older reader. It lands on `None` rather than on a real rail
     * on purpose: reading an unknown provider as an existing one would let
     * that rail's events act on a grant it never made.
     */
    public static function fromWire(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::None;
    }

    /**
     * True when a real rail stands behind the entitlement.
     *
     * `Manual` counts, because an operator-granted plan is as entitled as a
     * paid one. Only `None` means nothing is backing the plan at all.
     *
     * The `match` carries no `default` arm deliberately: a fifth rail added
     * later has to be decided here, and an unlisted case raises rather than
     * reading as a quiet `false`.
     */
    public function grants(): bool
    {
        return match ($this) {
            self::Stripe, self::AppStore, self::PlayStore, self::Manual => true,
            self::None => false,
        };
    }

    /**
     * True for the rails where the purchase is managed by the store rather
     * than by us.
     *
     * The caller that matters is the one deciding WHERE a customer manages a
     * subscription. A store-sold subscription can only be managed in that
     * store's own account surface, so pointing a store subscriber anywhere
     * else is both broken and a steering attempt the store forbids.
     */
    public function isStore(): bool
    {
        return match ($this) {
            self::AppStore, self::PlayStore => true,
            self::None, self::Stripe, self::Manual => false,
        };
    }
}
