{{--
    Google Consent Mode v2, then the Google Tag Manager container. Head only, inline only.

    WITHHELD ENTIRELY WITHOUT A CONTAINER ID

    The `@if` is the capability gate (config/analytics.php carries the reasoning): with no
    container this file emits NOTHING. No bootstrap, no loader, and deliberately not even a
    `preconnect` to a Google host, because a preconnect is still a request that hands the
    visitor's IP to a third party for a script the page is never going to load.

    THE ORDER IN HERE IS THE WHOLE CORRECTNESS QUESTION

    Consent Mode is a promise about SEQUENCE, not about presence. The container decides what
    a tag may store by reading the consent state it finds when it boots, so:

      1  `window.dataLayer` and the `gtag` shim, because `gtag()` is a dataLayer push and
         the real `gtag.js` is not here to define it.
      2  `consent` `default`, every signal DENIED. This has to be evaluated before the
         container exists, otherwise the tags inside it run for the window between the two
         and the denial arrives after the cookie has been written. Which is the defect that
         looks identical to a correct implementation in every screenshot.
      3  The stored choice, read synchronously, and an `update` to `granted` when the
         visitor already accepted. Without this a returning visitor who accepted last week
         is measured as denied until they interact with a banner they will never see again,
         and their own earlier consent is silently discarded.
      4  Only then the loader.

    So this block is INLINE and non-deferred, and it must stay that way. A bundled module
    (`resources/js/`) is deferred and would execute after `gtm.js` had already fetched and
    run, which is exactly step 2 happening too late. `ConsentTest` asserts the byte offset of
    the default block against the loader's rather than asserting that both are present,
    because presence passes against that bug.

    THE IP TRADE-OFF IS DELIBERATE, NOT AN OVERSIGHT

    The container loads on every page even with every signal denied, so Google sees the
    request (and therefore the IP) before consent. That is the accepted trade: in exchange
    the deployment keeps Consent Mode's own modelling and stays inside Google's EEA
    requirement for Ads. What consent gates here is STORAGE on the visitor's device, which
    is what ePrivacy Art. 5(3) is about, and the banner says so instead of implying that
    nothing at all reaches Google.

    `@js()` rather than `{{ }}` for every value that lands inside a script: it emits a
    JSON-safe JS literal, where the HTML escaper would turn a quote into `&#039;` and break
    the statement around it.
--}}
@if ($consent['container_id'] !== null)
    <script>
        (function () {
            window.dataLayer = window.dataLayer || [];

            function gtag() {
                window.dataLayer.push(arguments);
            }

            window.gtag = gtag;

            /*
             * Denied before anything can ask. `wait_for_update` holds a consent-gated tag
             * for up to 500ms so an `update` arriving slightly later still counts: the read
             * below is synchronous and normally beats the container outright, but a device
             * with slow or contended storage does not guarantee that, and without the wait
             * the container would resolve such a visitor as denied despite a stored grant.
             */
            gtag('consent', 'default', {
                'ad_storage': 'denied',
                'ad_user_data': 'denied',
                'ad_personalization': 'denied',
                'analytics_storage': 'denied',
                'wait_for_update': 500
            });

            var choice = null;

            try {
                var stored = window.localStorage.getItem(@js($consent['storage_key']));

                choice = stored === null ? null : JSON.parse(stored);
            } catch (error) {
                /*
                 * Storage can be unreachable rather than empty: Safari's private mode and a
                 * "block all storage" setting both THROW on access, and a truncated record
                 * throws on parse. The handler is a decision and not a swallow: no readable
                 * choice means no consent was proven, so the denial above stands and the
                 * banner asks again. Failing open here would grant on a broken read.
                 */
                choice = null;
            }

            /*
             * The version has to match. A stored answer covers the categories it was asked
             * about, so once those change (config/analytics.php `consent_version`) the old
             * answer is not consent to the new question and this comparison discards it.
             */
            if (choice !== null && choice.version === @js($consent['version']) && choice.analytics === true) {
                gtag('consent', 'update', {
                    'analytics_storage': 'granted'
                });
            }
        })();
    </script>

    {{--
        Google's own loader, verbatim in behaviour: it injects `gtm.js` async, so it never
        blocks the parser and never runs before the block above.

        Google's snippet also ships a `<noscript>` iframe fallback, and it is deliberately
        NOT here. With scripting off nothing on this page can present a choice, record one,
        or withdraw one, so that iframe would fetch a Google resource on behalf of a visitor
        who has no way to answer. It is also unnecessary: with no script there is no
        `gtm.js`, so there is nothing to store and nothing to consent to.
    --}}
    <script>
        (function (w, d, s, l, i) {
            w[l] = w[l] || [];
            w[l].push({ 'gtm.start': new Date().getTime(), event: 'gtm.js' });

            var f = d.getElementsByTagName(s)[0],
                j = d.createElement(s),
                dl = l !== 'dataLayer' ? '&l=' + l : '';

            j.async = true;
            j.src = 'https://www.googletagmanager.com/gtm.js?id=' + i + dl;
            f.parentNode.insertBefore(j, f);
        })(window, document, 'script', 'dataLayer', @js($consent['container_id']));
    </script>
@endif
