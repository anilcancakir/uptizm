<?php

/*
|--------------------------------------------------------------------------
| Analytics, and the consent it needs
|--------------------------------------------------------------------------
|
| A CAPABILITY GATE, in the shape this codebase already uses for the AI claim
| (`ShowLandingController::aiEnabled()`) and the contact form
| (`SendContactMessageController::mailDeliverable()`): a surface the deployment
| cannot back is withheld entirely rather than rendered as a stub.
|
| Here the withheld surface is the whole analytics stack. With no container id
| there is no Google Tag Manager, no Consent Mode bootstrap, no consent banner,
| and no request to a Google host of any kind, not even a `preconnect`. That is
| not tidiness. `routes/marketing.php` keeps these pages off the session group so
| they set NO cookie, `tests/Feature/Marketing/CookieTest.php` pins it, and the
| Privacy page publishes it as a claim; a banner on a site that stores nothing is
| a consent question about nothing, and a regulator reads that as noise rather
| than as compliance. The Privacy page's cookie section reads this same key and
| flips between "this site sets no cookies" and the `_ga` / `_gid` table, so the
| key name below is a contract with that page.
|
| THE SHAPE CHECK FAILS CLOSED
|
| A container id is interpolated into an inline script in the document head, and
| a mistyped one is worse than an absent one: `id=GTM` fetches a container that
| does not exist on every page load, and a pasted URL or a stray quote reaches
| that inline script. So the value is accepted only in the shape Google issues
| (`GTM-` then alphanumerics) and anything else resolves to null, which withholds
| the surface exactly as an unset variable would. Unknown means closed.
|
| The check runs while this array is BUILT, so it survives `config:cache` (the
| cached file is the already-validated value) and it cannot be bypassed by a
| later reader.
|
*/

$containerId = env('GTM_CONTAINER_ID');

return [

    // The GTM container this deployment loads, or null when it has none. Null is the
    // state of this deployment today, and every analytics surface reads this one key.
    'gtm_container_id' => is_string($containerId) && preg_match('/^GTM-[A-Za-z0-9]+$/', $containerId) === 1
        ? $containerId
        : null,

    // Where the visitor's choice is recorded on their own device. `localStorage`, never a
    // cookie: the server never reads this value (GTM runs client side), so keeping it out
    // of a cookie is what lets these pages carry on sending no `Set-Cookie` at all. Held
    // in config rather than typed twice because the inline head script and the Alpine
    // component both address it, and a drift between the two would silently re-ask a
    // visitor who had already answered.
    'consent_storage_key' => 'uptizm.consent',

    // The version of the CATEGORIES a stored choice was made against, recorded alongside
    // it. Consent is only valid for what it was asked about, so adding or widening a
    // category means the old answers no longer cover the new question: bump this and every
    // stored choice stops matching, the banner asks again, and Consent Mode stays denied
    // until it is answered. Not env-overridable, because it changes with the code that
    // defines the categories and never per deployment.
    'consent_version' => 1,

];
