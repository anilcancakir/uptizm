/**
 * The worker's bindings, in one place because two modules need them.
 *
 * `index.ts` reads them to route and to build the Sentry client;
 * `regional-probe.ts` needs the same shape as the type argument of
 * `DurableObject<Env>`, which is what `instrumentDurableObjectWithSentry`
 * matches its options callback against. Declaring the type twice would let the
 * two drift into a mismatch that only shows up as a confusing generic error.
 */
export type Env = {
    /**
     * The shared secret the origin signs each probe spec with.
     *
     * Provisioned out of band (`wrangler secret put RELAY_SECRET`, or
     * `.dev.vars` locally) and never written into `wrangler.toml`.
     */
    RELAY_SECRET: string;

    /**
     * The region-pinned Durable Object namespace the router dispatches into.
     */
    RegionalProbe: DurableObjectNamespace;

    /**
     * Where Sentry events go, or nowhere when it is absent.
     *
     * Optional on purpose: `wrangler dev` and the vitest pool both run without
     * it, and the SDK treats an undefined DSN as "disabled" rather than as an
     * error. `wrangler.toml` sets it for the deployed script.
     */
    SENTRY_DSN?: string;

    /**
     * The release this script belongs to, injected at deploy time so an event
     * can be tied to the commit that produced it.
     */
    SENTRY_RELEASE?: string;

    /**
     * The deployment environment an event is filed under. Defaults to
     * `production` when unset, since the deployed script is the only place
     * a DSN is configured.
     */
    SENTRY_ENVIRONMENT?: string;
};
