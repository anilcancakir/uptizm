## How to read this page

This page carries [[index.service_count]] service pages. Each one shows two things
side by side, each labelled with where it came from: what Uptizm measured by probing
one named public endpoint from up to [[index.region_count]] regions, and what the
provider publishes on its own status feed where they publish one and we have reviewed
their terms.

Those two are never merged. There is no combined score, no averaged uptime and no
single "real" answer, because the two answer different questions: we can only speak
for the endpoint we reach from outside, and a provider can see systems we cannot.
When they disagree, the service's page says so and shows both.

## What these pages will not tell you

- **No uptime percentage, availability figure or SLA number for a third party.** We
  probe one endpoint of one product, so a percentage would imply a coverage we do not
  have.
- **No stale reading dressed up as a current one.** A measurement older than
  [[index.stale_after_seconds]] seconds is shown as unknown rather than frozen at the
  last value we happened to hold.
- **No crowd reports.** Nothing here is a user submission, a vote or an aggregate of
  other people's outage reports.
- **No provider logos.** Every name is used as plain text to say which service we
  measured.

## Independence

Uptizm is not affiliated with, endorsed by or sponsored by any service listed here,
and every name and logo mentioned belongs to its owner. Each provider's own status
page remains the authority on their own systems; these pages are our own
measurements, published because an outside measurement is worth having beside a
first-party one.
