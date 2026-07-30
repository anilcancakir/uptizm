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
2. Settings > **Varnish Cache: OFF**. It came on by default. A shared cache in
   front of a tenant-scoped JSON API and per-tenant status pages can serve one
   team's page to another.
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
| `MAIL_*` (a real SMTP provider) | Mail goes to the log. No status-page subscriber receives a confirmation, and no alert email is sent. Cloudflare Email Routing on this domain **receives** only; sending needs a provider. |

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
