## What we measure, and what we do not

Uptizm checks `[[service.endpoints]]` every [[service.check_interval_seconds]] seconds
from up to [[service.region_count]] probe regions, and everything above under
"What Uptizm measured" is that check and nothing else. The daily strip is our
reachability of that one endpoint over the last [[service.strip_days]] days, one cell
per day, and a day we did not measure stays neutral instead of being painted as a
day that passed.

That is a narrow claim on purpose. We reach one public endpoint of
[[service.name]] from outside their network. We cannot see their internal services,
their regional capacity, their API error rates or the parts of their product we do
not probe, so we publish **no uptime percentage, no availability figure and no SLA
number** for [[service.name]]. A figure like that would imply we cover the whole
product, and we do not. If you need that number, it is theirs to publish, not ours
to estimate.

## When this page says we could not reach something

Every reading above is ours, names the endpoint it came from, and carries the time
it was taken. Two rules decide what we are willing to say:

- A reading older than [[service.stale_after_seconds]] seconds is **unknown**. We
  never leave the last value we happened to have on the page as though it were
  current.
- We report a problem only after [[service.incident_threshold]] consecutive failed
  checks **and** at least [[service.agreeing_regions]] regions agreeing. A single
  region having a bad minute is a fact about that region.

The second rule is deliberately stricter than the alerting we give our own
customers, where speed matters more: a customer wants to know within a minute,
whereas a public page that contradicts [[service.name]]'s own status page needs to
be right.

## What [[service.name]] publishes itself

Where [[service.name]] publishes a machine-readable status feed and we have reviewed
their terms, the section above quotes it: their overall status word, their component
names, their open incidents, and when we fetched it. Their words are shown as their
words. We do not translate their vocabulary into ours, we do not recolour their
overall status, and we do not open an incident of our own on their behalf.

If the two sections disagree, the page says so and leaves both standing. There is no
third, blended answer here: we watch one endpoint from the outside, they can see
systems we cannot, and both readings can be true at the same time. Their own status
page remains the authority on their own systems.

## What this page is not

This page is published by Uptizm and is not [[service.name]]'s official status page.
We are not affiliated with, endorsed by or sponsored by [[service.name]], and
[[service.name]] is a trademark of its owner. We use the name to say plainly which
service we measured.
