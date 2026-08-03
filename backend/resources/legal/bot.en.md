Uptizm runs one automated client that reads other companies' public status feeds. If it
showed up in your access log and you want to know what it is, or you want it to stop, this
page is the whole answer. Every figure below comes from the code that actually makes the
requests, so it cannot promise a cadence the client does not keep.

## How to recognise it

It identifies itself on every request:

```
[[bot.user_agent]]
```

It never pretends to be a browser, and it never sends a browser User-Agent.

## What it requests, and how often

It fetches one document per service: the public status feed you already publish for
everybody, at the URL a person at Uptizm reviewed and recorded by hand. It reads nothing
else on your site. It does not crawl, it does not follow links, and it does not look for
anything you have not published.

The shortest gap between two requests for the same service is
**[[bot.min_interval_seconds]] seconds**, and that floor is enforced against the last
recorded fetch rather than by a timer, so a restart, a duplicate schedule tick or a retry
cannot make it faster.

It sends `If-None-Match` with the `ETag` you gave it last time. When you answer `304 Not
Modified` it writes nothing new and asks again later, so an unchanged feed costs you a
header exchange and no body.

It does not follow redirects. If your feed moves, our request stops at the redirect and a
person has to update the address, because the new host is one nobody has reviewed yet.

## How it backs off

If you answer `429 Too Many Requests` or `403 Forbidden`, it disables that feed
immediately and stops asking. It does not retry, and nothing turns it back on
automatically: a person has to look at why it was refused and clear it by hand.

That is the intended way to make it stop. A `403` is a complete and permanent answer, and
we would rather you send one than have to block us at the network.

## How to reach a person

If you would prefer we did not read your feed at all, or you want a different cadence, or
you just want to know why we are there, write to [[bot.contact_email]] and say which
domain you are asking about. We will remove it.

## What it is for

Uptizm publishes an independent page per service showing our own measurement of a public
endpoint, alongside what that service's own status feed says at the same moment, each
labelled with where it came from. We never merge the two into one number and we never
present your published status as ours. The point of reading your feed is to be able to
show your side of the story next to ours when the two disagree.
