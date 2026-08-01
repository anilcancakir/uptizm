<?php

namespace App\Http\Controllers\Marketing;

use App\Enums\MonitorRegion;
use App\Enums\NotificationChannelType;
use App\Models\StatusPage;
use App\Notifications\IncidentOpened;
use App\Services\Ai\IncidentAnalysisPayload;
use App\Support\Marketing\ChromeData;
use App\Support\Marketing\LegalDocument;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * The Privacy notice, in whichever language its URL asked for.
 *
 * The document itself is Markdown under `resources/legal/`, so the prose lives in version
 * control and in one file per language rather than inside a Blade template. This controller
 * only says which document, in which language, wearing which chrome, and supplies every
 * FACT the prose quotes.
 *
 * WHY THIS CONTROLLER DERIVES MORE THAN ITS SIBLINGS
 *
 * A privacy notice that overstates is a false statement about how personal data is handled,
 * and the way a notice goes wrong is not by being written badly once: it is by being written
 * correctly and then left behind while the app moves. So no figure and no third party on this
 * page is typed into the prose. The retention window comes from the config the database's own
 * retention policy was built from, the region count and the alert channels from the enums the
 * write requests validate against, the AI excerpt cap from the constant that performs the
 * truncation, the third-party script count and the analytics cookie families from the two
 * configuration keys that load those scripts, and the recipient list from the same gates the
 * runtime checks before it calls anybody.
 *
 * The counterpart rule, learned from the two claims this page had to withdraw: a window or a
 * tier that no code applies does not get published because a config key exists for it. The
 * hourly and daily retention keys in `config/timescale.php` are read by nothing that prunes
 * anything (no continuous aggregate is ever created; the Timescale migration attaches its
 * policy to the raw tables only), so the summary tier they described came off the page rather
 * than the aggregates going in.
 *
 * Only digits and proper nouns are interpolated, matching {@see ShowFaqController}: the
 * surrounding sentence is authored per locale in the Markdown, so no translatable word ever
 * has to route through a placeholder and no prose lives in PHP.
 *
 * `$sections` is deliberately left at ChromeData's empty default. It means "the in-page
 * anchors the header and footer nav may offer", and those are the LANDING page's; this page's
 * own anchors are its headings and they belong in the table of contents beside the text.
 * Handing over the landing list would put nav links on this page pointing at ids it never
 * emits, which `ChromeTest`'s dangling-anchor guard fails the build on.
 */
class ShowPrivacyController
{
    /**
     * The cookie FAMILIES Google Analytics may store once the visitor accepts the analytics
     * category, which is also the figure and the list the page publishes.
     *
     * FAMILIES and not names, and the count is not a count of cookies. This list used to be
     * `_ga` plus `_gid`, published as a two-cookie total, while the module that expires them on
     * withdrawal (`resources/js/components/consentChoice.js`) already worked in the four
     * prefixes below: GA4 mints a per-stream `_ga_<MEASUREMENT_ID>` whose suffix no code here
     * can know, `_gid` is a Universal Analytics cookie a GA4-only container may never set, and
     * a container can add `_gat` or `_gac_*` without anybody revisiting either file. A total is
     * therefore not derivable at all, so the page publishes the families and says why the
     * figure is not a cookie count.
     *
     * Kept identical to that module's prefix list on purpose: what this page publishes has to
     * be what taking an answer back actually clears, and `PrivacyPageTest` reads the list out
     * of the JavaScript rather than restating it, so the two cannot drift.
     *
     * The consent layer keeps Consent Mode defaults denied, so these are what the site CAN
     * store rather than what it has stored: publishing the capability is the honest figure,
     * because it is fixed by configuration while what is actually stored depends on an answer
     * the visitor has not given yet.
     *
     * @var list<string>
     */
    private const ANALYTICS_COOKIE_FAMILIES = [
        '_ga',
        '_gid',
        '_gat',
        '_gac_',
    ];

    /**
     * One string doing three jobs: the route path, the Markdown filename under
     * `resources/legal/<page>.<locale>.md`, and the path `ChromeData` composes this page's
     * own canonical and hreflang set from. Keeping them the same constant is what stops a
     * page declaring itself canonical at an address it is not served on.
     */
    private const PAGE = 'privacy';

    public function __construct(
        protected LegalDocument $document,
    ) {}

    public function __invoke(): View
    {
        return view('marketing.content-page', [
            ...(new ChromeData(
                path: self::PAGE,
                summary: $this->summary(),
            ))->toArray(),
            'title' => __('Privacy Policy'),
            // `app()->getLocale()`, which `SetMarketingLocale` has already set from the
            // path, rather than the route parameter: the apex form carries no `{locale}`
            // parameter at all, so reading the route would render the default language's
            // document for the prefixed URLs and nothing for `/privacy`.
            'document' => $this->document->render(self::PAGE, app()->getLocale(), $this->replacements()),
        ]);
    }

    /**
     * Every fact the notice quotes, mapped from its bracketed placeholder.
     *
     * `LegalDocument` applies these AFTER its cache read, so a config change reaches the page
     * without the Markdown file having to be re-saved. An unmapped placeholder survives into
     * the output verbatim rather than vanishing, so a forgotten entry here shows up as
     * `[[privacy.check_days]]` on the page instead of as a legal sentence with a hole in it,
     * and `LegalPagesTest` fails on exactly that.
     *
     * The operator's tax number and registry id are deliberately NOT here. They are the
     * e-Commerce Art. 5 identity disclosure and they belong to the Terms page; for an esnaf
     * the tax number is a national identity number, and a privacy notice is the last page
     * that should publish one it has no reason to.
     *
     * The two identity values it DOES carry route through {@see self::identity()}: both are
     * personal data the repository no longer holds, so the row renders the absence rather
     * than a blank while the Service is unlaunched.
     *
     * @return array<string, string>
     */
    protected function replacements(): array
    {
        return [
            '[[legal.operator]]' => $this->identity('operator'),
            '[[legal.address]]' => $this->identity('address'),
            '[[legal.contact_email]]' => (string) config('legal.contact_email'),
            '[[legal.rights_email]]' => (string) config('legal.rights_email'),
            '[[legal.authority]]' => (string) config('legal.authority'),
            // The one retention window the database's own policy was attached from
            // (config/timescale.php), so the page states the period that is actually applied
            // rather than the one somebody remembered when they wrote it. The hourly and daily
            // keys beside it in that file are deliberately NOT here: see the class docblock.
            '[[privacy.check_days]]' => (string) config('timescale.retention.raw_days'),
            '[[privacy.region_count]]' => (string) count(MonitorRegion::cases()),
            '[[privacy.regions]]' => Arr::join(
                array_map(fn (MonitorRegion $region): string => $region->label(), MonitorRegion::cases()),
                ', ',
            ),
            '[[privacy.channels]]' => Arr::join($this->alertChannels(), ', '),
            '[[privacy.ai_chars]]' => (string) IncidentAnalysisPayload::UNTRUSTED_FIELD_MAX_LENGTH,
            '[[privacy.recipients]]' => Arr::join($this->recipients(), ', '),
            '[[privacy.embed_count]]' => (string) $this->thirdPartyScriptCount(),
            '[[privacy.cookie_family_count]]' => (string) $this->cookieFamilyCount(),
            '[[privacy.cookie_families]]' => Arr::join(self::ANALYTICS_COOKIE_FAMILIES, ', '),
            '[[privacy.session_cookie]]' => (string) config('session.cookie'),
            '[[privacy.session_minutes]]' => (string) config('session.lifetime'),
        ];
    }

    /**
     * One identity value from the catalog, or the honest absence the notice publishes while
     * the slot is empty.
     *
     * Duplicated from {@see ShowTermsController::identity()} rather than extracted: two
     * callers is not an abstraction, and the pair a notice needs (who the controller is, and
     * where) is a smaller set than the Art. 5 disclosure block. The rule both share is the
     * one that matters: never an empty string, never a dash, never a guess, because the
     * operator's name and address are personal data the repository does not hold until the
     * Service launches and a reader must be able to tell that from a rendering fault.
     */
    protected function identity(string $key): string
    {
        $value = config("legal.{$key}");

        if (! is_string($value) || trim($value) === '') {
            return __('Not published yet');
        }

        return $value;
    }

    /**
     * The team-scoped alert destinations that exist, matching
     * {@see ShowLandingController::channels()} and {@see ShowFaqController::alertChannels()}:
     * an exhaustive match with no default arm, so adding a channel type breaks the build here
     * rather than leaving a recipient of personal data undisclosed.
     *
     * @return list<string>
     */
    protected function alertChannels(): array
    {
        return array_map(
            fn (NotificationChannelType $type): string => match ($type) {
                NotificationChannelType::Slack => 'Slack',
                NotificationChannelType::Webhook => 'Webhook',
                NotificationChannelType::PagerDuty => 'PagerDuty',
                NotificationChannelType::Teams => 'Microsoft Teams',
            },
            NotificationChannelType::cases(),
        );
    }

    /**
     * The third parties that receive personal data on THIS deployment, named.
     *
     * Art. 13/14 accept categories, and the prose gives categories; this list is the other
     * half of that answer, and it exists because a named list is where a privacy page most
     * easily publishes a fiction. Every template names a payment provider, an AI vendor and an
     * analytics vendor whether or not the deployment calls any of them. Each entry below is
     * gated on the SAME configuration the runtime checks before it makes the call, so a
     * deployment with no AI key and no payment secret names neither, and one that gains a key
     * names it without anybody editing this page.
     *
     * The inverse failure is what the analytics arm exists for, and this list ran without one
     * for a while: the consent layer landed with the page, so a configured container made
     * Google a recipient of an IP address on every marketing page while the sentence above
     * still told the reader that a category missing from this list receives nothing.
     *
     * @return list<string>
     */
    protected function recipients(): array
    {
        return array_values(array_filter([
            $this->edgeNetwork(),
            $this->analyticsProvider(),
            $this->aiProvider(),
            $this->paymentProvider(),
            $this->emailProvider(),
            $this->pushProvider(),
            $this->objectStorage(),
        ]));
    }

    /**
     * The edge platform every probe runs in.
     *
     * Unconditional, and the only entry that is. The probe path has exactly one shape: the API
     * signs a spec and POSTs it to the Cloudflare Worker in `backend/workers/regional-checker`,
     * which runs it inside a region-pinned Durable Object. `relay.url` chooses WHICH worker,
     * never whether one is involved, so gating this entry on the URL would only make the page
     * lie on a developer's machine where the worker is served from localhost.
     */
    protected function edgeNetwork(): string
    {
        return 'Cloudflare';
    }

    /**
     * The analytics provider, or null while this deployment configures no tag container.
     *
     * The gate is the one key every analytics surface reads (`config/analytics.php`), and the
     * shape check there means a mistyped id resolves to null and withholds the whole stack, so
     * this answers "does a Google script load" rather than "was something typed into the env".
     *
     * Google and not "Google Analytics": the container is Tag Manager, the tag inside it is
     * Analytics, and the recipient of both requests is one company. Naming the company is what
     * the transfer section then needs, because that is what an adequacy decision attaches to.
     *
     * Unconditional once the key is set, with no consent condition attached, because consent
     * does not gate the transfer: `resources/views/marketing/analytics.blade.php` loads the
     * container on every page with every signal denied, so the request and the IP address it
     * carries reach Google before the banner is answered. Consent Mode gates STORAGE on the
     * device. Gating this entry on an answer nobody has given yet would withhold a disclosure
     * about a transfer that has already happened.
     */
    protected function analyticsProvider(): ?string
    {
        return filled(config('analytics.gtm_container_id')) ? 'Google' : null;
    }

    /**
     * The AI provider incident analyses are sent to, or null when this deployment has no key.
     *
     * The gate is {@see ShowLandingController::aiEnabled()}'s: without a key for the selected
     * provider every AI path degrades to its deterministic baseline and nothing leaves, so
     * naming a provider would name a recipient that receives nothing.
     *
     * The match arms exist for CASING only, and only where a generic transform gets it wrong
     * ("Openai", "Openrouter", "Xai"). The default is the configured key run through
     * `Str::headline`, so a provider nobody anticipated is still named from configuration
     * rather than omitted.
     */
    protected function aiProvider(): ?string
    {
        $provider = config('ai.default');

        if (! is_string($provider) || $provider === '') {
            return null;
        }

        $key = config("ai.providers.{$provider}.key");

        if (! is_string($key) || $key === '') {
            return null;
        }

        return match ($provider) {
            'openai' => 'OpenAI',
            'openai-compatible' => 'OpenAI-compatible provider',
            'openrouter' => 'OpenRouter',
            'xai' => 'xAI',
            'azure' => 'Azure OpenAI',
            'bedrock' => 'AWS Bedrock',
            'voyageai' => 'Voyage AI',
            default => Str::headline($provider),
        };
    }

    /**
     * The payment provider, or null when this deployment cannot take a payment.
     *
     * Either Stripe credential is enough to treat billing as live: the publishable key alone
     * puts the payment form in front of a customer, and the secret alone lets the server talk
     * to Stripe. Requiring both would withhold the disclosure from a half-configured
     * deployment that is nonetheless sending people to a checkout.
     */
    protected function paymentProvider(): ?string
    {
        return filled(config('cashier.key')) || filled(config('cashier.secret'))
            ? 'Stripe'
            : null;
    }

    /**
     * The email provider, or null when no mail actually leaves this deployment.
     *
     * Gated on `SendContactMessageController::mailDeliverable()`, the same allowlist the
     * contact form uses to decide whether to render at all, so the two cannot disagree about
     * whether this deployment sends mail. `sendmail` returns null on purpose: it hands the
     * message to a binary on the operator's own machine, so there is no third party to name.
     * For a plain SMTP relay the recipient IS the configured host, which is the honest thing
     * to publish and the only name configuration knows.
     */
    protected function emailProvider(): ?string
    {
        if (! SendContactMessageController::mailDeliverable()) {
            return null;
        }

        $mailer = (string) config('mail.default');
        $transport = (string) config("mail.mailers.{$mailer}.transport", $mailer);

        return match ($transport) {
            'ses', 'ses-v2' => 'Amazon SES',
            'postmark' => 'Postmark',
            'resend' => 'Resend',
            'smtp' => (string) config("mail.mailers.{$mailer}.host") ?: null,
            default => null,
        };
    }

    /**
     * The push and text-message provider, or null while the push app is unprovisioned.
     *
     * The same `app_id` check the notification itself makes before advertising the driver
     * ({@see IncidentOpened}): with no app id nothing is dispatched, so nothing is received.
     */
    protected function pushProvider(): ?string
    {
        return filled(config('magic-starter.onesignal.app_id')) ? 'OneSignal' : null;
    }

    /**
     * The object-storage provider, or null while user files stay on the operator's own server.
     *
     * The disk under test is the PROFILE PHOTO disk, because that is the only user-content
     * disk a deployment can move: rendered status-page images are pinned to
     * {@see StatusPage::PREVIEW_DISK}, a class constant no configuration reaches. Reading
     * `filesystems.default` instead would answer a question this page never asks.
     */
    protected function objectStorage(): ?string
    {
        $disk = (string) config('magic-starter.profile_photo_disk', 'public');
        $driver = (string) config("filesystems.disks.{$disk}.driver");

        if ($driver === '' || $driver === 'local') {
            return null;
        }

        return $disk === StatusPage::PREVIEW_DISK ? null : strtoupper($driver);
    }

    /**
     * How many cookie families the analytics layer can store on a visitor's device.
     *
     * Zero here is a measured fact rather than a claim: the read-only marketing routes are
     * registered outside the middleware group that starts a session, so no page a visitor
     * reads sets anything of its own, the contact form's POST included. It stops being true the
     * moment an analytics container is configured, which is exactly why the page counts instead
     * of asserting: the sentence cannot go stale behind the configuration.
     *
     * Scoped to the ANALYTICS layer, and the page says so, because it is the only browser
     * storage this deployment's own configuration describes. What a Turnstile challenge keeps
     * on the device is Cloudflare's and is not enumerable from here, so the page names it in
     * the paragraph that names the widget rather than folding an unknown into a figure.
     */
    protected function cookieFamilyCount(): int
    {
        return filled(config('analytics.gtm_container_id')) ? count(self::ANALYTICS_COOKIE_FAMILIES) : 0;
    }

    /**
     * How many third-party scripts a page on this website can load into a visitor's browser.
     *
     * The figure the zero-embed claim reads, and it takes TWO keys because two independent ones
     * put a third-party script on this site: `config/services.php` named that coupling in a
     * comment ("the Privacy page's cookie section has to be revisited with it") and left it as
     * a comment, which is the one thing on this page that was not wired to the configuration it
     * describes. A derivation reading the analytics key alone kept promising no third-party
     * embed on a deployment whose contact form loads one.
     *
     * Both arms mirror the gate the markup itself uses. Turnstile's includes deliverability
     * because the widget rides the contact FORM (`ShowContactController::viewData()`), and with
     * no deliverable mailer the form is not rendered at all, so a site key alone loads nothing.
     */
    protected function thirdPartyScriptCount(): int
    {
        return count(array_filter([
            // `marketing/analytics.blade.php`, in the head of every marketing page.
            'tag_manager' => $this->analyticsProvider() !== null,
            // `marketing/contact.blade.php`, on the contact page only.
            'challenge' => $this->challengeWidgetLoads(),
        ]));
    }

    /**
     * Whether the contact form's anti-abuse challenge actually loads Cloudflare's script.
     *
     * The SITE key and not the secret: the secret gates verification
     * ({@see SendContactMessageController::rules()}), while the site key is what puts
     * `challenges.cloudflare.com` in the markup, and this page is answering the question of
     * what runs in the visitor's browser.
     */
    protected function challengeWidgetLoads(): bool
    {
        return SendContactMessageController::mailDeliverable()
            && filled(config('services.turnstile.site_key'));
    }

    /**
     * This page's own meta description.
     *
     * Per page and never the landing page's sentence: it is what a crawler and a link
     * preview show, so a document reusing the home page's summary tells both that the two
     * are the same document.
     */
    protected function summary(): string
    {
        return __('What Uptizm stores about you, why it stores it, and how long it keeps it.');
    }
}
