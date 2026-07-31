<?php

namespace App\Http\Controllers\Marketing;

use App\Support\Marketing\ChromeData;
use App\Support\Marketing\LegalDocument;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\MessageBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Contact page, in whichever language its URL asked for.
 *
 * The page carries the operator's address always and a form only when this deployment can
 * actually send mail (`SendContactMessageController::mailDeliverable()`). A form that posts
 * into `MAIL_MAILER=log` is worse than an address a visitor can copy, so the offer is
 * withheld rather than decorated with an apology.
 *
 * WHY THE WRITE PATH RENDERS THROUGH THIS CLASS TOO
 *
 * `response()` is public and takes a state override, and `SendContactMessageController` calls
 * it for both of its outcomes: the 422 re-render with the messages and the submitted values,
 * and the 200 acknowledgement. That is the whole reason it exists as a seam. Every response
 * on this URL is the same page in a different state, and it needs the entire chrome contract,
 * the rendered document and a freshly minted form token to be one. A second copy of that
 * assembly in the POST controller would drift, and the way it would drift is a Turkish
 * submission answered with an English chrome, or a re-render whose form has no token in it.
 *
 * This page returns a `Response` rather than a `View` for the same reason: a validation
 * failure has to carry a 422, and a status code is a property of the response, not the view.
 *
 * `$sections` stays at ChromeData's empty default, as on every document page.
 */
class ShowContactController
{
    /**
     * The one view every state of this page renders through, named once so the GET and the
     * POST cannot end up rendering two different templates.
     */
    public const VIEW = 'marketing.contact';

    /**
     * The route path, the Markdown filename and the path `ChromeData` builds this page's
     * canonical and hreflang set from, held as one constant so they cannot drift apart.
     */
    private const PAGE = 'contact';

    public function __construct(
        protected LegalDocument $document,
    ) {}

    public function __invoke(): Response
    {
        return $this->response();
    }

    /**
     * The contact page in a given state.
     *
     * The three overrides the write path uses: `fieldErrors` (the MessageBag from a failed
     * validation), `submitted` (the values to repopulate, since there is no `old()` without a
     * session) and `sent` (render the acknowledgement instead of the form).
     *
     * @param  array<string, mixed>  $state  Merged over the defaults in `viewData()`.
     * @param  int  $status  200 for a rendered page, 422 for a rejected submission.
     */
    public function response(array $state = [], int $status = 200): Response
    {
        return response()->view(self::VIEW, $this->viewData($state), $status);
    }

    /**
     * Everything the contact view dereferences.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function viewData(array $state): array
    {
        $chrome = (new ChromeData(
            path: self::PAGE,
            summary: $this->summary(),
        ))->toArray();

        $formEnabled = SendContactMessageController::mailDeliverable();

        return [
            ...$chrome,
            'title' => __('Contact'),
            // The locale `SetMarketingLocale` set from the path, not the route parameter:
            // the apex form carries no `{locale}` parameter to read.
            'document' => $this->document->render(self::PAGE, app()->getLocale(), $this->replacements()),
            'contactEmail' => (string) config('legal.contact_email'),
            'formEnabled' => $formEnabled,
            'formAction' => $this->formAction($chrome['localeLinks']),
            'formToken' => $formEnabled ? $this->formToken() : null,
            // The two field names the form and the validator have to agree on, handed to
            // the view as data rather than dereferenced there as a fully qualified constant:
            // Blade has no `use` statement, and the alternative is the write path and the
            // template holding two copies of the same string.
            'timestampField' => SendContactMessageController::TIMESTAMP_FIELD,
            'honeypotField' => SendContactMessageController::HONEYPOT_FIELD,
            // The widget renders only when a site key exists, so a deployment that never
            // configured Turnstile loads no third-party script at all. The SECRET key gates
            // verification separately (`SendContactMessageController::rules()`), which is
            // what keeps a half-configured deployment from rejecting every message.
            'turnstileSiteKey' => $formEnabled ? $this->turnstileSiteKey() : null,
            'fieldErrors' => new MessageBag,
            'submitted' => [],
            'sent' => false,
            ...$state,
        ];
    }

    /**
     * The encrypted render timestamp the form ships back.
     *
     * Encrypted rather than signed-and-readable so it carries no meaning to a bot, and minted
     * FRESH on every render including the 422 re-render. The accepted cost of freshness: a
     * visitor who corrects a single character in under three seconds is asked once more to
     * slow down. The alternative, echoing the submitted token back, would hand a bot a
     * pre-aged token that clears the minimum on its second attempt forever.
     */
    protected function formToken(): string
    {
        return Crypt::encryptString((string) now()->timestamp);
    }

    /**
     * Where the form posts.
     *
     * A RELATIVE path, taken from the same `ChromeData` that composed this page's canonical,
     * so the form posts to the exact URL the visitor is reading and a change to the
     * locale-path scheme moves both together. Never `url()` or `route()`: an absolute action
     * posts to `APP_URL`, which is not necessarily the host in the address bar, and a Turkish
     * reader posting to the English path would be answered in English.
     *
     * @param  list<array{code: string, label: string, path: string, url: string, current: bool}>  $localeLinks
     */
    protected function formAction(array $localeLinks): string
    {
        $current = Arr::first($localeLinks, static fn (array $link): bool => $link['current']);

        return is_array($current) ? $current['path'] : '/'.self::PAGE;
    }

    /**
     * The Turnstile site key, or null when Turnstile is not configured on this deployment.
     */
    protected function turnstileSiteKey(): ?string
    {
        $key = config('services.turnstile.site_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * The values interpolated into the Markdown at render time.
     *
     * The contact addresses are the facts on this page that must never be typed into the
     * prose: they appear in two languages, they are what a regulator and a data subject both
     * use, and `config/legal.php` is where a change to them belongs. `LegalDocument` applies
     * these AFTER its cache read, so a config change reaches the page without the Markdown
     * file having to be re-saved.
     *
     * An unmapped placeholder survives into the output verbatim rather than vanishing, so a
     * forgotten entry here shows up as `[[legal.contact_email]]` on the page instead of as a
     * sentence with a hole in it. `LegalPagesTest` fails on exactly that.
     *
     * @return array<string, string>
     */
    protected function replacements(): array
    {
        return [
            '[[legal.contact_email]]' => (string) config('legal.contact_email'),
            '[[legal.rights_email]]' => (string) config('legal.rights_email'),
        ];
    }

    /**
     * This page's own meta description, never the landing page's: a crawler and a link
     * preview both read it, and two pages sharing one sentence claim to be one document.
     */
    protected function summary(): string
    {
        // "The operator" and not "our team": one person runs this service, and the
        // identity block on the Terms page says so.
        return __('How to reach the operator who runs Uptizm.');
    }
}
