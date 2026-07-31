/**
 * The hero sequence: one incident, told in four acts, on a loop.
 *
 * Modelled on fluttersdk.com's hero workflow: an async sequence with explicit
 * `await delay(ms)` beats driving an `act` variable, whole stages swapped inside one
 * persistent frame, and text typed a character at a time so it reads as someone using
 * the product rather than a diagram of it.
 *
 * The four acts are the product's actual pipeline, which is why they carry four
 * separate claims without the hero feeling like four adverts stacked up:
 *
 *   1  setup     you point it at a URL and pick regions
 *   2  fan-out   every region is checked AT THE SAME MOMENT, results land in
 *                latency order
 *   3  triage    a metric out of the response body crosses its bound, and the AI
 *                drafts a cause from evidence it is allowed to cite
 *   4  delivery  it reaches whoever is on call, on whatever they are holding
 *
 * Honesty boundary: everything on this stage is an illustration against the
 * placeholder host `api.acme.com`. It reports on nothing. Every real claim on the
 * page is derived server-side in ShowLandingController.
 */

/** Region rows for act 2, with the data centre each one really came back from during
 *  live verification, so the geography is true even though the latency is an example. */
const REGIONS = [
    { label: 'EU West', colo: 'CDG', ms: 12 },
    { label: 'EU Central', colo: 'FRA', ms: 18 },
    { label: 'US East', colo: 'MIA', ms: 36 },
    { label: 'US West', colo: 'DFW', ms: 52 },
    { label: 'Asia-Pacific', colo: 'HKG', ms: 88 },
];

export const heroSequence = (channels = [], labels = {}, beats = {}) => ({
    /** Alert destinations, handed in from the server so they stay derived. */
    channels,

    /*
     * Act names, also handed in from the server, and for the same reason twice over:
     * a string literal in this file is derived from nothing, and it never reaches
     * Laravel's translator. Hardcoded here, the stage relabelled itself in English a
     * second after a Turkish page finished loading.
     *
     * Keyed by act, `1.5` included, which is why the server sends string keys.
     */
    labels,

    /*
     * The copy beside the stage, one beat per act: `{ lead, accent, line }`. Same
     * reasoning as the labels, plus one more that matters more: each beat is a product
     * CLAIM, so it belongs where the other claims are derived and can be checked
     * against the code, not in a JS literal nobody audits.
     */
    beats,

    /*
     * The beat the copy column is currently DISPLAYING, which deliberately lags `act`.
     *
     * The swap cannot be driven straight off `act`, because then the words change while
     * they are fully visible and it reads as a snap. So the fade drives the swap: the
     * text is replaced at the invisible midpoint and `act` only requests the change.
     *
     * Seeded with act 1's beat so the very first `act = 1` is not treated as a change
     * and the page does not fade in over itself a beat after loading.
     */
    shownBeat: beats['1'] ?? null,
    copyFading: false,
    copyToken: 0,

    /** 1, 1.5, 2, 3, 4 — halves are transitions. */
    act: 0,

    /*
     * Act 1. Seeded with the finished value rather than empty, and the Blade renders
     * the same string, so the example host is in the server HTML: without it a visitor
     * with no JavaScript, or a crawler, saw an endpoint field with nothing in it. Act 1
     * clears and retypes it when the sequence starts.
     */
    url: 'https://api.acme.com/health',
    pickedRegions: 0,
    submitting: false,

    /** Act 2: one entry per region, filled as results land. */
    results: [],

    /** Act 3 */
    metricValue: 0,
    metricBreached: false,
    showAi: false,

    /*
     * Act 4: the escalation ladder, filled a row at a time.
     *
     * Channel names arrive from the server (derived from NotificationChannelType) so
     * this file cannot name a destination the product cannot deliver to.
     */
    escalation: [],
    waiting: false,
    resolvedAt: null,

    verdict: 'up',
    running: false,

    /*
     * Generation token. Every new sequence bumps it, and anything already in flight
     * (a delay, a typing interval) checks it and bails when it is stale.
     *
     * Without this, jumping to an act while the loop was mid-typing left the old
     * `setInterval` still appending characters to the same property the jump had just
     * reset, and the field rendered the URL twice, spliced together. Any visitor
     * clicking a tab at the wrong moment hit it.
     */
    gen: 0,
    restartTimer: null,

    init() {
        // Crossfade the copy whenever the act asks for a different beat. A watcher
        // rather than a call at each `this.act = N`, of which there are seven.
        this.$watch('act', (value) => this.crossfade(value));

        /*
         * ONE GATE: is the stage on screen. An earlier build also required the tab to
         * be visible and that created a dead state, because a page opened in a
         * background tab starts hidden and IntersectionObserver does not fire while
         * hidden, so nothing ever started it. Browsers already throttle background
         * timers to about once a minute.
         */
        new IntersectionObserver(
            (entries) =>
                entries.forEach((entry) => {
                    this.running = entry.isIntersecting;

                    if (this.running && this.act === 0) {
                        this.play();
                    }
                }),
            { threshold: 0.15 },
        ).observe(this.$el);
    },

    /**
     * Reduced motion removes the MOVEMENT, not the sequence.
     *
     * The first version parked on act 3 and re-entered it every seven seconds, so a
     * visitor with the preference set saw a stage that never animated and yet changed
     * on its own: "no movement, it just switches tabs". Which is exactly what it did.
     *
     * The acts still advance, the values still change, the states still recolour. What
     * goes is the typing, the transition pulse, the slide between acts and the caret.
     * Vestibular safety is about movement; a number arriving in place is information,
     * and withholding it leaves someone looking at a screenshot of a monitoring
     * product with no way to tell it monitors anything.
     */
    /** What the copy shows right now. Falls back to act 1's beat, which is the one
     *  the server renders, so the bindings never resolve to undefined. */
    get beat() {
        return this.shownBeat ?? this.beats['1'] ?? { lead: '', accent: '', line: '' };
    },

    /**
     * Fade the copy out, swap the words while they cannot be seen, fade back in.
     *
     * Two beats are shared between acts (1.5 and 2 carry the same one, because the
     * transition is too short to read a sentence in), and comparing the resolved beats
     * rather than the act numbers is what stops that handover from flickering for no
     * reason.
     *
     * The fade runs under reduced motion too, and that is deliberate rather than an
     * oversight. A cross-fade is not what the preference exists to suppress; travel across
     * the screen is, and there is none here. It also has to match the rest of the page:
     * every section below the hero now cross-fades in under reduce, so a hard swap here
     * would be the one jarring thing on an otherwise calm page.
     *
     * What stays off under reduce is the typing and the transition pulse, which are
     * movement.
     */
    async crossfade(act) {
        const next = this.beats[act] ?? this.beats['1'];

        if (!next || next === this.shownBeat) {
            return;
        }

        const token = ++this.copyToken;

        this.copyFading = true;
        await this.delay(190);

        // A tab click can request another beat mid-fade; the newer one owns the slot.
        if (this.copyToken !== token) {
            return;
        }

        this.shownBeat = next;
        this.copyFading = false;
    },

    get reduced() {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    },

    async play() {
        const g = ++this.gen;
        const alive = () => this.gen === g;

        while (alive()) {
            await this.act1(alive);
            if (!alive()) return;

            // The transition act is pure motion: a pulse standing in for dispatch. It
            // carries no information, so it is the one act that is skipped outright.
            if (!this.reduced) {
                await this.act1Transition();
                if (!alive()) return;
            }

            await this.act2(alive);
            if (!alive()) return;

            await this.act3(alive);
            if (!alive()) return;

            await this.act4(alive);
        }
    },

    /** Jump straight to an act, fully resolved. Used by the tabs and by reduced
     *  motion, so no state is ever half-built. */
    showAct(n) {
        // Kill whatever is in flight, then resume the loop after a dwell so a click
        // never leaves the stage frozen, and never yanks the chosen act away either.
        this.gen += 1;
        window.clearTimeout(this.restartTimer);
        this.restartTimer = window.setTimeout(() => this.play(), 9000);

        this.act = n;
        this.submitting = false;

        if (n === 1) {
            this.url = 'https://api.acme.com/health';
            this.pickedRegions = REGIONS.length;
        }

        if (n >= 2) {
            this.results = REGIONS.map((r) => ({ ...r, state: 'up', shown: r.ms }));
        }

        if (n === 3) {
            this.metricValue = 4812;
            this.metricBreached = true;
            this.showAi = true;
            this.results = REGIONS.map((r, i) => ({
                ...r,
                state: i >= 3 ? 'degraded' : 'up',
                shown: i >= 3 ? r.ms * 9 : r.ms,
            }));
            this.verdict = 'degraded';
        } else {
            this.metricBreached = false;
            this.showAi = false;
            this.verdict = 'up';
        }

        if (n === 4) {
            this.escalation = this.ladder();
            this.waiting = true;
            this.resolvedAt = '5m 12s';
        } else {
            this.escalation = [];
            this.waiting = false;
            this.resolvedAt = null;
        }
    },

    async act1(alive = () => true) {
        this.act = 1;
        this.url = '';
        this.pickedRegions = 0;
        this.results = [];
        this.showAi = false;
        this.metricBreached = false;
        this.verdict = 'up';
        await this.delay(500);

        await this.type('url', 'https://api.acme.com/health', 45, alive);
        await this.delay(400);

        // Regions get picked one at a time, which is the moment the page earns the
        // phrase "checked from everywhere at once" that act 2 then shows.
        for (let i = 1; i <= REGIONS.length && alive(); i++) {
            this.pickedRegions = i;
            await this.delay(140);
        }

        await this.delay(700);
        this.submitting = true;
        await this.delay(450);
        this.submitting = false;
    },

    /** Pure motion, no state: a pulse standing in for dispatch. It takes no
     *  generation token because it writes nothing that could go stale. */
    async act1Transition() {
        this.act = 1.5;
        await this.delay(1400);
    },

    async act2(alive = () => true) {
        this.act = 2;
        // Every row goes in flight together: that is the whole point of the act.
        this.results = REGIONS.map((r) => ({ ...r, state: 'pending', shown: null }));
        await this.delay(350);

        // Results land in latency order, each on its own clock, because that is what a
        // parallel fan-out looks like from the outside. Each lander checks its
        // generation first: five timers are in flight here, and a tab click mid-fan-out
        // would otherwise let them write rows into a sequence that had already moved on.
        await Promise.all(
            this.results.map(
                (row, i) =>
                    new Promise((resolve) => {
                        setTimeout(() => {
                            if (!alive()) {
                                return resolve();
                            }

                            this.results[i] = { ...row, state: 'up', shown: row.ms };
                            resolve();
                        }, 120 + row.ms * 14);
                    }),
            ),
        );

        this.verdict = 'up';
        await this.delay(2200);
    },

    async act3(alive = () => true) {
        this.act = 3;
        this.metricValue = 0;
        this.metricBreached = false;
        this.showAi = false;
        await this.delay(500);

        // The number climbs through its bound rather than appearing past it, so the
        // breach is something that happens.
        for (const v of [1180, 2260, 3410, 4812]) {
            if (!alive()) return;

            this.metricValue = v;
            await this.delay(520);
        }

        this.metricBreached = true;

        // Latency follows the queue, which is the correlation the AI then cites.
        [3, 4].forEach((i) => {
            this.results[i] = { ...this.results[i], state: 'degraded', shown: REGIONS[i].ms * 9 };
        });
        this.verdict = 'degraded';
        await this.delay(900);

        this.showAi = true;
        await this.delay(4200);
    },

    /**
     * The two escalation steps. Step 1 goes out immediately; step 2 fires because the
     * incident is still open when its delay elapses.
     */
    ladder() {
        const [first, second, third, fourth] = this.channels;

        return [
            { at: 't+0s', channel: first, target: '#ops-alerts', step: 1 },
            { at: 't+0s', channel: third, target: 'Ops rotation', step: 1 },
            { at: 't+5m', channel: fourth, target: '#incidents', step: 2 },
            { at: 't+5m', channel: second, target: 'ops.acme.com/hooks/uptizm', step: 2 },
        ];
    },

    async act4(alive = () => true) {
        this.act = 4;
        this.escalation = [];
        this.waiting = false;
        this.resolvedAt = null;
        await this.delay(350);

        const rows = this.ladder();

        // Step 1 lands first.
        for (const row of rows.filter((r) => r.step === 1)) {
            if (!alive()) return;

            this.escalation.push(row);
            await this.delay(520);
        }

        // Then the interval that lets step 2 fire: the ladder climbs on its own
        // timers, and only a resolution stops it.
        await this.delay(900);
        this.waiting = true;
        await this.delay(1100);

        for (const row of rows.filter((r) => r.step === 2)) {
            if (!alive()) return;

            this.escalation.push(row);
            await this.delay(520);
        }

        await this.delay(800);
        this.resolvedAt = '5m 12s';
        await this.delay(2600);
    },

    delay(ms) {
        return new Promise((resolve) => setTimeout(resolve, ms));
    },

    /**
     * Types into a property one character at a time, and stops the moment its
     * generation is superseded. Writing `this[prop] +=` from a stale interval is what
     * produced a doubled URL.
     */
    type(prop, text, speed, alive = () => true) {
        // Under reduced motion the text simply arrives. Typing is movement.
        if (this.reduced) {
            this[prop] = text;

            return Promise.resolve();
        }

        return new Promise((resolve) => {
            this[prop] = '';
            let i = 0;

            const id = setInterval(() => {
                if (!alive()) {
                    clearInterval(id);

                    return resolve();
                }

                this[prop] += text.charAt(i);
                i += 1;

                if (i >= text.length) {
                    clearInterval(id);
                    resolve();
                }
            }, speed);
        });
    },
});
