/**
 * The consent banner's state: what the visitor chose, what that does to Consent Mode, and
 * where the answer is kept.
 *
 * WHAT THIS FILE IS NOT RESPONSIBLE FOR
 *
 * Denying every signal before Google Tag Manager loads. That is
 * `marketing/analytics.blade.php`, inline in the document head, and it has to be: this
 * module arrives through Vite as a deferred bundle, so anything in here runs AFTER
 * `gtm.js` has already fetched and run. Putting the `default` block here would be the
 * correct code in the wrong place, which is the one version of this bug that looks right
 * in review.
 *
 * WHY THE STORED CHOICE IS READ IN TWO PLACES
 *
 * The head script reads it to grant a returning visitor before the container boots; this
 * module reads it to decide whether to ask at all. Neither can call the other (see above),
 * so what keeps them addressing the same record is that BOTH take the key and the version
 * from `config/analytics.php`, handed in by Blade. Do not hardcode either here.
 *
 * WHY `localStorage` AND NOT A COOKIE
 *
 * Two reasons, and both are load-bearing. The server never reads this value, because GTM
 * is entirely client side, so a cookie would put a `Set-Cookie` (or a document.cookie
 * write) on a surface that publishes itself as storing nothing (`routes/marketing.php`,
 * `tests/Feature/Marketing/CookieTest.php`, and the Privacy page). And the record itself
 * is strictly necessary storage: GDPR Art. 7(1) requires the controller to be able to
 * demonstrate that consent was given, so keeping the answer needs no consent of its own,
 * which is why it is written for the "necessary only" answer too.
 */
export const consentChoice = ({ storageKey, version }) => ({
    /** Where the answer lives, and which question it answered. Both from config. */
    storageKey,
    version,

    /** Whether the banner is asking. Decided in init(), never rendered server-side. */
    open: false,

    /**
     * The only optional category. False here and `aria-checked="false"` in the markup:
     * a pre-ticked box is an assumption of consent, not consent.
     */
    analytics: false,

    init() {
        const choice = this.stored();

        /*
         * Ask only when there is no usable answer on this device. A stored answer against
         * an older `consent_version` does not count as one (see stored()), so bumping the
         * version re-asks everybody rather than carrying an answer to a question we have
         * since changed.
         */
        this.open = choice === null;
        this.analytics = choice?.analytics === true;

        /*
         * Opened here, synchronously, which means the banner ARRIVES WITHOUT A FADE on the
         * first paint. That is a deliberate trade and not an oversight: `x-show` skips the
         * transition on its first evaluation (Alpine wraps the toggle in `once()`), so
         * getting a fade on load would mean flipping this a tick later, and then `x-show`
         * only removes `display: none` two frames into the enter transition, by which point
         * the focus move below has already run against a hidden element and done nothing.
         * A visitor who cannot find the dialog is a worse outcome than a banner that does
         * not fade, so the transition classes serve the reopen and the dismissal, where the
         * element is already on screen and focus is not in play.
         */
        if (this.open) {
            this.focusIn();
        }
    },

    /**
     * The visitor answered. One path for both buttons and both outcomes.
     *
     * The `update` is not optional on the deny path: withdrawing has to actually reach
     * Consent Mode, or the page has recorded a refusal while the container carries on with
     * whatever state it already had. Rewriting the stored value alone would leave analytics
     * granted for the rest of the session, which is the difference between a withdrawal and
     * a note about one.
     */
    choose(analytics) {
        this.analytics = analytics;

        this.apply(analytics);
        this.persist(analytics);

        this.open = false;
    },

    /**
     * Closed without answering: Escape, and nothing else.
     *
     * Deliberately writes nothing and updates nothing. The default state is denied, so a
     * dismissal leaves the visitor un-measured and the banner asks again on the next page.
     * Escape, a scroll or continued browsing must never become an answer.
     */
    dismiss() {
        this.open = false;
    },

    /**
     * Reopened from the footer, which is the withdrawal path (Art. 7(3): as easy as
     * giving). The switch is re-seeded from what is actually stored, so the panel shows the
     * state being changed rather than the state it was last left in.
     */
    reopen() {
        this.analytics = this.stored()?.analytics === true;
        this.open = true;

        this.focusIn();
    },

    /**
     * Consent Mode, updated for real.
     *
     * `window.gtag` is the shim the head script defines, so it exists on any page that
     * carries the bootstrap. The guard covers the case where it does not (the bootstrap
     * withheld, a blocked inline script, an extension that removed it): then there is no
     * container to inform and no storage to gate, so there is nothing to do.
     */
    apply(granted) {
        if (typeof window.gtag !== 'function') {
            return;
        }

        window.gtag('consent', 'update', {
            analytics_storage: granted ? 'granted' : 'denied',
        });

        if (!granted) {
            this.forgetAnalyticsCookies();
        }
    },

    /**
     * Expire the analytics cookies that a previous acceptance allowed to be set.
     *
     * Consent Mode's `denied` stops Google reading and writing from here on, but it does not
     * remove what is already on the device, and `_ga` lives for two years. The privacy notice
     * tells the reader they can withdraw, so leaving the identifier behind would make that a
     * note about a withdrawal rather than one: the same visitor stays re-identifiable for two
     * years after saying no.
     *
     * Matched by PREFIX rather than by an exact list, because GA4 mints a per-stream
     * `_ga_<MEASUREMENT_ID>` whose suffix this code cannot know, and a container may add
     * `_gat` or `_gac_*` without anybody revisiting this file.
     *
     * Expired against the current host AND each parent domain, because GA sets its cookie on
     * the registrable domain (`.uptizm.com`) while `document.cookie` here defaults to the
     * exact host, and a delete that does not match the domain silently does nothing. The walk
     * stops at two labels, so it cannot reach a public suffix.
     */
    forgetAnalyticsCookies() {
        const prefixes = ['_ga', '_gid', '_gat', '_gac_'];
        const labels = window.location.hostname.split('.');
        const domains = [''];

        for (let i = 0; i <= labels.length - 2; i += 1) {
            domains.push(labels.slice(i).join('.'));
        }

        document.cookie.split(';').forEach((entry) => {
            const name = entry.split('=')[0].trim();

            if (name === '' || !prefixes.some((prefix) => name.startsWith(prefix))) {
                return;
            }

            domains.forEach((domain) => {
                const scope = domain === '' ? '' : `; domain=${domain}`;

                document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/${scope}`;
            });
        });
    },

    /**
     * The answer, plus the question it answered and when.
     *
     * `version` is what lets a later change to the categories invalidate this record;
     * `at` is the timestamp that makes it evidence rather than a preference.
     */
    persist(analytics) {
        const record = JSON.stringify({
            version: this.version,
            analytics,
            at: new Date().toISOString(),
        });

        try {
            window.localStorage.setItem(this.storageKey, record);
        } catch (error) {
            /*
             * Storage can be unreachable rather than full: Safari's private mode and a
             * "block all storage" setting both throw on access. Reported rather than
             * swallowed, and then carried on with deliberately: the choice still reached
             * Consent Mode for this page load (apply() ran first), and the banner asks again
             * next time because nothing was recorded. Which is the honest outcome, since we
             * cannot demonstrate a consent we were not allowed to store. Rethrowing would
             * leave the visitor clicking a button that visibly does nothing.
             */
            console.warn('Uptizm: the consent choice could not be recorded on this device.', error);
        }
    },

    /**
     * The stored answer, or null when there is none we may rely on.
     *
     * Null covers four cases on purpose: never asked, storage unreadable, record corrupt,
     * and an answer to an older set of categories. Every one of them means "no consent has
     * been demonstrated", and every one of them therefore has to ask again.
     */
    stored() {
        try {
            const raw = window.localStorage.getItem(this.storageKey);
            const choice = raw === null ? null : JSON.parse(raw);

            return choice !== null && choice.version === this.version ? choice : null;
        } catch (error) {
            // Unreadable is not consent. Fail closed, exactly as the head script does.
            return null;
        }
    },

    /**
     * Focus moves INTO the dialog when it opens, so a keyboard or screen-reader visitor is
     * put where the question is instead of having to hunt for it at the bottom of the
     * document. The container carries the label, so focusing it announces the dialog rather
     * than one of its buttons, and it does not nudge anybody towards either answer.
     *
     * Not a focus trap: the banner is non-modal, the page behind it stays readable, and
     * trapping focus in a bar at the bottom of a page nobody has read yet would be worse
     * than the problem it solves.
     */
    focusIn() {
        this.$nextTick(() => this.$refs.dialog?.focus());
    },
});
