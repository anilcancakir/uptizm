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
seconds, from [[faq.free_regions]] region. [[faq.free_status_pages]] status page
with up to [[faq.free_subscribers]] email subscribers. [[faq.free_responders]] responder,
alerting over every channel below. TLS expiry alerts and response-metric bounds. The AI
anomaly inbox is included, plus [[faq.free_ai_trials]] free AI monitor setups before that
particular feature asks you to move to Pro.

</details>

<details>
<summary>Which protocols can I monitor?</summary>

Two: [[faq.monitor_types]]. An HTTP monitor issues a request at the configured URL; a TCP
monitor opens a socket and times the connect and handshake only. There is no ping check,
no DNS check and no browser-based check today.

</details>

<details>
<summary>Are monitor authentication and assertion rules honoured?</summary>

Not yet. Both can be entered and saved on a monitor, and the probe engine currently ignores
both: an endpoint that requires authentication is checked as if it did not, and an assertion
rule changes nothing about whether a check passes or fails. Do not rely on either. The
[Terms](/terms) carry the same disclosure, and it stays on both pages until the probe engine
honours them.

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

[[faq.retention_days]] days of raw checks, at full resolution. The database drops it on a
schedule of its own rather than an application job doing the rounds, and that schedule is
a feature of the time-series extension the database runs: there is no second pruning job
and no rollup tier, hourly or daily, standing behind it.

</details>

<details>
<summary>How do I cancel?</summary>

By email, and that is the route that reliably works: send a message to the operator's contact
address, which section 8 of the [Terms](/terms) publishes. It costs nothing and needs no notice
period. Cancelling does not cut you off immediately either: your plan stays active until the end
of the period you already paid for, and the account then continues on the free plan. The billing
screen in the application opens the payment provider's customer portal, where your invoices and
your payment method live; what can be done in there is the payment provider's own configuration
rather than ours, so do not count on finding a cancel control in it. Deleting your account ends
the contract too, on a paid plan as well as on the free one.

</details>

<details>
<summary>If I withdraw in the first 14 days, is the AI I used taken off the refund?</summary>

No, nothing is deducted. An AI analysis is not priced separately and there is no charge per
analysis: a paid plan entitles it, and the free plan includes
[[faq.free_ai_trials]] AI monitor setups before that feature asks you to move up, so it is a
metered entitlement inside the plan rather than a line item. Charging a consumer for what was
supplied during a withdrawal period is
open only to a trader who collected an express request to start and an acknowledgement at the
moment of purchase, and this checkout collects neither. Section 7 of the [Terms](/terms) is the
full version, including how to withdraw.

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
