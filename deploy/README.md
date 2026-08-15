# Production deployment

Target: the `can` VPS (`185.172.57.108`), CloudPanel v2, Ubuntu 24.04. The
operational runbook for the box itself lives in
`/Users/anilcan/Code/devops/kodizm/AGENTS.md`.

Four hostnames, two CloudPanel sites, one Laravel application:

| Hostname | Serves | CloudPanel site |
|---|---|---|
| `uptizm.com` | landing page, `/s/{slug}` status pages | `uptizm.com` (php) |
| `*.uptizm.com` | `{slug}.uptizm.com` status pages | same |
| `api.uptizm.com` | `api/v1`, `/app` websocket, `/horizon` | same |
| `app.uptizm.com` | compiled Flutter web client | `app.uptizm.com` (static) |

`api.uptizm.com` shares the `uptizm.com` vhost and its single Octane instance
rather than getting its own site: it is the same application, and a second Octane
would cost roughly 300 MB on an 8 GB box shared with two other products.

## What is already provisioned

- PostgreSQL: database `uptizm`, role `uptizm`, port **6543** (this cluster does
  not listen on 5432). Password at `/var/lib/postgresql/.uptizm_db_pass`.
  `timezone` is pinned to **UTC for this database only**, because the cluster
  runs `Europe/Istanbul` and Laravel binds naive datetimes; the two together
  would shift every `timestamptz` write by the offset. `CONNECT` is revoked from
  `PUBLIC`, so only the owner and superusers can reach it.
- TimescaleDB 2.28.2 is enabled in that database. `monitor_checks` and
  `monitor_metric_values` are hypertables with 90-day retention policies
  attached. Enabling it needed `timescaledb` in `shared_preload_libraries`, which
  `postgresql.auto.conf` had been overriding; backups of both config files are at
  `*.bak-2026-07-30`.
- Code at `/home/uptizm/htdocs/uptizm.com` (the whole monorepo; Laravel lives in
  `backend/`). Deploy with `git pull` as the `uptizm` user.
- Node 22 (system-wide, NodeSource) plus the full Chrome 148 under
  `/home/uptizm/.cache/puppeteer`, owned by `uptizm` so the Horizon worker can
  resolve it. Chrome's sandbox needed `/etc/apparmor.d/uptizm-chrome`: Ubuntu
  24.04 restricts unprivileged user namespaces and Chrome refuses to start
  without one. The profile grants `userns` to that binary only, which is the fix
  Chromium documents; `--no-sandbox` was rejected because the rendered status page
  carries tenant-controlled content.
- Supervisor group `uptizm`: Octane on **9502**, Horizon, Reverb on **6002**, and
  the scheduler as `schedule:work`. All four run as `user=uptizm` from their own
  path with `autostart=true`.
- **PHP's tracing JIT is off**, box-wide, in `conf.d/zz-nojit.ini`
  (`opcache.jit=disable`) for four ini sets: 8.5 and 8.4, cli and fpm. Every site
  here is a Laravel application, so the JIT buys effectively nothing (the time goes
  to PostgreSQL, Redis and HTTP, and compiling to machine code only speeds up PHP's
  own arithmetic), while `php/php-src#15145` is OPEN and makes it emit WRONG machine
  code, which does not fail the way slow code fails. Measured on 2026-08-12: ~1,400
  silent php8.5 segfaults a day, every one a kodizm scheduled command, with nothing
  in any PHP log because the process dies below PHP; plus 11 `Undefined variable
  $result` aborts of a whole `schedule:run` minute on 2026-08-10
  (`laravel/framework#58065` carries the same trace, closed with no code change).
  Splitting the kernel log on the ini file's own mtime gives 27 before and 0 after,
  and a reaper that had been dying now exits 0. OPcache itself stays ON, which is
  the optimisation that actually matters. Three things before touching this:
  `opcache.jit=tracing` is written in THREE places per version
  (`mods-available/opcache.ini`, `cli/php.ini`, `fpm/php.ini`), so what wins is a
  scan-dir file named to load LAST rather than an edit to any of them; **all three
  products on this box run Octane through the php8.5 CLI binary**
  (`octane:start --server=frankenphp`), so anything done to the CLI SAPI reaches
  three web tiers and not just a scheduler; and a long-lived process reads its ini
  only at start, so short-lived work (cron, `schedule:run`, queue, artisan) picks a
  change up immediately while an Octane instance carries the old value until it is
  restarted.
- The Cloudflare worker `uptizm-regional-checker` is redeployed from this repo
  with the correct `env.RegionalProbe` binding, and its `RELAY_SECRET` matches the
  backend. A signed probe was verified end to end (us-east, HTTP 200).
- The Flutter web build is uploaded to
  `/home/uptizm-app/htdocs/app.uptizm.com`.
- `public/storage` is symlinked. Profile photos default to the `public` disk
  (`magic-starter.profile_photo_disk`), so without the link every uploaded avatar
  would 404. Status-page preview PNGs are on the private disk and served through a
  signed route instead, so they do not depend on it.

## Still to do in the CloudPanel UI

Nginx configs are never edited on the server; CloudPanel regenerates them on
save. Paste the files from this directory into the panel instead.

**Site `uptizm.com`:**

1. Settings > **Site Root** = `uptizm.com/backend/public`. This is a monorepo, so
   Laravel's public directory is one level deeper than CloudPanel's default. The
   vhost renders `{{root}}` from this setting, so a mismatch serves the wrong
   directory.
2. Varnish Cache: leave it alone. It came on by default, and it does not matter:
   the vhost in this directory hardcodes `proxy_pass http://127.0.0.1:9502`
   instead of using CloudPanel's `varnish_proxy_pass` placeholder, so Varnish is
   not in the request path at all. Verified: zero occurrences of `varnish` or
   `6081` in the rendered config, and the running Varnish on 6081 belongs to
   another site. The panel's own toggle and its `varnish_cache` database flag
   disagree with each other here; that is a panel quirk, not something to chase.
   It WOULD matter if the vhost ever went back to that placeholder, since a
   shared cache in front of a tenant-scoped API can serve one team's page to
   another.
3. Vhost > paste `vhost-uptizm.com.conf`, Save.
4. SSL/TLS > **Import Certificate**. Paste from the server:
   ```bash
   ssh personal 'cat /root/uptizm-origin-cert/origin.crt'   # certificate
   ssh personal 'cat /root/uptizm-origin-cert/origin.key'   # private key
   ```
   This is a Cloudflare Origin CA certificate covering `uptizm.com` and
   `*.uptizm.com`, valid to 2041. The wildcard is required: the zone is on
   **Full (strict)**, and Cloudflare connects to the origin with the visitor's own
   hostname as SNI, so `acme.uptizm.com` must be on the certificate. CloudPanel's
   Let's Encrypt flow cannot issue a wildcard (it uses HTTP-01). The certificate
   CloudPanel generated at site creation is self-signed, and Full (strict) rejects
   it with a 526.

**Site `app.uptizm.com`:**

5. Settings > **Allow traffic from Cloudflare only: ON**. It came off. Without it
   the origin address bypasses Cloudflare.
6. Vhost > paste `vhost-app.uptizm.com.conf`, Save.
7. SSL/TLS > Import the **same** certificate and key: `*.uptizm.com` covers this
   host too.

**Cloudflare dashboard:**

8. SSL/TLS > Edge Certificates > **Always Use HTTPS: ON** (currently off).

## Configuration still missing, and what it costs

Each of these is empty in `backend/.env` on purpose, so the feature fails closed
rather than pretending to work.

| Key | Until it is set |
|---|---|
| `ANTHROPIC_API_KEY` | AI triage is inert. No suggestion is invented. |
| `STRIPE_KEY` / `STRIPE_SECRET` / `STRIPE_WEBHOOK_SECRET` | Billing endpoints answer, but no checkout completes and the webhook cannot verify a signature. |
| `MAIL_MAILER` / `RESEND_KEY` | Mail goes to the log. No status-page subscriber receives a confirmation, no alert email is sent, and the contact form is not rendered at all. Cloudflare Email Routing on this domain **receives** only; sending needs a provider. The cutover is the next section. |
| `APP_OWN_STATUS_PAGE_URL` | The landing page footer has no link to a status page of our own. Every competitor in this category publishes one, so its absence is visible. |

Two of those now change what the landing page SAYS, not just what the product
does. `ShowLandingController` derives the page's feature claims from the
deployment's actual capabilities, so a missing key removes a claim rather than
leaving a false one:

- no AI provider key -> the whole AI section is withheld, because without it every
  AI path returns its deterministic fallback and the section would be selling the
  fallback
- `MAIL_MAILER=log` -> the status-page subscriber promise is withheld, because the
  confirmation email a subscriber waits for is written to a file

So the page currently goes live in a reduced form. Set the mail transport and an
AI key first if you want it whole. `LandingPageTest` pins both directions of both
gates, so neither can be quietly inverted.

## Turning on mail, the contact form and the captcha

One transport carries the contact form, the status-page subscriber
confirmations and every alert email, and all three are inert until it is real.
This is a one-time cutover with four parts: a Resend key, the domain's DNS
records, the Turnstile key pair, and the contact address. None of them can be
done from the repository, which is why they are here and not in a migration.

**1. Install the transport.** `resend` is already the mailer in
`backend/config/mail.php` and already on the contact form's sending-transport
allowlist, but Laravel only *suggests* the SDK, so it is not in `composer.lock`:

```bash
sudo -u uptizm -H bash
cd ~/htdocs/uptizm.com/backend
php8.5 /usr/local/bin/composer require resend/resend-php
```

`php8.5` explicitly, for the same reason the deploy section below uses it: the
default `composer` on this box resolves under PHP 8.3 while the site runs 8.5.
The transport is constructed lazily, so a missing package does not fail at boot;
it fails inside the queued job, after the visitor has been told the message was
accepted.

**2. Verify the sending domain in Resend.** Add the domain there and take the
`send.` subdomain Resend offers rather than the apex: it keeps sending
reputation off the name Email Routing already receives on. Resend then prints
the records to create (an MX and an SPF `TXT` on that subdomain, and a DKIM
`TXT` at `resend._domainkey`). Paste them exactly as printed; the values are
account- and region-specific, so a remembered one is a wrong one.

Three things about adding them in the Cloudflare dashboard:

- Anything Resend gives you as a `CNAME` must be **DNS only** (grey cloud).
  Cloudflare proxies new CNAMEs by default, and a proxied mail record resolves
  to the edge address instead of the provider.
- **One SPF `TXT` per name, ever.** Email Routing already publishes
  `v=spf1 include:_spf.mx.cloudflare.net ~all` on the apex for receiving, and
  two SPF records on one name is a permanent error under RFC 7208 that fails
  *every* check, including the one that was working before. If Resend's SPF
  lands on a name that already has one, merge the `include:` into the existing
  record rather than adding a second record.
- Do not delete the Email Routing MX records while adding these. Sending and
  receiving are independent, and those are what make `info@uptizm.com` arrive:
  the address the contact page prints as its fallback.

DMARC is not required for delivery and is worth adding once SPF and DKIM verify:
one `TXT` at `_dmarc` reading `v=DMARC1; p=none; rua=mailto:<a mailbox you
read>` reports without rejecting anything.

**3. Point the application at it.** In `backend/.env`:

```
MAIL_MAILER=resend
RESEND_KEY=<from the Resend dashboard>
MAIL_FROM_ADDRESS=noreply@send.uptizm.com
MAIL_FROM_NAME=Uptizm
LEGAL_CONTACT_EMAIL=info@uptizm.com
LEGAL_RIGHTS_EMAIL=info@uptizm.com
```

`MAIL_FROM_ADDRESS` has to be on the domain that was verified, and it has to
stop being the framework's `hello@example.com`: that placeholder is one of the
things the contact form's gate refuses, so leaving it renders no form even with
a working transport. `LEGAL_CONTACT_EMAIL` is both the address the form sends
*to* and the fallback printed on the page whenever the form is withheld; the
gate runs `FILTER_VALIDATE_EMAIL` over it, so it must be one valid address and
not two, and Email Routing has to forward it to a mailbox somebody reads.
`LEGAL_RIGHTS_EMAIL` is the GDPR/KVKK rights channel published beside it; both
still default to `info@kodizm.com`, which is the wrong domain for this product.

```bash
php8.5 artisan config:clear && php8.5 artisan optimize
exit
supervisorctl restart uptizm:*
```

The restart is not optional: Octane holds the config in memory for the life of a
worker, so an `.env` edit without it changes nothing that is serving traffic.

**4. Turn on Turnstile.** Cloudflare dashboard > Turnstile > add a Managed
widget for `uptizm.com`, then put **both** keys in `backend/.env`:

```
TURNSTILE_SITE_KEY=<the widget's site key>
TURNSTILE_SECRET_KEY=<the widget's secret>
```

Both or neither. With neither, the widget is dormant, the page loads no
Cloudflare script and the form works without a captcha. With only the secret,
the page renders no widget while the POST demands a token, so every visitor is
refused with "Please complete the verification challenge" and none of them can
tell you why. Restart as above; these are config too.

**5. Read the log for a day afterwards.** A mis-copied secret is invisible from
the outside: siteverify answers `invalid-input-secret` and the visitor reads the
same generic "Verification failed" a bot reads. `TurnstileRule` therefore writes
the reason down, and separates whose fault it is:

```bash
sudo -u uptizm -H bash -c 'cd ~/htdocs/uptizm.com/backend && php8.5 artisan pail --filter=Turnstile'
```

`Turnstile refused a contact form submission` at **warning** is ordinary traffic
being turned away. `Turnstile is rejecting every submission because of this
deployment` at **error** means the key pair is wrong and nobody can reach us
through the form at all; fix the secret before anything else. The rule never
changes what the visitor is told, so this line is the only signal there is.

**6. Confirm it end to end.** Load `https://uptizm.com/contact` and check that a
form renders at all: no form means the gate is still closed, and the cause is
one of `MAIL_MAILER`, `MAIL_FROM_ADDRESS` or `LEGAL_CONTACT_EMAIL`. Send a
message, watch the job in Horizon, and confirm the delivery in the Resend
dashboard. The page says "accepted" and never "arrived" because the send is
queued, so the provider's dashboard is the only place the actual delivery is
visible.

## Turning on Sentry

Three projects under the `kodizm-sentry` org, one per half of the system:
`uptizm-api`, `uptizm-app` and `uptizm-worker`. Two of the three are already wired
by files in this repository and need nothing here: the Flutter DSN is in
`.env.production` and the Worker DSN is in `wrangler.toml`, because a DSN is
write-only and both of those bundles are public anyway.

The backend is the one that needs a hand, because its DSN belongs in the server's
own `.env`:

```bash
ssh personal
sudo -u uptizm -H bash
cd ~/htdocs/uptizm.com/backend
echo 'SENTRY_LARAVEL_DSN=https://<key>@o4511168697794560.ingest.de.sentry.io/4511908893294672' >> .env
php8.5 artisan optimize
```

Nothing reports until that is set AND `APP_ENV=production`, which is a gate in
`config/sentry.php` rather than an accident: a DSN on a developer machine would
spend quota that this org cannot replace, since the plan carries
`onDemandMaxSpend = 0` and a spent quota drops real production events for the rest
of the month rather than billing for more.

**Profiling is OFF and installing excimer here does not work. Do not spend an
afternoon on it.** Both blockers were measured on this box on 2026-08-14:

| | version | thread safety | extension dir |
|---|---|---|---|
| frankenphp (Octane, HTTP) | 8.5.6 | ZTS | `/usr/lib/frankenphp/modules` |
| system CLI (Horizon, scheduler) | 8.5.7 | NTS | `/usr/lib/php/20250925` |

Two separate PHP builds run this application, so an extension built for one is
invisible to the other. The frankenphp binary is a self-contained 59 MB build
with its own embedded PHP; it exposes no `php-config` and its module directory
is empty, so the web tier cannot be profiled without rebuilding it from source.

And the CLI half cannot be built either, today. `sudo pecl install excimer`
SUCCEEDS and is still useless: the box carries `phpize8.4` and PHP 8.4 headers
only, so it compiles 1.2.6 into `/usr/lib/php/20240924/` and `php8.5 -m` never
shows it. That is the trap, because nothing in the output says the target was
wrong. Verify rather than trust:

```bash
php8.5 -m | grep -i excimer || echo "not loaded by php8.5"
```

Making the queue half work would mean `sudo apt install php8.5-dev` and a
rebuild against `phpize8.5`. That was considered and declined: it adds a system
package for a function-level CPU breakdown, on the tier that is not the slow
one. `SENTRY_PROFILES_SAMPLE_RATE=0` is set in the server's `.env` and the
config defaults to `0.0`.

**None of this affects request timing or query performance.** Tracing does not
use excimer at all: spans, per-query duration and the call site of any query
over 100ms are built on Laravel's own events and work today. Profiling would
only have added a CPU breakdown of the time those spans do not already explain.

**There is no per-key rate limit to set, and looking for one is a dead end.**
Sentry's client-key rate limit is a Business-plan feature; this org is on Team,
so the field is absent from the UI. The API hides that badly: a `PUT` carrying
`rateLimit` answers 200 and keeps `null`, while the same `PUT` renames the key
happily, so it reads as a request that worked.

The flood it would have capped is real. A relay outage fails every check job,
`PerformMonitorCheck` retries three times, and at a thousand jobs a minute that
is ~3000 events a minute against a 50,000 per MONTH allowance with no overage
budget: under twenty minutes to spend the rest of the month's visibility, during
the one incident class this product exists to detect.

So the cap lives in the application instead. `App\Support\Sentry\SentryEventThrottle`
runs inside `before_send` and allows one event per distinct fault per minute,
which turns that outage into ~60 events an hour and loses nothing an operator
reads (Sentry groups identical faults into one issue anyway). It fails OPEN, so
a Redis outage costs quota rather than visibility. Nothing to configure at
deploy time; it is listed here because the absent UI control is the first place
anyone will look.

**`sentry-cli` runs from your machine, not the server.** It needs a token with
`org:read`, `project:read`, `project:write` and `project:releases`; the release and
source-map steps in the deploy sections below assume `SENTRY_AUTH_TOKEN` and
`SENTRY_ORG` are already in your shell profile.

## Deploying: what runs in what order

Three things ship separately and the order between two of them is a contract, not a
preference. Do not follow the order the sections happen to appear in below.

1. **The backend** (`## Deploying the backend`), including `migrate` and the asset
   build, ending with `supervisorctl restart`.
2. **The regional checker** (`## Deploying the regional checker`), last. A worker
   deployed ahead of the backend sends a payload the backend cannot store yet.
3. **The Flutter client** (`## Rebuilding the Flutter client`), whenever. It talks to
   `api/v1` and has no ordering constraint against the other two, but a deploy that
   changed a client-visible endpoint shape wants it promptly.

Then `## Proving the deploy landed`, which is three separate checks because these
are three separate deploys.

## Rebuilding the Flutter client

`.env` is bundled as a pubspec asset, which is not optional: without it
`flutter_dotenv` silently defaults `BROADCAST_CONNECTION` to null on web and
realtime never connects. Everything in it ships to every browser, so
`.env.production` holds only values that are public by design.

```bash
cp .env .env.local.bak && cp .env.production .env
RELEASE="$(git rev-parse --short HEAD)"
sed -i '' "s|^SENTRY_RELEASE=.*|SENTRY_RELEASE=$RELEASE|" .env
flutter build web --release --source-maps
mv .env.local.bak .env
sentry-cli sourcemaps upload --project uptizm-app --release "$RELEASE" build/web
rsync -az --delete build/web/ personal:/home/uptizm-app/htdocs/app.uptizm.com/
ssh personal 'chown -R uptizm-app:uptizm-app /home/uptizm-app/htdocs/app.uptizm.com'
```

Three of those lines are new and each one fails quietly if you drop it.

**`SENTRY_RELEASE` is written into `.env`, not exported.** The Flutter build reads
its configuration from the bundled asset, not from your shell, so an exported
variable reaches nothing. `.env.production` ships that key empty on purpose and the
`sed` fills it for this build only, which is why the restore on the next line puts
your development `.env` back untouched.

**`--source-maps` is what makes a stack trace readable.** Without it every issue
from the web client names a minified `dart2js` symbol, and web is this product's
primary surface. Note that the maps are uploaded and then NOT shipped: `rsync`
sends `build/web/`, which contains them, so if you would rather not serve `.map`
files publicly, delete them after the upload and before the sync.

**The release name must match on both sides or the maps are ignored.** The same
`$RELEASE` goes into the bundle and into the upload, which is the entire contract.
A mismatch produces no error anywhere: the events arrive, the maps sit in Sentry,
and nothing connects them. `sentry-cli releases files "$RELEASE" list` is how you
check that a release actually has artifacts.

## Deploying the backend

```bash
ssh personal
sudo -u uptizm -H bash
cd ~/htdocs/uptizm.com && git pull && cd backend && php8.5 artisan migrate --force
php8.5 /usr/local/bin/composer install --no-dev --optimize-autoloader
npm ci && npm run build
RELEASE="$(git rev-parse --short HEAD)"
grep -q '^SENTRY_RELEASE=' .env \
  && sed -i "s|^SENTRY_RELEASE=.*|SENTRY_RELEASE=$RELEASE|" .env \
  || printf 'SENTRY_RELEASE=%s\n' "$RELEASE" >> .env
php8.5 artisan optimize
exit
supervisorctl restart uptizm:*
```

Then, from your own machine, tell Sentry the release exists and which commits are
in it:

```bash
RELEASE="$(git rev-parse --short HEAD)"
sentry-cli releases new --project uptizm-api "$RELEASE"
sentry-cli releases set-commits --auto --ignore-missing "$RELEASE"
sentry-cli releases finalize "$RELEASE"
```

**Append when the key is absent, do not just `sed`.** A bare substitution is a
no-op on a file that has no `SENTRY_RELEASE=` line, and it exits 0 while doing
it, so the deploy looks clean and every event afterwards is filed against no
release at all. The server's `.env` had neither Sentry key when this landed, and
a plain `sed` would have silently skipped both.

**The write runs before `artisan optimize`, and that order is the whole point.**
`optimize` runs `config:cache`, which evaluates every `env()` call once and freezes
the result; a release written after it would sit in `.env` doing nothing until the
next deploy happened to cache it. The same freeze is what makes the DSN work at
all, since `config/sentry.php` only reads it when `APP_ENV` is `production`.

**`set-commits --auto` is what makes an issue name a commit.** Without it the
release exists and its "suspect commits" panel stays empty, which is most of the
reason to create a release in the first place.

**`--ignore-missing` is not optional here, and it is this repo's merge style that
makes it so.** Pull requests land squashed, so the previous release's SHA is a
commit that no longer exists in any history; without the flag `set-commits` fails
with "Could not find the SHA of the previous release in the git history" and the
release ships with no commits attached. With it, Sentry falls back to the last 20
commits, which is the right answer for a squashed history.

Three things about that order, all learned the hard way on a deploy.

**`migrate --force` is chained to the `git pull` and runs BEFORE `composer install`.**
An additive nullable column is safe ahead of the code and unsafe behind it, and the
gap between the pull and the migration is where that bites: the new classes are on
disk, this box writes a check every few seconds, and a queue worker that loads a new
class and inserts a column the database does not have yet is rejected by PostgreSQL
*after* its check row has already committed. The job lands in `failed_jobs`, so an
outage inside that window opens no incident and nothing says why. Chaining the two
shortens the window to one migration's runtime. A migration is loaded from its own
file and needs no regenerated autoloader, which is what makes running it first safe.

**Run `npm run build` on any deploy that touched Blade**, not just CSS or JS.
Tailwind generates its output from the class strings it finds in the templates, so
a Blade change introducing a utility the previous build never saw produces markup
referencing a class that is not in the stylesheet. It fails silently and visually:
the element renders with no styling. This bit us on a `bg-gray-400` status dot that
simply did not appear.

**Restart Octane AFTER the asset build, never before.** Octane holds the Vite
manifest in memory for the life of the worker, so a build followed by no restart
leaves every page linking the PREVIOUS content hash, which the build has already
deleted from disk. The page then references a stylesheet that 404s and renders
completely unstyled. Cloudflare hides this for a while by serving the old hashed
file from its own cache (those assets go out with `expires max`), so the breakage
surfaces later and looks unrelated to the deploy. The command order above is
correct; do not reorder it.

Do **not** run `migrate:fresh --seed` here. The seeder creates the demo account
(`demo@uptizm.test`) and is local/dev only.

**Read what `migrate` is about to do before running it.** `migrate:status | grep -i
pending` lists it, and any migration that drops or truncates deserves a row count
first:

```bash
php8.5 artisan tinker --execute='echo DB::table("some_table")->count();'
```

A drop of an empty table is a schema change. A drop of a populated one is data
loss, and this box has no automated restore.

**Confirm `artisan` still answers after `composer install`.** `php8.5 artisan
--version` is enough. A package removed from `composer.json` can resurrect from
`vendor/composer/installed.json` and break every artisan command while Octane keeps
serving 200s from workers that already booted, so the deploy looks fine until the
next queue job or the next restart.

## Deploying the regional checker

**The backend deploy does not ship the Worker.** Nothing in the section above
touches Cloudflare, so a deploy that changed `backend/workers/regional-checker/`
leaves the edge running whatever was there before. That failure is silent and it
looks exactly like success: the backend stores the new columns, the edge never
sends them, and every check records NULL.

It happened on 2026-08-05. The backend went out with assertion evaluation and the
edge kept running the 2026-08-01 build for the twenty minutes it took to notice,
publishing `up` for monitors whose assertions were being violated.

```bash
cd backend/workers/regional-checker
npx wrangler deployments list        # what is actually live right now
RELEASE="$(git rev-parse --short HEAD)"
npx wrangler deploy --outdir dist --var SENTRY_RELEASE:"$RELEASE"
sentry-cli sourcemaps upload --project uptizm-worker --release "$RELEASE" dist
```

**`--outdir` is what puts the bundle and its maps on your disk.** `wrangler.toml`
already sets `upload_source_maps = true`, but that hands them to CLOUDFLARE; Sentry
needs its own copy and can only take one from a directory. Without this the worker's
stack traces name a column in a 500 KB bundle.

**`--var` overrides the `SENTRY_RELEASE` binding for this deploy only.** It has to
carry the same value the upload does, for the same reason the Flutter build does:
a mismatch is silent on both ends.

**Server first, edge second**, which `.claude/rules/relay-worker.md` also states: a
worker deployed ahead of the backend sends a payload the backend cannot store yet.
So this is the LAST step of a deploy, after `supervisorctl restart`.

Before deploying, check the `[[migrations]]` block in `wrangler.toml` against what
is live. Wrangler decides which Durable Object migrations to apply by comparing the
deployed tag against that list, and the DO carries live probe traffic:

```bash
# the deployed tag, which `wrangler deployments list` does not show
TOKEN=$(grep -m1 '^oauth_token' ~/Library/Preferences/.wrangler/config/default.toml | sed -E 's/.*= *"([^"]+)".*/\1/')
curl -s -H "Authorization: Bearer $TOKEN" \
  "https://api.cloudflare.com/client/v4/accounts/<account-id>/workers/scripts" \
  | python3 -c 'import json,sys; [print(s["id"], s.get("migration_tag")) for s in json.load(sys.stdin)["result"]]'
```

`wrangler api` does not exist as a subcommand; the REST call above is the way, and
the account's OAuth token already carries the `workers_scripts` scope. Note that
`/workers/services/<name>/environments/production/settings` returns `migration_tag:
null` for every script, so do not read that null as "no migration applied".

## Proving the deploy landed

Three things are deployed separately, so check three things. A 200 is not evidence
for any of them.

**The backend** is a revision comparison, not a page load:

```bash
ssh personal 'sudo -u uptizm -H bash -c "cd ~/htdocs/uptizm.com && git log --oneline -1"'
```

**The Flutter client** is a byte comparison. `app.uptizm.com` returns 200 with the
previous build just as happily as with the new one:

```bash
shasum -a 256 build/web/main.dart.js
curl -s https://app.uptizm.com/main.dart.js | shasum -a 256
```

Also confirm the bundled `.env` carries the production values and not the local
ones, since the build swaps the file in and out:

```bash
curl -s https://app.uptizm.com/assets/.env | grep -E 'API_URL|BROADCAST_CONNECTION'
```

**The admin panel** is the case that proves why a 200 is not evidence. It lives on
its own hostname (`admin.uptizm.com`, from `AdminPanelProvider`'s `->domain()`),
and a broken one still returns 200 with a complete login page: the HTML renders,
the CSS loads, every Filament script loads, and the panel is simply dead, because
Livewire's own script is a ROUTE rather than a file and nginx can answer it before
the application ever sees it. Ask for that one asset by name:

```bash
curl -s -o /dev/null -w '%{http_code} %{content_type}\n' \
  https://admin.uptizm.com/livewire/livewire.min.js
```

`200 application/javascript` is the pass. A `404 text/html` is nginx serving its
own error page, which means the static-asset location in the vhost lost its
`try_files $uri @octane` and every Livewire page on this box is inert. Check the
nginx error log rather than the application log; the application was never asked:

```
openat() ".../public/livewire/livewire.min.js" failed (2: No such file or directory)
```

**The edge** answers only a signed spec, so sign one. Run it ON the box so
`RELAY_SECRET` never leaves it: read `RELAY_URL` and `RELAY_SECRET` from
`backend/.env`, sign `${timestamp}.${body}` with HMAC-SHA256, and POST to
`$RELAY_URL/run` with `X-Relay-Timestamp` and `X-Relay-Signature`. What you are
looking for is behaviour, not a 200: a body assertion the target violates must come
back `status: "down"` with `status_code: 200`.

### Four traps, each of which produced a wrong answer once

**A 502 seconds after `supervisorctl restart` is the restart.** FrankenPHP workers
are still booting. Wait a minute and request twice before believing it.

**A check row is written by the SECOND job, not the first.** `PerformMonitorCheck`
(queue `checks`) calls the relay and dispatches `ProcessCheckResult` on
`processing`, and that one writes the row. Reading `monitor_checks` right after
dispatching returns the PREVIOUS row and reads as "the feature does not persist".
Poll for a row newer than the newest one that existed before.

**Most monitors here do not use the relay at all.** Every monitor on a team with
`is_system = true` is a catalog monitor served by `LocalProbeEngine`, which by
design evaluates no assertions and records NULL. Eight of production's nine
monitors are those. Filter before you measure:

```sql
select m.url from monitors m join teams t on t.id = m.team_id where t.is_system = false;
```

**Check the route exists before calling a 404 a regression.** `artisan route:list`
settles it. Two "regressions" on 2026-08-05 were URLs that had never existed.
