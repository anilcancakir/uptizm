Straight answers about what Uptizm checks, what it costs, and what it cannot do. Every
number below comes from the same configuration and enums the product itself runs on, so
if a plan or a limit changes, this page changes with it.

## About monitoring

<details>
<summary>Which regions does a check run from?</summary>

Every monitor can be pinned to any of the [[faq.region_count]] supported regions:
[[faq.region_names]]. All the regions you select run in the same tick, so a slow region
is a fact about that region rather than about where it sat in a queue.

</details>

<details>
<summary>Which protocols can I monitor?</summary>

Two: [[faq.monitor_types]]. An HTTP monitor issues a request at the configured URL; a TCP
monitor opens a socket and times the connect and handshake only. There is no ping check,
no DNS check and no browser-based check today.

</details>

<details>
<summary>How often can a check run?</summary>

That depends on your plan. The shortest interval you can set is
[[faq.free_interval_seconds]] seconds on Free, [[faq.pro_interval_seconds]] seconds on
Pro, [[faq.business_interval_seconds]] seconds on Business and
[[faq.enterprise_interval_seconds]] seconds on Enterprise. You can always choose a longer
interval than your plan's floor.

</details>

<details>
<summary>What does the free plan include?</summary>

[[faq.free_monitors]] monitor, checked as often as every [[faq.free_interval_seconds]]
seconds, from all [[faq.region_count]] regions. [[faq.free_status_pages]] status page
with up to [[faq.free_subscribers]] email subscribers. [[faq.free_responders]] responder,
alerting over every channel below. TLS expiry alerts and response-metric bounds. The AI
anomaly inbox is included, plus [[faq.free_ai_trials]] free AI monitor setups before that
particular feature asks you to move to Pro.

</details>

<details>
<summary>Which alerting channels can I use?</summary>

[[faq.alert_channels]]. Your own personal SMS and email delivery ride a separate opt-in,
but these four are the team-level alert destinations a monitor can page.

</details>

<details>
<summary>Is there an uptime SLA?</summary>

No. Uptizm does not promise an uptime percentage or a support response time on any plan
today.

</details>

## About the AI

<details>
<summary>What does the AI actually see, and what does it not see?</summary>

When it analyzes an incident, the AI reads that incident's own timeline and the checks
recorded against its monitors: region, status and timing, plus up to
[[faq.ai_char_limit]] characters of the error message, response body and response
headers your endpoint returned. It never sees your deploys, your commits, your CI, your
logs, your traces, your APM, your CDN, or anybody else's status page: Uptizm has no
integration into anything you run, so it can only reason from what its own checks
measured.

</details>

## About your account and data

<details>
<summary>How long is check history kept?</summary>

[[faq.retention_days]] days of raw checks. History older than that is rolled up into
hourly and daily aggregates rather than kept at full resolution forever.

</details>

<details>
<summary>How do I cancel?</summary>

From your billing settings, any time. Cancelling does not cut you off immediately: your
plan stays active until the end of the period you already paid for, and the same screen
opens Stripe's billing portal for your invoices and payment method.

</details>

<details>
<summary>How do I get my data deleted?</summary>

Deleting your account removes your API tokens and your profile photo, then deletes the
user row itself; it is a hard delete, not a deactivation. Deleting a team removes its
monitors and its status-page subscribers along with it, at the database level.
Unsubscribing from a status page hard-deletes that one subscriber row on its own. For
anything a deletion request should cover that the product has no self-service button for
yet, write to [[faq.rights_email]].

</details>
