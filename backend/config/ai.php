<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Names
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the AI providers below should be the
    | default for AI operations when no explicit provider is provided
    | for the operation. This should be any provider defined below.
    |
    */

    'default' => env('AI_DEFAULT', 'anthropic'),
    'default_for_images' => 'gemini',
    'default_for_audio' => 'openai',
    'default_for_transcription' => 'openai',
    'default_for_embeddings' => 'openai',
    'default_for_reranking' => 'cohere',

    /*
    |--------------------------------------------------------------------------
    | Uptizm AI Suggest Mode
    |--------------------------------------------------------------------------
    |
    | Uptizm-specific settings for the AI suggest mode: a daily spend cap
    | per team (guards against runaway provider cost) and the model used
    | for incident triage suggestions.
    |
    */

    'budget' => [
        'daily_per_team' => env('AI_BUDGET_DAILY_PER_TEAM', 100),
    ],

    /*
     * How many seconds of WALL TIME one analyze's MODEL CALLS may spend
     * together, not per call.
     *
     * Per-call timeouts are not enough here and the difference is what produced
     * a production 500: an analyze makes up to THREE model calls (the
     * suggestion, the research turn, and metric discovery), so three
     * comfortable per-call limits still add up past any single wall. This is
     * the shared budget {@see App\Services\Ai\AiDeadline} hands out, and a call
     * that would start with too little left is not made at all: the caller
     * degrades to its deterministic answer, which every one of them already
     * knows how to do.
     *
     * It was 45, which is EXACTLY the suggestion turn's own ceiling, so a slow
     * suggestion left zero for the metric-discovery call behind it and the
     * metrics half of the setup came back empty with only a log line to say why.
     * The value has to stay at least `minimum_call_seconds` above that ceiling,
     * or a degraded analysis takes discovery down with it. That bound is
     * asserted in {@see Tests\Unit\Services\Ai\AiDeadlineTest}.
     *
     * It was then briefly 75, and 75 is too big for a request that shares this
     * number with the target probe. MEASURED in production on 2026-08-07: a
     * request that spent 36 seconds on the suggestion and 39 on discovery
     * (`cURL error 28: Operation timed out after 39001 milliseconds`) answered
     * the operator with a 504, twice. Something between the client and the
     * worker cut at 60 seconds and it was NOT any wall written down at the time:
     * nginx's `proxy_read_timeout` on the api vhost is 3600 and
     * `octane.max_execution_time` is 90. That 60 is still unidentified
     * (`config/octane.php`'s wall list; step 13 of the async-analyze plan chases
     * it down before the deploy that removes the only reproducer). It was set to
     * 50 for exactly that reason: sized to guarantee the degrade instead of the
     * 504, because it shared one clock with `POST /monitors/analyze`'s own HTTP
     * request.
     *
     * It is 150 now because that constraint is gone. The model calls no longer
     * run inside the HTTP request at all: they run inside
     * `App\Jobs\AnalyzeMonitorJob` on its own `analyze` Horizon queue
     * (`config/horizon.php`), so the wall this number has to clear is not a
     * proxy or `octane.max_execution_time` any more, it is the JOB's own
     * `$timeout` (160), which sits under the `analyze` supervisor's `timeout`
     * (170), which sits under the `redis-analyze` connection's `retry_after`
     * (200). `AnalyzeQueueConfigTest` pins that whole chain, and
     * `AiDeadlineTest` pins this value against the supervisor half of it. 150
     * gives metric discovery real room instead of the sliver 50 left it, and it
     * can afford to because it now only has to clear its own worker's ceiling
     * rather than a wall shared with every other request on the box.
     *
     * The clock no longer covers the probe either. It used to: the clock started
     * at the top of `MonitorController::analyze()`, before the probe, so the
     * probe's own 30 second timeout spent from this same number instead of
     * sitting outside it and clearing every wall above. The probe stays in the
     * request now; `AiDeadline::restart()` is called from
     * `AnalyzeMonitorJob::handle()` on entry instead, so this number funds the
     * model calls alone. Do not re-anchor it to cover the probe again without
     * re-deriving the sum against the job's own 160 second ceiling.
     */
    'request_budget_seconds' => (int) env('AI_REQUEST_BUDGET_SECONDS', 150),

    /*
     * The least time worth starting a model call with. Below this the provider
     * cannot realistically answer, and burning the remainder only delays the
     * degrade the caller is going to perform anyway.
     */
    'minimum_call_seconds' => (int) env('AI_MINIMUM_CALL_SECONDS', 8),

    'triage' => [
        'model' => env('AI_TRIAGE_MODEL', 'claude-haiku-4-5-20251001'),
    ],

    /*
     * Metric discovery reads its own model key rather than borrowing triage's,
     * so retuning one selection task cannot silently retune the other. It is a
     * structured-output selection over a compact candidate digest, which the
     * same cheap, fast model handles well.
     */
    'metric_discovery' => [
        // Falls back through `AI_TRIAGE_MODEL` for the reason spelled out at the
        // `analysis` key below: this gateway runs on the same analyze request, so
        // a literal default here degrades the metrics half of every setup on any
        // deployment that moved the AI surface, silently and identically.
        'model' => env('AI_METRIC_DISCOVERY_MODEL', env('AI_TRIAGE_MODEL', 'claude-haiku-4-5-20251001')),
    ],

    /*
     * Monitor-setup analysis reads its own model key for the same reason, and
     * has the strongest claim to one: it is the only task here that runs a
     * bounded TOOL LOOP before its structured turn, so it is the first that
     * would need a different model, and retuning it must not retune the five
     * gateways still on `triage`.
     *
     * It falls back through `AI_TRIAGE_MODEL` rather than straight to the
     * literal, and that is not tidiness. A deployment that has moved the AI
     * surface to another provider sets `AI_DEFAULT` and `AI_TRIAGE_MODEL` and
     * nothing else, so a literal default here would ask THAT provider for an
     * Anthropic-native id it does not serve. The gateway's degrade would then
     * catch the failure and answer deterministically on every single request,
     * with only a log line to say so: the feature would ship dark and look
     * healthy. Inheriting the value the rest of the surface already resolves to
     * is what makes an unset env genuinely change nothing.
     */
    'analysis' => [
        'model' => env('AI_ANALYSIS_MODEL', env('AI_TRIAGE_MODEL', 'claude-haiku-4-5-20251001')),
    ],

    /*
     * The hard character budget one response digest may spend in the setup
     * prompt. The worker returns up to 1 MiB of body, which is two orders of
     * magnitude more context than a monitor suggestion needs and all of it
     * target-authored, so the budget is a ceiling rather than a target: when it
     * binds, whole subtrees are dropped and the digest says so.
     */
    'digest' => [
        'max_characters' => env('AI_DIGEST_MAX_CHARACTERS', 8000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Below you may configure caching strategies for AI related operations
    | such as embedding generation. You are free to adjust these values
    | based on your application's available caching stores and needs.
    |
    */

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Below are each of your AI providers defined for this application. Each
    | represents an AI provider and API key combination which can be used
    | to perform tasks like text, image, and audio creation via agents.
    |
    */

    'providers' => [
        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
            'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
        ],

        'azure' => [
            'driver' => 'azure',
            'key' => env('AZURE_OPENAI_API_KEY'),
            'url' => env('AZURE_OPENAI_URL'),
            'api_version' => env('AZURE_OPENAI_API_VERSION', '2025-04-01-preview'),
            'deployment' => env('AZURE_OPENAI_DEPLOYMENT', 'gpt-4o'),
            'embedding_deployment' => env('AZURE_OPENAI_EMBEDDING_DEPLOYMENT', 'text-embedding-3-small'),
            'image_deployment' => env('AZURE_OPENAI_IMAGE_DEPLOYMENT', 'gpt-image-1'),
            'store' => env('AZURE_OPENAI_STORE', true),
        ],

        'bedrock' => [
            'driver' => 'bedrock',
            'region' => env('AWS_BEDROCK_REGION', 'us-east-1'),
            'key' => env('AWS_BEARER_TOKEN_BEDROCK'),
            'access_key_id' => env('AWS_ACCESS_KEY_ID'),
            'secret_access_key' => env('AWS_SECRET_ACCESS_KEY'),
            'session_token' => env('AWS_SESSION_TOKEN'),
            'use_default_credential_provider' => env('AWS_USE_DEFAULT_CREDENTIALS', true),
        ],

        'cohere' => [
            'driver' => 'cohere',
            'key' => env('COHERE_API_KEY'),
        ],

        'deepseek' => [
            'driver' => 'deepseek',
            'key' => env('DEEPSEEK_API_KEY'),
        ],

        'eleven' => [
            'driver' => 'eleven',
            'key' => env('ELEVENLABS_API_KEY'),
        ],

        'gemini' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY'),
            'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/'),
        ],

        'groq' => [
            'driver' => 'groq',
            'key' => env('GROQ_API_KEY'),
        ],

        'jina' => [
            'driver' => 'jina',
            'key' => env('JINA_API_KEY'),
        ],

        'mistral' => [
            'driver' => 'mistral',
            'key' => env('MISTRAL_API_KEY'),
        ],

        'ollama' => [
            'driver' => 'ollama',
            'key' => env('OLLAMA_API_KEY', ''),
            'url' => env('OLLAMA_URL', 'http://localhost:11434'),
        ],

        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
            'store' => env('OPENAI_STORE', true),
        ],

        'openai-compatible' => [
            'driver' => 'openai-compatible',
            'url' => env('OPENAI_COMPATIBLE_URL'),
            'key' => env('OPENAI_COMPATIBLE_API_KEY'),
        ],

        'openrouter' => [
            'driver' => 'openrouter',
            'key' => env('OPENROUTER_API_KEY'),
        ],

        'voyageai' => [
            'driver' => 'voyageai',
            'key' => env('VOYAGEAI_API_KEY'),
        ],

        'xai' => [
            'driver' => 'xai',
            'key' => env('XAI_API_KEY'),
        ],
    ],

];
