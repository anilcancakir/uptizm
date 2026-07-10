# regional-checker worker

HMAC-signed, **synchronous** probe relay. One Cloudflare Worker, five
region-pinned Durable Objects (`us-east`, `us-west`, `eu-west`,
`eu-central`, `ap`).

Laravel dispatches a signed `POST /run`; the Worker verifies the HMAC,
forwards the payload to the Durable Object matching `body.region`, and
that DO (created with `locationHint`) runs the outbound probe from the
requested geography. The `CheckResultPayload` is returned **inline in the
`/run` response body** — there is no callback. Laravel reads the result
directly off the response.

## The idempotency contract

The inbound `ProbeRequest` carries a `probe_run_id`. The Worker echoes it
back verbatim in the `CheckResultPayload`. Laravel dedups persisted check
results on `probe_run_id`, so a retried `/run` for the same run never
double-writes. The Worker never generates it; it only mirrors it.

## Endpoints

| Method | Path      | Purpose                                                     |
| ------ | --------- | ---------------------------------------------------------- |
| `GET`  | `/health` | Heartbeat: `{"ok":true,"regions":[...]}`                   |
| `POST` | `/run`    | HMAC-verified probe dispatch, synchronous inline result    |

`/run` requires two headers:

- `X-Relay-Timestamp`: unix seconds, must be within 300s of the Worker clock.
- `X-Relay-Signature`: hex HMAC-SHA256 of `${timestamp}.${body}` under `RELAY_SECRET`.

## Timing caveat

Cloudflare's Workers runtime does not expose per-phase TCP/TLS timing to
user code, so `dns_ms` / `connect_ms` / `tls_ms` are always `0` (the
consumer reads a zero as "unknown", not "instant"). Only `ttfb_ms` and
`download_ms` are real wall-clock measurements.

## Local development

`RELAY_SECRET` is read from `.dev.vars` (git-ignored, provisioned out of
band). Never commit it.

```bash
npm install
npm run typecheck        # tsc --noEmit
npm run dev              # wrangler dev --port 8787
```

Smoke the health endpoint:

```bash
curl -s localhost:8787/health
# {"ok":true,"regions":["us-east","us-west","eu-west","eu-central","ap"]}
```

Send a signed probe (reads the secret from `.dev.vars`):

```bash
SECRET=$(grep '^RELAY_SECRET=' .dev.vars | cut -d= -f2-)
BODY='{"monitor_id":"smoke","probe_run_id":"run-1","region":"eu-central","type":"http","method":"GET","url":"https://example.com","request_headers":{},"request_body":null,"timeout_seconds":15,"expected_status_code":200,"auth_config":null,"assertion_rules":null}'
TS=$(date +%s)
SIG=$(printf '%s.%s' "$TS" "$BODY" | openssl dgst -sha256 -hmac "$SECRET" -hex | awk '{print $2}')

curl -s -i localhost:8787/run \
  -H "Content-Type: application/json" \
  -H "X-Relay-Timestamp: $TS" \
  -H "X-Relay-Signature: $SIG" \
  --data-binary "$BODY"
```

The response body is the `CheckResultPayload`, echoing the same
`probe_run_id`. `X-Probe-Colo` in the response headers reports the IATA
airport code the DO ran from.

## Deploy

```bash
echo "<secret>" | npx wrangler secret put RELAY_SECRET   # or: npm run secret:set
npx wrangler deploy                                       # or: npm run deploy
```

Durable Object migration `v1` (`new_sqlite_classes = ["RegionalProbe"]`)
runs automatically on first deploy. `RELAY_SECRET` on Cloudflare must
byte-match Laravel's `RELAY_SECRET`, otherwise every `/run` returns
`401 invalid signature`.

## Laravel `.env`

```dotenv
RELAY_URL=https://<worker-url>
RELAY_SECRET=<matching-secret>
RELAY_TIMEOUT_SECONDS=45
```

## Troubleshooting

| Symptom                    | Likely cause                                                            |
| -------------------------- | ---------------------------------------------------------------------- |
| `401 invalid signature`    | Laravel and CF `RELAY_SECRET` drift, or clock skew > 300s.             |
| `400 unknown region: ...`  | Region not in the five canonical hints.                                |
| `X-Probe-Colo: unknown`    | DO could not reach `cdn-cgi/trace`; probe still ran, only debug lost.  |
