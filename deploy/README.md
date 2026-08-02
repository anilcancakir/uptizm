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

## Rebuilding the Flutter client

`.env` is bundled as a pubspec asset, which is not optional: without it
`flutter_dotenv` silently defaults `BROADCAST_CONNECTION` to null on web and
realtime never connects. Everything in it ships to every browser, so
`.env.production` holds only values that are public by design.

```bash
cp .env .env.local.bak && cp .env.production .env
flutter build web --release
mv .env.local.bak .env
rsync -az --delete build/web/ personal:/home/uptizm-app/htdocs/app.uptizm.com/
ssh personal 'chown -R uptizm-app:uptizm-app /home/uptizm-app/htdocs/app.uptizm.com'
```

## Deploying the backend

```bash
ssh personal
sudo -u uptizm -H bash
cd ~/htdocs/uptizm.com && git pull
cd backend
php8.5 /usr/local/bin/composer install --no-dev --optimize-autoloader
php8.5 artisan migrate --force
npm ci && npm run build
php8.5 artisan optimize
exit
supervisorctl restart uptizm:*
```

Two things about that order, both learned the hard way on the first deploy.

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
