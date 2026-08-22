<?php

use App\Services\Billing\RevenueCatClient;

/*
|--------------------------------------------------------------------------
| RevenueCat
|--------------------------------------------------------------------------
|
| The store rail: Apple App Store and Google Play subscriptions, reaching this
| application as webhook deliveries (App\Http\Controllers\RevenueCatWebhookController)
| that are only ever a SIGNAL. What a subscriber is actually entitled to is read
| back from the API (App\Services\Billing\RevenueCatClient, called from
| App\Jobs\SyncRevenueCatEntitlement), so both halves of the rail need
| configuration here: the secret that authenticates an inbound delivery, and
| the key that authenticates our outbound read.
|
| BOTH SECRETS ARE EMPTY BY DEFAULT AND THE RAIL FAILS CLOSED ON EITHER. With
| no webhook secret the endpoint refuses every delivery, because it cannot tell
| RevenueCat apart from anybody who found the URL and an endpoint that queues a
| tier change must not accept an unauthenticated one. With no API key the
| authoritative read raises rather than answering "nothing is owed", which would
| revoke every paying team. Neither has a fallback: a default would be either a
| secret in a public repository or a value that authenticates as nobody.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Webhook Signing Secret
    |--------------------------------------------------------------------------
    |
    | The shared secret RevenueCat signs each delivery with, configured on the
    | webhook integration in its dashboard. The signature travels as
    | `X-RevenueCat-Webhook-Signature: t=<unix>,v1=<hmac_sha256_hex>` over
    | `"{t}.{raw body}"`.
    |
    | HMAC signing is OPT-IN on RevenueCat's side and the endpoint here accepts
    | nothing else, so the integration must have it enabled: the baseline static
    | `Authorization` header is a bearer secret in a header, which is weaker and
    | says nothing about the body it arrived with.
    |
    */

    'webhook_secret' => env('REVENUECAT_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Secret API Key
    |--------------------------------------------------------------------------
    |
    | The v1 secret key the authoritative `GET /subscribers/{app_user_id}` read
    | authenticates with. Project-scoped and secret; it never reaches a client.
    |
    */

    'secret_api_key' => env('REVENUECAT_SECRET_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | API Base URL
    |--------------------------------------------------------------------------
    |
    | RevenueCat's published API base. Overridable so a test can point the client
    | at a local listener; an empty value falls back to the constant, which is
    | why a blank env line cannot leave the client with no host at all.
    |
    */

    'base_url' => env('REVENUECAT_BASE_URL', RevenueCatClient::DEFAULT_BASE_URL),

    /*
    |--------------------------------------------------------------------------
    | Operation Budget
    |--------------------------------------------------------------------------
    |
    | Seconds for ONE authoritative read INCLUDING its retries, not per call: a
    | per-call timeout sized against a wall breaks the moment anything retries.
    | The arithmetic that has to hold is one read (10s) inside the sync job's own
    | timeout (55s) inside the `supervisor-1` worker timeout (60s), so raising
    | this without raising both of those puts a killed worker where a failed read
    | belongs.
    |
    */

    'operation_budget_seconds' => env('REVENUECAT_OPERATION_BUDGET_SECONDS', 10),

    /*
    |--------------------------------------------------------------------------
    | Accept Sandbox Purchases
    |--------------------------------------------------------------------------
    |
    | Whether this deployment may act on sandbox purchases at all. FALSE in
    | production, always: a sandbox purchase granting a real `business` tier is
    | money out of the door, and a store's sandbox is trivially reachable by
    | anybody with a developer account.
    |
    | It WIDENS what the event's own `environment` field is allowed to say; it is
    | never read instead of that field. The gate is applied twice on purpose, at
    | the webhook (the event's `environment`) and in the sync job (per-subscription
    | `is_sandbox`), because the API answers the two questions separately.
    |
    */

    'accept_sandbox' => (bool) env('REVENUECAT_ACCEPT_SANDBOX', false),

];
