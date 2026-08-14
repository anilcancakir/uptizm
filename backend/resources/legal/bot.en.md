Uptizm sends you two kinds of automated request, and this page describes both. If either
showed up in your access log and you want to know what it is, or you want it to stop, this
page is the whole answer. The figures below come from this deployment's own configuration
rather than from prose somebody updated by hand, so they track what we run. One caveat we
would rather state than have you catch: the availability check's cadence is the configured
default, and an individual check that has been retuned may differ from it.

## How to recognise them

Both identify themselves with the same string on every request:

```
[[bot.user_agent]]
```

Neither pretends to be a browser, and neither sends a browser User-Agent.

## 1. The availability check

This is the larger of the two, so it comes first. We request **one URL** on your service,
usually your homepage, and record whether it answered and how quickly. That is the
measurement we publish, and it is the only reason the page about your service exists.

It runs from **[[bot.probe_regions]]**, each about every
**[[bot.probe_interval_seconds]] seconds**, which works out to roughly
**[[bot.probe_daily_requests]] requests a day** to that one URL. It is a plain GET. It
reads no other page, follows no links, submits no forms and looks for nothing you have not
published.

## 2. The status-feed read

If your service publishes a machine-readable status feed and a person at Uptizm has
reviewed your terms and recorded that review, we also read that feed so we can show what
you say about yourself next to what we measured.

The shortest gap between two of these for the same service is
**[[bot.min_interval_seconds]] seconds**, and that floor is enforced against the last
recorded fetch rather than by a timer, so a restart, a duplicate schedule tick or a retry
cannot make it faster.

It sends `If-None-Match` with the `ETag` you gave it last time. When you answer `304 Not
Modified` it writes nothing new and asks again later, so an unchanged feed costs you a
header exchange and no body.

It does not follow redirects. If your feed moves, our request stops at the redirect and a
person has to update the address, because the new host is one nobody has reviewed yet.

## How they back off

If you answer `429 Too Many Requests` or `403 Forbidden` to the **feed** read, it disables
that feed immediately and stops asking. It does not retry, and nothing turns it back on
automatically: a person has to look at why it was refused and clear it by hand. A `403` is
a complete and permanent answer and we would rather you send one than have to block us.

The **availability check** does not stop itself that way, and we would rather say so than
let you find out. It keeps requesting on its schedule and records what came back, because
a refusal is itself a measurement and publishing "they refuse our requests" is more honest
than publishing nothing. A `403` is recorded like any other answer. If you want that one
stopped, the section below is the way.

There is one answer we do not record: an interactive bot challenge. If your edge replies to
the availability check with a challenge page instead of a response, we have measured
nothing about your service, so nothing is stored and your service is never published as
down over it. We do not try to solve the challenge, swap our User-Agent for a browser one,
or come back from a different address.

## How to reach a person

If you would prefer we did not request anything at all, or you want a different cadence, or
you just want to know why we are there, write to [[bot.contact_email]] and say which domain
you are asking about. We will remove your service from the catalog, which stops both
clients. Blocking the User-Agent works too, and we will read that as the answer it is
rather than routing around it. So does a bot challenge, on the terms described above.

One more thing worth saying plainly, and the two clients differ here.
[[bot.probe_egress]] The status-feed read is the opposite: it
comes straight from one of our own servers, so blocking that one address does stop it,
permanently.

If you want us stopped for good, the reliable way is asking us to remove your service from
the catalog, as above, or blocking the User-Agent itself, which we honour the same way
regardless of which address it arrives from.

## What it is for

Uptizm publishes an independent page per service showing our own measurement of a public
endpoint, alongside what that service's own status feed says at the same moment, each
labelled with where it came from. We never merge the two into one number and we never
present your published status as ours. The point of reading your feed is to be able to
show your side of the story next to ours when the two disagree.
