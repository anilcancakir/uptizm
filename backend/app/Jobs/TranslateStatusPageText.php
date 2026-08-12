<?php

namespace App\Jobs;

use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\ScheduledMaintenance;
use App\Models\StatusPage;
use App\Models\StatusPageTranslation;
use App\Services\Ai\Concerns\RoutesOpenRouterByLatency;
use App\Services\Ai\LaravelAiAnalysisGateway;
use App\Services\Ai\TranslationPayload;
use App\Services\Monitoring\IncidentTitle;
use App\Support\StatusPages\TranslationOutputContract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\AnonymousAgent;
use Throwable;

/**
 * Translates ONE field of ONE row into ONE language, at write time.
 *
 * The fan-out is per (row, field, target locale) and it happens where the text
 * is WRITTEN, never where it is read: a public status page is unauthenticated,
 * so a translation resolved on demand would let any visitor spend money on a
 * model call. {@see self::fanOut()} is the only entry point, and the four write
 * paths that call it are the whole surface.
 *
 * WHY THE MODEL CALL RUNS THROUGH AN ANONYMOUS AGENT, AND WHAT THAT COSTS.
 * Every other model call in this application is a NAMED `laravel/ai` agent in
 * `app/Services/Ai`, carrying {@see RoutesOpenRouterByLatency} and its own
 * `#[MaxTokens]`. This one is {@see AnonymousAgent}, prompted from
 * {@see self::translate()}, because neither of the two alternatives is available
 * to this file:
 *
 *   - This class cannot BE the agent. `Laravel\Ai\Promptable` defines
 *     `queue(string $prompt, ...)`, and `Illuminate\Bus\Dispatcher` dispatches a
 *     queued job through `method_exists($command, 'queue')`, so every dispatch
 *     would call the agent's method with a Queue instance and fatal. Measured:
 *     seven `IncidentWriteServiceTest` errors on exactly that TypeError.
 *   - A named agent beside the other six is what this SHOULD be, and it is a
 *     follow-up rather than a decision: `OpenRouterRoutingTest` enumerates
 *     `app/Services/Ai` and asserts the discovered agent list equals a literal
 *     six, so the seventh arrives with that test in the same change.
 *
 * The cost, stated so it is not rediscovered as a mystery: this call does not
 * carry `provider.sort = latency`, so on OpenRouter it inherits price-weighted
 * routing and the 8x latency spread that trait exists to avoid, and it declares
 * no token ceiling of its own. Neither can make a translation WRONG (the output
 * contract still runs), only slow or absent, which the page shows as `pending`.
 *
 * THE CONTAINMENT, in order, because none of it is optional on this path:
 *
 *  1. WHAT MAY BE TRANSLATED IS A CLOSED SET.
 *     {@see self::TRANSLATABLE_FIELDS} is checked at dispatch AND again in
 *     {@see self::handle()}, so a queued job that outlived a code change cannot
 *     reach a column the set no longer names. Identity fields (`StatusPage.name`,
 *     `Monitor.name`, a component's custom label) are absent by design: a brand
 *     is not translated.
 *  2. AN AUTO-GENERATED TITLE IS NEVER TRANSLATED. `Incident.title` is
 *     translated only when `title_key IS NULL`, which is this codebase's test for
 *     "a human wrote this", and it is read off the COLUMN rather than off
 *     {@see IncidentTitle::render()}: `render()` falls
 *     back to the raw English column when `title_params` is not an array, and a
 *     generated title's stored English carries the monitored response value
 *     interpolated into it, which is third-party text.
 *  3. AN INTERNAL NOTE IS NEVER TRANSLATED. An `IncidentUpdate` is translated
 *     only while `is_public` is true. A postmortem DRAFT is translated, and that
 *     is deliberate rather than an oversight: the translation inherits its row's
 *     visibility because it is keyed to the row, so nothing becomes readable that
 *     was not already.
 *  4. THE FENCE, in {@see TranslationPayload}: the untrusted text is delimited
 *     and JSON-encoded onto one line, so it cannot pose as an instruction.
 *  5. THE OUTPUT CONTRACT, in {@see TranslationOutputContract}: mechanical, and
 *     the only thing standing between an injected support URL and a public,
 *     indexed, customer-branded page. A rejection is RECORDED (value null,
 *     `rejected_at` set, a machine reason) rather than retried, so the page
 *     renders the original with a note and no loop spends a second call.
 *
 * IDEMPOTENCY IS BY SOURCE HASH, not by a unique lock. A row whose stored
 * translation already answers for exactly this source text costs no model call,
 * so a retry, a double save and a re-dispatch are all free; and an operator who
 * edits the text twice in a second still gets both edits translated, which a
 * `ShouldBeUnique` lock would have dropped.
 */
class TranslateStatusPageText implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The six free-text fields this surface translates, per model.
     *
     * A closed set on both ends: a class not named here is not translatable at
     * all, and a field not named against its class is not either. Everything
     * absent is absent on purpose. `StatusPage.name`, `StatusPage.logo_text`,
     * `Monitor.name` and a component's `custom_label` are IDENTITY: they are what
     * the reader recognises the service by, and a translated brand is a wrong
     * brand.
     *
     * @var array<class-string<Model>, list<string>>
     */
    public const array TRANSLATABLE_FIELDS = [
        Incident::class => [
            'title',
            'postmortem_body',
        ],
        IncidentUpdate::class => [
            'message',
        ],
        ScheduledMaintenance::class => [
            'title',
            'description',
        ],
        StatusPage::class => [
            'description',
        ],
    ];

    /**
     * The shared `ai` queue on the default Redis connection, drained by
     * supervisor-1 alongside the other model-calling jobs.
     *
     * Only `onQueue()` is set here, and unlike {@see AnalyzeMonitorJob} that is
     * NOT an omission: that job needs its own connection because its 160-second
     * timeout clears the shared connection's `retry_after` of 90, which would
     * otherwise release it to a second worker mid-run. One translation is a
     * single short call and {@see self::$timeout} sits well under that 90, so the
     * shared connection is correct and a private one would only cost another
     * supervisor.
     */
    protected const string QUEUE = 'ai';

    /**
     * Seconds the model call may take.
     *
     * The measured spread on the pinned model is p50 0.33s to about 4s depending
     * on which upstream OpenRouter routes to, so 30 is roughly seven times the
     * slow end: generous enough that a routing artifact does not cost a
     * translation, tight enough that a hung provider does not hold a worker.
     */
    protected const int CALL_TIMEOUT_SECONDS = 30;

    /**
     * One retry, then the failure is recorded and the field renders untranslated.
     *
     * Two rather than one because the failure this covers is transport (a 429, a
     * dropped connection, a provider having a bad day) and a retried call is
     * cheaper than a page that reads `pending` forever. It is NOT a retry on
     * rejection: a rejected translation is a stored verdict, not an exception,
     * and nothing re-queues it.
     */
    public int $tries = 2;

    /**
     * Seconds before the retry, so a provider that is rate-limiting is not asked
     * again inside the same window.
     */
    public int $backoff = 60;

    /**
     * Wall clock for one attempt, comfortably above the model call's own ceiling
     * and comfortably below the shared Redis connection's `retry_after` of 90, so
     * no attempt can be released to a second worker while it is still running.
     */
    public int $timeout = 60;

    /**
     * @param  string  $translatableType  The morph type of the row holding the text.
     * @param  string  $translatableId  That row's key.
     * @param  string  $field  The column being translated, one of {@see self::TRANSLATABLE_FIELDS}.
     * @param  string  $sourceLocale  The locale the text was authored in.
     * @param  string  $targetLocale  The locale to translate it into.
     */
    public function __construct(
        public string $translatableType,
        public string $translatableId,
        public string $field,
        public string $sourceLocale,
        public string $targetLocale,
    ) {
        $this->onQueue(self::QUEUE);
    }

    /**
     * Queue one translation of `$field` per supported locale other than the
     * source's own.
     *
     * The ONLY dispatch entry point, so the closed set, the per-field guards and
     * the locale arithmetic have exactly one implementation and a fifth write
     * path inherits all three by calling this instead of remembering them.
     *
     * Dispatched `afterCommit()`: three of the four write paths write inside a
     * transaction, and the job hydrates its row by key, so a worker that started
     * before the commit would find nothing and record no translation at all.
     *
     * @param  Model  $translatable  The row whose field was just written.
     * @param  string  $field  The column that was written.
     * @param  string  $sourceLocale  The language it was written in.
     */
    public static function fanOut(Model $translatable, string $field, string $sourceLocale): void
    {
        if (! self::shouldTranslate($translatable, $field)) {
            return;
        }

        foreach (self::targetLocales($sourceLocale) as $locale) {
            self::dispatch(
                $translatable->getMorphClass(),
                (string) $translatable->getKey(),
                $field,
                $sourceLocale,
                $locale,
            )->afterCommit();
        }
    }

    /**
     * Whether this row's field is translatable AS IT STANDS.
     *
     * Public and static because it is asked twice on purpose: once at dispatch,
     * so a keyed incident title or an internal note never costs a queued job at
     * all, and again in {@see self::handle()}, so a job that outlived the state
     * it was queued against (a note flipped to internal, a title regenerated with
     * a key) cannot spend a model call on it either.
     */
    public static function shouldTranslate(Model $translatable, string $field): bool
    {
        $fields = self::TRANSLATABLE_FIELDS[$translatable->getMorphClass()] ?? [];

        if (! in_array($field, $fields, true)) {
            return false;
        }

        $value = $translatable->getAttribute($field);

        if (! is_string($value) || trim($value) === '') {
            return false;
        }

        return match (true) {
            // An auto-generated title is composed from a catalogue key and
            // re-rendered per language already, and its stored English carries
            // the monitored response value interpolated into it.
            $translatable instanceof Incident && $field === 'title' => $translatable->title_key === null,
            // An internal note never reaches the public page, so translating it
            // would spend a model call to publish nothing.
            $translatable instanceof IncidentUpdate => (bool) $translatable->is_public,
            default => true,
        };
    }

    /**
     * Resolve, guard, prompt, verify, and record exactly one translation.
     */
    public function handle(): void
    {
        // 0. A deployment with no credential for its default AI provider cannot
        //    translate anything, and asking anyway costs a worker, a failed job
        //    row and a 401 per public write. Checked at RUN time rather than at
        //    dispatch, because a credential can arrive between the two, and the
        //    next write re-queues the field either way.
        //
        //    This is also what keeps CI off the network: the four write paths are
        //    exercised by dozens of existing tests, `QUEUE_CONNECTION` is `sync`
        //    in the suite so each of those would run this job inline, and
        //    `phpunit.xml` pins every provider key to the empty string.
        if (! $this->providerIsConfigured()) {
            return;
        }

        // 1. Re-resolve the row and re-run every guard: this job was queued
        //    against a state that may have moved, and the guards are cheaper than
        //    the call they precede.
        $translatable = $this->resolveTranslatable();

        if ($translatable === null || ! self::shouldTranslate($translatable, $this->field)) {
            return;
        }

        if (! in_array($this->targetLocale, self::supportedLocales(), true)
            || $this->targetLocale === $this->sourceLocale) {
            return;
        }

        $teamId = $this->teamIdFor($translatable);

        if ($teamId === null) {
            return;
        }

        // 2. Idempotency by source hash: a stored verdict that already answers
        //    for exactly this text, accepted or rejected, is not re-asked.
        $source = (string) $translatable->getAttribute($this->field);
        $sourceHash = hash('sha256', $source);
        $existing = $this->existingTranslation($translatable);

        if ($existing !== null
            && $existing->source_hash === $sourceHash
            && ($existing->value !== null || $existing->rejected_at !== null)) {
            return;
        }

        // 3. The call.
        $answer = $this->translate(new TranslationPayload(
            text: $source,
            sourceLocale: $this->sourceLocale,
            targetLocale: $this->targetLocale,
            field: $this->field,
        ));

        // 4. The verdict, and the row either way. A rejection is recorded rather
        //    than retried, so the page can tell "not translated yet" from
        //    "translation unavailable" and nothing loops.
        $verdict = TranslationOutputContract::verify($source, $answer);

        if (! $verdict['accepted']) {
            // The reason and the row identity, never the text: the suspect string
            // is what a log reader would then paste somewhere, and the contract
            // deliberately does not hand it back.
            Log::warning('Status page translation rejected by the output contract.', [
                'translatable_type' => $translatable->getMorphClass(),
                'translatable_id' => (string) $translatable->getKey(),
                'field' => $this->field,
                'locale' => $this->targetLocale,
                'reason' => $verdict['reason'],
            ]);
        }

        StatusPageTranslation::query()->updateOrCreate(
            [
                'translatable_type' => $translatable->getMorphClass(),
                'translatable_id' => $translatable->getKey(),
                'field' => $this->field,
                'locale' => $this->targetLocale,
            ],
            [
                'team_id' => $teamId,
                'value' => $verdict['value'],
                'source_hash' => $sourceHash,
                'rejected_at' => $verdict['accepted'] ? null : now(),
                'rejection_reason' => $verdict['reason'],
            ],
        );
    }

    /**
     * The one model call, and the ONLY seam a test replaces.
     *
     * The agent is anonymous for the reason this class's docblock gives, so the
     * package's own per-agent fake is keyed on {@see AnonymousAgent} rather than
     * on this class: a test binds `Ai::fakeAgent(AnonymousAgent::class, [...])`
     * and no CI run can reach a provider. Both halves of the prompt come from
     * {@see TranslationPayload}, so the whole thing is readable in one file.
     *
     * The timeout is passed explicitly rather than declared as an attribute,
     * because an anonymous agent has no class to hang one on.
     */
    protected function translate(TranslationPayload $payload): string
    {
        $agent = new AnonymousAgent($payload->instructions(), [], []);

        return $agent->prompt(
            $payload->buildUserMessage(),
            model: $this->translationModel(),
            timeout: self::CALL_TIMEOUT_SECONDS,
        )->text;
    }

    /**
     * Record a transport failure without quoting the provider.
     *
     * The exception CLASS and the row identity, never the message: a provider or
     * gateway message can quote the model, which was reading text a third party
     * authored. Nothing is written to the translation store here, so the field
     * stays `pending` and the next edit re-queues it.
     */
    public function failed(?Throwable $exception): void
    {
        Log::warning('Status page translation failed; the field stays untranslated.', [
            'translatable_type' => $this->translatableType,
            'translatable_id' => $this->translatableId,
            'field' => $this->field,
            'locale' => $this->targetLocale,
            'exception' => $exception === null ? 'none' : $exception::class,
        ]);
    }

    /**
     * Every supported locale except `$sourceLocale`.
     *
     * Read from `magic-starter.supported_locales`, the one list the API
     * negotiation, the marketing routes, the validators and the Flutter client
     * all read, so adding a language reaches this fan-out with no edit here.
     *
     * @return list<string>
     */
    public static function targetLocales(string $sourceLocale): array
    {
        return array_values(array_diff(self::supportedLocales(), [$sourceLocale]));
    }

    /**
     * The model key this task reads.
     *
     * Its own key rather than triage's, so retuning translation cannot silently
     * retune incident triage. Named `translationModel()` rather than `model()`
     * for the reason {@see LaravelAiAnalysisGateway::analysisModel()} spells out:
     * `model()` is a `laravel/ai` agent hook, and defining it would change how the
     * package resolves the model as a side effect of naming a method.
     */
    protected function translationModel(): ?string
    {
        $model = config('ai.translation.model');

        return is_string($model) ? $model : null;
    }

    /**
     * Whether the default AI provider has a credential to call with.
     *
     * Reads the DEFAULT provider's key rather than a named one, so a deployment
     * that moved its AI surface is answered correctly without another edit here.
     * Mirrors {@see LaravelAiAnalysisGateway::research()}'s
     * `$this->researchClient->configured()` guard: a call that could only refuse
     * is not worth making.
     */
    protected function providerIsConfigured(): bool
    {
        $provider = config('ai.default');

        if (! is_string($provider) || $provider === '') {
            return false;
        }

        $key = config("ai.providers.{$provider}.key");

        return is_string($key) && $key !== '';
    }

    /**
     * The languages this deployment ships.
     *
     * @return list<string>
     */
    protected static function supportedLocales(): array
    {
        $locales = config('magic-starter.supported_locales');

        return is_array($locales) ? array_values(array_filter($locales, 'is_string')) : [];
    }

    /**
     * Load the row this job was queued against, or null when it is gone or its
     * type is not one this job may touch.
     */
    protected function resolveTranslatable(): ?Model
    {
        if (! array_key_exists($this->translatableType, self::TRANSLATABLE_FIELDS)) {
            return null;
        }

        /** @var class-string<Model> $class */
        $class = $this->translatableType;

        return $class::query()->find($this->translatableId);
    }

    /**
     * The stored verdict for this (row, field, locale), if there is one.
     */
    protected function existingTranslation(Model $translatable): ?StatusPageTranslation
    {
        return StatusPageTranslation::query()
            ->where('translatable_type', $translatable->getMorphClass())
            ->where('translatable_id', $translatable->getKey())
            ->where('field', $this->field)
            ->where('locale', $this->targetLocale)
            ->first();
    }

    /**
     * The owning team, denormalised onto every translation row so a team-scoped
     * read never needs a join.
     *
     * An {@see IncidentUpdate} carries no `team_id` of its own, so it answers
     * through its incident; an update whose incident is gone has no tenant to
     * file under and is not translated.
     */
    protected function teamIdFor(Model $translatable): ?string
    {
        $teamId = $translatable instanceof IncidentUpdate
            ? $translatable->incident?->team_id
            : $translatable->getAttribute('team_id');

        return is_string($teamId) || is_int($teamId) ? (string) $teamId : null;
    }
}
