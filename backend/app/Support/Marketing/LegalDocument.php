<?php

namespace App\Support\Marketing;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Parser\MarkdownParser;
use RuntimeException;

/**
 * Renders a localized long-form legal/support Markdown page (Privacy, Terms, Contact, FAQ)
 * into HTML plus a table of contents, with locale fallback and placeholder substitution.
 *
 * Markdown source lives under resources/legal/<page>.<locale>.md, never storage/app/, because
 * storage/app/.gitignore is "*" and legal text must ship in version control.
 *
 * Ported from fluttersdk.com's App\Docs\Rendering\MarkdownRenderer and HeadingExtractor
 * (READ-ONLY reference; not a dependency), collapsed into one class because both passes
 * share the exact same heading_permalink configuration and this pipeline has no separate
 * Blade-compilation stage to justify keeping them apart.
 */
class LegalDocument
{
    /**
     * The heading_permalink config shared by the render pass and the TOC extraction pass, so
     * the TOC slugs are guaranteed to equal the ids the rendered HTML actually carries.
     *
     * Measured against this repo's installed league/commonmark (php artisan tinker) before
     * writing this: the extension's OWN default writes a "content-" prefixed id onto an inner
     * permalink <a> element, not onto the heading itself, e.g. `# A` renders
     * `<h1><a id="content-a" href="#content-a" class="heading-permalink">...</a>A</h1>`. An
     * assertion on `<h2 id=` cannot pass against that default. This config instead forces
     * id_prefix to '' and apply_id_to_heading to true, which writes a bare id directly on the
     * heading element (`<h1 id="a">A</h1>`), which is what an in-page TOC link needs and what
     * this class's tests pin.
     *
     * @var array<string, mixed>
     */
    private const HEADING_PERMALINK_CONFIG = [
        'id_prefix' => '',
        'fragment_prefix' => '',
        'symbol' => '',
        'insert' => 'none',
        'apply_id_to_heading' => true,
    ];

    /**
     * The locale a missing translation falls back to: the app's own default, read from config
     * rather than hardcoded so the fallback cannot drift from the language the site actually
     * defaults to. `routes/marketing.php` registers the unprefixed apex route from this same
     * key.
     *
     * `app.default_locale` and NOT `app.locale`: `App::setLocale()` rewrites the latter for the
     * rest of the request, and every localized page runs under a set locale, so reading
     * `app.locale` here would make the fallback follow the visitor instead of the app.
     */
    private function defaultLocale(): string
    {
        return (string) config('app.default_locale', 'en');
    }

    /**
     * Render a legal/support page to HTML plus a table of contents, with locale fallback and
     * placeholder substitution.
     *
     * @param  string  $page  The page slug, e.g. "privacy", matching resources/legal/<page>.<locale>.md.
     * @param  string  $locale  The requested locale, e.g. "tr". Falls back to the default
     *                          locale when that file is missing; missing for both is a hard failure.
     * @param  array<string, string>  $replacements  Bracketed placeholder => value, e.g.
     *                                               ['[[legal.address]]' => '...']. Applied with strtr AFTER the cache read (see the
     *                                               comment on the cache line below for why the ordering is load-bearing). An
     *                                               unreplaced placeholder is left in the output verbatim rather than stripped, so a
     *                                               missing mapping is visible on the page instead of a silently blank legal notice.
     * @return array{html: string, toc: array<int, array{level: int, text: string, slug: string}>}
     *
     * @throws RuntimeException When neither the requested locale's file nor the default
     *                          locale's file exists.
     */
    public function render(string $page, string $locale, array $replacements = []): array
    {
        $path = $this->resolvePath($page, $locale);

        // The cache key is built from page, locale and the file's mtime ONLY, never from
        // $replacements. Steps 6, 7 and 8 gate acceptance on interpolated config values (the
        // operator address, contact email, retention days), and those values change without
        // touching the Markdown file, so a key that could see $replacements (or a payload that
        // baked them in) would need the file to be re-saved before a config change ever
        // reached the page. rememberForever is safe here specifically because mtime is part
        // of the key: a content edit produces a new key and the old entry is simply orphaned,
        // never served stale.
        $cacheKey = sprintf('legal-document:%s:%s:%d', $page, $locale, filemtime($path));

        $cached = Cache::rememberForever($cacheKey, fn (): array => $this->renderFile($path));

        // Substitution runs AFTER the cache read, never before, and is never folded into the
        // cached payload. backend/phpunit.xml sets CACHE_STORE=array, so a naive
        // implementation that baked $replacements into the cached value would still pass a
        // superficial test while production served stale text indefinitely, because a
        // config-only change never changes the Markdown file's mtime.
        return $this->applyReplacements($cached, $replacements);
    }

    /**
     * Resolve the Markdown file for the requested locale, falling back to the default locale.
     *
     * @throws RuntimeException When neither file exists.
     */
    private function resolvePath(string $page, string $locale): string
    {
        $requested = resource_path("legal/{$page}.{$locale}.md");

        if (is_file($requested)) {
            return $requested;
        }

        $default = $this->defaultLocale();
        $fallback = resource_path("legal/{$page}.{$default}.md");

        if (is_file($fallback)) {
            return $fallback;
        }

        throw new RuntimeException(
            "Legal document \"{$page}\" has no Markdown file for locale \"{$locale}\" or the ".
            "default locale \"{$default}\".",
        );
    }

    /**
     * Read and render one Markdown file into the cacheable payload: rendered HTML plus TOC.
     *
     * @return array{html: string, toc: array<int, array{level: int, text: string, slug: string}>}
     */
    private function renderFile(string $path): array
    {
        $markdown = file_get_contents($path);

        if ($markdown === false) {
            throw new RuntimeException("Unable to read legal document at \"{$path}\".");
        }

        return [
            'html' => $this->renderHtml($markdown),
            'toc' => $this->extractHeadings($markdown),
        ];
    }

    /**
     * Render Markdown to HTML with heading permalinks. html_input is left at this installed
     * league/commonmark's own default (allow, verified in
     * vendor/league/commonmark/src/Environment/Environment.php) so an authored
     * `<details>/<summary>` block (the FAQ page) survives the render. allow_unsafe_links is
     * forced to false regardless of authorship: these files are admin-curated, not user
     * input, but a legal page is exactly the wrong place to rely on that alone. There is no
     * Blade-compilation pass afterward, unlike the docs pipeline this was ported from, because
     * these documents contain no dynamic expressions.
     */
    private function renderHtml(string $markdown): string
    {
        return Str::markdown($markdown, [
            'allow_unsafe_links' => false,
            'heading_permalink' => self::HEADING_PERMALINK_CONFIG,
        ], [new HeadingPermalinkExtension]);
    }

    /**
     * Walk the CommonMark AST and return the ordered heading list: level, text and the
     * permalink slug HeadingPermalinkProcessor assigned. Parsing separately from renderHtml()
     * (rather than scraping the rendered HTML with a regex) guarantees the slug here is
     * identical to the id renderHtml() actually emits, including CommonMark's
     * duplicate-heading disambiguation suffix.
     *
     * @return array<int, array{level: int, text: string, slug: string}>
     */
    private function extractHeadings(string $markdown): array
    {
        $environment = new Environment([
            'heading_permalink' => self::HEADING_PERMALINK_CONFIG,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new HeadingPermalinkExtension);

        $document = (new MarkdownParser($environment))->parse($markdown);

        $headings = [];

        foreach ($document->iterator() as $node) {
            if (! $node instanceof Heading) {
                continue;
            }

            $text = '';
            foreach ($node->iterator() as $inline) {
                if ($inline instanceof Text) {
                    $text .= $inline->getLiteral();
                }
            }

            $attributes = $node->data->get('attributes', []);
            $slug = is_array($attributes) ? ($attributes['id'] ?? '') : '';

            $headings[] = [
                'level' => $node->getLevel(),
                'text' => $text,
                'slug' => (string) $slug,
            ];
        }

        return $headings;
    }

    /**
     * Apply $replacements to the cached payload with strtr: the whole substitution mechanism,
     * deliberately not Blade (these documents contain no dynamic expressions, and
     * Blade-compiling user-facing legal text is an injection surface for no benefit). Heading
     * text is substituted too, so a placeholder inside a heading does not leak into the TOC.
     *
     * @param  array{html: string, toc: array<int, array{level: int, text: string, slug: string}>}  $document
     * @param  array<string, string>  $replacements
     * @return array{html: string, toc: array<int, array{level: int, text: string, slug: string}>}
     */
    private function applyReplacements(array $document, array $replacements): array
    {
        if ($replacements === []) {
            return $document;
        }

        $document['html'] = strtr($document['html'], $replacements);

        $document['toc'] = array_map(
            static fn (array $heading): array => [
                ...$heading,
                'text' => strtr($heading['text'], $replacements),
            ],
            $document['toc'],
        );

        return $document;
    }
}
