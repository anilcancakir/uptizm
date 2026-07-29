<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Preview PNG Rendering

The status-page preview feature renders a team's public status page to PNG via headless Chrome, stored on the private disk and served through signed routes. Provisioning requires:

### Node and Puppeteer

Node 22 LTS or later is required. After `npm ci`, provision the browser binaries explicitly (because `backend/.npmrc` sets `ignore-scripts=true`):

```bash
npx puppeteer browsers install chrome
npx puppeteer browsers install chrome-headless-shell
```

Both binaries must be installed: the renderer calls `newHeadless()` and uses the full `chrome` binary, but the second binary provides a fallback if future code changes the launch mode.

### Dependencies and environment

`puppeteer` is declared as a runtime dependency in `package.json`, not a dev dependency. The browsershot renderer (`vendor/spatie/browsershot/bin/browser.cjs`) requires it at runtime. A production `npm ci --omit=dev` will fail every preview render with "Cannot find module 'puppeteer'".

Do NOT export `TZ` (timezone) to the previews queue worker. Browsershot's node wrapper spreads `...process.env` after `setEnvironmentOptions()`, so an exported `TZ` silently overrides the timezone passed by PHP, and every timestamp rendered into the PNG ends up in an undeclared zone.

### Docker and containerisation

For any containerised deploy, apply these traps or renders will fail silently:

- **`--shm-size=1gb`**: Docker's default `/dev/shm` is 64 MB, too small for Chrome IPC. Runs fail silently with "Failed to use POSIX shared memory" without this.
- **Font packages**: Slim images have no fonts. Install `fonts-liberation fonts-noto-core fonts-noto-cjk fontconfig` and run `fc-cache` to regenerate the font cache. Without this, text renders as Unicode placeholder tofu boxes, and a branded artefact is worse than no PNG.
- **`dumb-init` as PID 1**: Chrome spawns children that become zombies when the parent dies, an unfixed cross-version Puppeteer issue. Run the queue worker under `dumb-init` to reap them.
- **Chrome binary as a patched dependency**: Chromium's CVE stream reaches headless rendering. Pin the Chrome version in your image (never rely on system package manager Chrome, which lags security updates). Match the revision pinned by `puppeteer` in `package-lock.json`.
- **Run the worker as a non-root user.** The renderer deliberately does not call `noSandbox()`, so the Chromium sandbox stays on, and there is no config switch to turn it off. A container running the queue worker as root will fail every render. If a deploy genuinely has to drop the sandbox, that is a code change in `StatusPagePreviewRenderer::browsershotFor()` and a deliberate decision, not an environment variable.

### The worker must be able to reach APP_URL itself

The render is an HTTP fetch of this application's own public status page, issued from the queue worker. So the worker's network has to resolve and reach `APP_URL`, and whatever answers has to be the app rather than an edge in front of it.

This is the mirror image of the reason Cloudflare's hosted browser was rejected for this feature: a renderer that cannot reach the origin cannot render it.

Two failure shapes to watch:

- A WAF or bot challenge in front of the origin returns 200 with a challenge page. The status check passes, the render-ready marker never appears, and the job fails honestly, but the failure names the marker rather than the challenge. If renders fail with a ready-marker timeout and the page loads fine in a browser, suspect this first.
- Signed image URLs are absolute and built from `APP_URL`. An `APP_URL` that disagrees with the host actually serving the request, or an untrusted proxy rewriting the scheme, invalidates the signature and returns 403 on a URL that is otherwise legitimate. Configure `TrustProxies` for the deploy.

### Preview process count is a correctness bound

`config/horizon.php` provisions exactly ONE `previews` process per environment, and `PreviewQueueConfigTest` pins that number. It is not a cost ceiling.

The render job releases its uniqueness lock when processing starts, every render of a page writes the same single key, and each completion stamps `preview_rendered_at = now()`. With two processes, two renders of one page can overlap and the earlier-started one can finish last, storing pre-change pixels under a post-change timestamp. Overlap is the normal save path, since a component sync dispatches a render per detach, attach and reorder.

Raising the count therefore requires per-page serialization first: a cache lock in the job, or one file per render plus an atomic pointer column.

**The precondition, which the number alone does not enforce.** What serializes renders is exactly one consumer of `previews` **globally**. `maxProcesses` bounds one Horizon master on one host, so all three of these restore the overlap without anyone touching the value the test guards:

- A second application instance running its own Horizon.
- `composer dev`, whose `queue:listen` names `previews`, running alongside Horizon locally.
- An ad-hoc `php artisan queue:work --queue=previews` started by hand to drain a stuck queue. This is the obvious recovery move and it silently defeats the bound, so prefer fixing the Horizon supervisor over adding a second worker.

If a deploy cannot guarantee a single consumer, add the mutex: wrap the render-and-write in `RenderStatusPagePreview::handle()` with a `Cache::lock` keyed on the page id. That makes the process count a cost knob again.

### Signed preview URLs and APP_URL

Status-page preview routes are signed with absolute URLs. An `APP_URL` mismatch or an untrusted proxy rewriting the scheme (e.g., terminating TLS upstream) yields a 403 on a legitimate URL, even after the PNG renders successfully. Verify that your `APP_URL` matches the public address clients use and that proxies preserve the original scheme in `X-Forwarded-Proto`.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
