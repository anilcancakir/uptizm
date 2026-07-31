This notice was written by the person who operates Uptizm, who is not a lawyer, and it has not
been reviewed by one. Nothing on this page is legal advice, to you or to anybody else. It is
published anyway, because a product that ends up holding data about people who never signed up
for it owes them a description of what happens to that data, and because the alternative, a
template full of claims this system cannot back, would be worse. Every fact below was checked
against the code that implements it, and every number in it is read from the running
configuration rather than typed into the page. This describes what the system does; it is not a
claim to be compliant with anything.

## Two roles, and which one applies to you

Which of the two roles the operator wears decides who you should be asking.

**The service's own data.** Accounts, teams, billing, login sessions, and messages sent through
the contact form on this website. The operator decides why and how those are processed, so here
the operator is the controller and this page is your notice.

**Data a customer configures.** The endpoints a customer points a monitor at, whatever those
endpoints return, the incident notes a customer writes, the status pages a customer publishes and
the addresses that subscribed to them. The customer chooses all of it, so there
the customer is the controller, and the operator is only a processor acting on their instructions.

The second case is what template notices get wrong by claiming controller for everything. If your
address is on somebody's status page, or your personal data turned up inside a response a monitor
was pointed at, your controller is that customer. A request sent to [[legal.rights_email]] is
passed on to them, and the operator will not act on another controller's data by itself; it will
tell you that rather than going quiet.

## Who is responsible, and how to reach them

- **Operator:** [[legal.operator]]
- **Address:** [[legal.address]]
- **General contact:** [[legal.contact_email]]
- **Anything about your own data:** [[legal.rights_email]]

A sole proprietorship in Türkiye, operating the service from Türkiye. Three absences, stated here
rather than left for you to find out: there is **no representative in the European Union**,
although the kind of continuous monitoring this product does is what such a representative exists
for, and that gap is recorded and accepted rather than papered over; there is no data protection
officer, because the scale does not require one and appointing a title is not a control; and there
is no privacy certification, seal or independent audit report behind this page.

## What is stored, why, and on what basis

Where a row below says legitimate interest, the interest itself is named. "Our legitimate business
interests" is not a lawful basis, it is a way of not stating one.

| What | Why | Basis |
|---|---|---|
| Your name, email address, password hash, language and time zone | so an account exists and can be addressed in the right language | legitimate interest in running an account-based service somebody asked for; performance of the contract where you signed up as an individual rather than for a company |
| API tokens, with the IP address and browser string each was issued to | so you can see and revoke your own sessions, and a stolen token is recognisable | legitimate interest in keeping accounts from being taken over |
| Team, membership and role | to decide who sees which monitors | the same interest as the account |
| Monitor configuration: the URL, request headers and body, credentials, assertion rules | to run the check that was asked for; credentials are encrypted at rest | the customer's instruction, with the customer as controller |
| Check results: outcome, latency, response headers, error text, and a bounded excerpt of the response body | so history, incidents and uptime figures exist at all | the customer's instruction |
| Incidents and their timelines, including who wrote each update | so an outage has a record | the customer's instruction |
| A status page, and each address subscribed to it after confirming a link sent to that address | to send the updates the subscriber asked for | the subscriber's consent, taken as a double opt-in and withdrawable in one click |
| Billing: the payment provider's customer and subscription identifiers, and invoices | to charge for a paid plan and to keep what tax law requires | performance of the contract, plus a legal obligation for the retention |
| Your name, your email address and your message when you use the contact form | to answer you | legitimate interest in replying to somebody who wrote in |
| Rate-limit counters and web-server access logs, keyed on IP address | to bound and investigate abuse of the endpoints anybody can reach | legitimate interest in the security of the service |

None of it is sold, rented or used for advertising, and none of it is profiled. No decision with a
legal or similarly significant effect on a person is made automatically. Where the AI layer is
switched on it writes about an incident rather than about a person, and what it writes is
advisory.

## Data that arrives from somewhere other than you

Two places where the service ends up holding data about somebody it has never dealt with.

**Inside a monitored response.** A customer points a monitor at a URL, and each result stores the
response headers plus a bounded excerpt of the body. If that body carries a name, an address, an
identifier, or an error message with somebody in it, the service is now storing it. The categories
are whatever that endpoint returned, which the operator neither chooses nor reads, and the source
is that endpoint: not a public register, not a data broker, nowhere it was bought.

**On a status page.** An incident update a customer publishes can name people, and a subscriber
list is a list of addresses somebody typed in.

In the first case nobody here can tell who the person is or how to reach them, and finding out
would mean reading customer content the operator has no business reading. Informing each person
individually would take disproportionate effort in the sense the law means, so this page is the
measure that stands in place of a message nobody could send. In both cases the controller is the
customer, not the operator.

## How long it is kept

- **Check history: [[privacy.check_days]] days.** The database drops it on a schedule of its own
  rather than an application job doing the rounds, and the same policy keeps hourly summaries for
  [[privacy.hourly_days]] days and daily summaries for [[privacy.daily_days]] days. The honest
  caveat: that schedule is a feature of the time-series extension the database runs, and where a
  deployment has no such extension there is no second pruning job standing behind it.
- **A deleted monitor is hidden rather than erased.** Deleting one marks the row and keeps it, so
  the check history behind it stays reachable until the window above catches up with it.
- **Unsubscribing really deletes.** The link in every status-page email removes the subscriber row
  outright. No suppression list keeps the address afterwards, which also means a later
  resubscription starts from nothing.
- **Deleting your account takes the rest with it.** Tokens are revoked, the profile photo is
  removed from storage, the user row goes, and the database removes the teams you own along with
  their monitors, check history, incidents, status pages and subscribers.
- **In-app notifications are pruned by nothing** today. They stay until the account or the team
  that owns them goes.
- **Counters** expire within minutes. Access logs are kept only while they are useful for looking
  into abuse, and are used for nothing else.

## Who else receives it

Third parties receiving personal data on this deployment today: [[privacy.recipients]]. That list
is read from the deployment's own configuration, so a category described below that is missing
from it is not configured here and receives nothing.

- **The edge network the probes run in (Cloudflare).** Every check leaves as one signed
  instruction: the monitor id, the region, the URL, the method, the request headers and body, the
  timeout, the expected status, the assertion rules, and **the credentials stored on the monitor**.
  The credentials travel even though the probe engine ignores them, as it ignores the assertion
  rules, which the Terms page says too: they are transmitted, they are not used. They are
  encrypted in the database and the instruction is signed, and none of that changes the fact that
  they leave.
- **An AI provider, where one is configured for the deployment.** An incident analysis sends the
  incident's own metadata and timeline plus check metadata, and three fields nobody here controls,
  each cut hard at [[privacy.ai_chars]] characters: the error text, the response headers, and the
  excerpt of the response body. So up to [[privacy.ai_chars]] characters of whatever a monitored
  endpoint returned can reach that provider. With no provider configured nothing is sent at all.
- **The alert destinations a customer sets up: [[privacy.channels]].** Each receives the incident
  title, the monitor name, the severity, the state and a link. A customer's own webhook receives
  the same, signed.
- **Push and text messages, where the push service is provisioned**, carry the monitor name and
  the incident title. Text messages are never on by default: they need a phone number and an
  explicit opt-in for that kind of notification.
- **An email provider, where a real transport is configured.** Incident mail, subscription
  confirmations, and the messages this site's contact form sends to the operator.
- **A payment provider, for a paid plan.** Card details are entered on its side; the operator
  never sees or stores a card number.
- **File storage.** Profile photos, team logos and rendered status-page images sit on the disk the
  deployment configures. On a local disk they never leave the operator's own server.

One more thing worth saying plainly: a public status page is public. What appears on it is what
its owner chose to show, and subscriber addresses are never among that.

## Where it goes outside Türkiye

The probes are the point of the product and they are meant to leave: a monitor can be pinned to
any of [[privacy.region_count]] regions to choose from ([[privacy.regions]]), so a check is made
from wherever the customer picked.

For the recipients above that sit outside Türkiye and the European Economic Area, the mechanism is
named as a category rather than as a promise: an adequacy decision where one covers the recipient,
which for the United States means the EU-US Data Privacy Framework where that recipient is
certified under it; otherwise the European Commission's standard contractual clauses; and for a
transfer out of Türkiye, the route Turkish data protection law provides. Ask at
[[legal.rights_email]] which one applies to which recipient, or for a copy of the safeguards.

This is deliberately not written as settled. The adequacy decision covering the United States is
under challenge in the European courts, and if it falls the mechanism for those recipients changes
rather than the transfers stopping. This section is reviewed when that happens; a page presenting
the mechanism as permanent would be making a promise about somebody else's litigation.

## What protects it

Only what is actually built. Monitor credentials are encrypted at rest. Passwords and API tokens
are stored hashed. Confirmation and unsubscribe tokens are single-use and compared in constant
time. Requests to the edge network and to a customer webhook are signed and verified. A webhook
target is re-checked at send time against internal address ranges, with the connection pinned to
the address that was checked and redirects refused. One team cannot read another's data, and the
attempt looks like a page that does not exist. The paths anybody can write to without an account,
and the public status pages, are rate limited. Nothing beyond that list is claimed here.

## Your rights

You can ask for access to your data and a copy of it, for correction, for deletion, for
restriction of processing, and for a machine-readable copy to take elsewhere. You can object to
anything resting on a legitimate interest, including the security-related ones named above. Where
something rests on consent, which today means a status-page subscription and analytics if it is
ever switched on, you can withdraw it whenever you like, and withdrawing does not unpick what was
lawful while it stood. There is no automated decision-making with a legal or similarly significant
effect on anybody, so there is nothing to contest under that heading.

Write to [[legal.rights_email]]. You get an answer within one month. If the request is genuinely
complicated that can be extended by two further months, and you are told inside the first month
rather than after it. You may be asked to confirm who you are, but only as far as it takes to be
sure it is you.

If your data is here because a customer put it here, the two roles at the top apply and the
request goes to that customer.

**Complaining.** The operator's own supervisory authority is [[legal.authority]]. If you are in
the European Economic Area, take a complaint to the data protection authority of your country
instead, or to the one where you work or where the problem happened: the
[EDPB keeps the list](https://www.edpb.europa.eu/about-edpb/board/members_en). You can also go to
court, instead of or as well as complaining. No single European authority is named here as though
it were competent for this service, because without an establishment in the Union there is no lead
authority to name.

## Cookies, and what else is kept on your device

Cookies this website can store on your device: [[privacy.cookie_count]]. That count is read from
the deployment's configuration rather than typed here, so this section cannot fall behind the
site.

**While the count is zero it means what it reads.** No cookie of any kind on any page you can
read, no analytics, no tag manager, no advertising pixel, no third-party embed, no fingerprinting,
and nothing stored when you submit the contact form either. The routes serving these pages are
registered outside the part of the framework that starts a session, deliberately, which is why the
number is zero rather than a preference somebody could quietly change back. The fonts come from
this domain.

Two cookies used to be set here and are not any more. They are named because a reader who
remembers them, or finds one sitting in an old browser profile, deserves an answer.

| Name | Purpose | Duration | Party |
|---|---|---|---|
| `XSRF-TOKEN` | the framework's form-forgery token, readable by scripts on this domain | [[privacy.session_minutes]] minutes | first |
| `[[privacy.session_cookie]]` | the framework's session identifier | [[privacy.session_minutes]] minutes | first |

**Once the count reads two, analytics is configured for this deployment**, and this is what that
means. Google Tag Manager loads with consent denied for every purpose by default, and a banner
asks before anything measures you. It separates what is strictly necessary, always on and with no
toggle because the site does not work without it, from analytics, which stays off until you say
otherwise. Accepting analytics lets Google Analytics store two cookies:

| Name | Purpose | Duration | Party |
|---|---|---|---|
| `_ga` | tells one browser from another so visits can be counted | 2 years | third party, Google |
| `_gid` | the same job within a single day | 24 hours | third party, Google |

Your answer is kept in the browser's local storage rather than in a cookie, and a link in the
footer reopens the banner so you can change it or take it back at any time. Declining leaves the
number of cookies actually stored at zero.

**The application is a different surface from this website.** Signing in there sets no cookie: the
app keeps a bearer token in the browser's local storage and sends it with every request. Keeping
that token is still keeping something on your device, and it needs no permission because it is
what signing you in requires; nothing about it measures or follows you. Clearing this site's data
in your browser removes it and signs you out.

## Changes to this notice

It changes when the system changes, and the figures in it change on their own, because they are
read from the configuration rather than transcribed. A change that affects you is announced by
email to your account address. No effective date has been published for this document yet, which
is why the line under the title says so.
