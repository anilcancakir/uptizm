These terms were written by the operator, who is not a lawyer, and they have not been
reviewed by one. Nothing on this page is legal advice, to you or to anybody else. They are
published anyway, because a service that takes money and holds data owes its users a
document saying what it will and will not do, and because the alternative, a template full
of promises this product cannot keep, would be worse. Where a term below is weaker than the
law that protects you, the law wins and the term does not apply.

## Who operates this service

- **Operator:** [[legal.operator]]
- **Trading name:** [[legal.trade_name]]
- **Address:** [[legal.address]]
- **Email:** [[legal.contact_email]]
- **Data protection requests:** [[legal.rights_email]]
- **Telephone:** [[legal.phone]]
- **[[legal.tax_number_label]]:** [[legal.tax_number]]

The operator is a sole proprietorship in Türkiye. A sole proprietorship has no legal
identity separate from the person behind it, so the person named above is the party you
contract with. The number published above is that person's individual tax identifier, not a
company registration number and not a VAT number. No trade-register number appears here
because none was available to publish, and this page does not invent one.

There is no establishment, branch, office or representative in the European Union, and none
is claimed. The Service is operated from Türkiye.

The operator is one person. There is no support team, no duty desk and no committed response
time anywhere in this document, and section 10 says so again in the place a reader would look
for it.

## Definitions

- **Uptizm**, or **the Service**: the uptime, incident and status-page monitoring product
  reachable through this website and the application it links to, including the parts of it
  described in section 4.
- **The operator**: the party named in section 1.
- **You**: the person or organisation holding an account.
- **Consumer**: a natural person acting for purposes outside their trade, business, craft or
  profession. Where these terms say something applies to consumers, it applies to you if you
  fit that description, whatever the plan you are on is called.
- **Account** and **team**: the login that identifies you, and the workspace that owns
  monitors, incidents, status pages and billing. People are added to a team; a team is not a
  shared login.
- **Monitor** and **check**: the target you ask the Service to watch, and one attempt to
  reach it from one region at one moment.
- **Incident**: the record the Service opens when checks fail repeatedly, together with its
  timeline and the notifications it triggers.
- **Status page**: a page you publish about your own service, and the email subscribers who
  asked to be told when it changes.
- **Free plan** and **paid plan**: the plan that costs nothing, and the plans listed with a
  price in the application.
- **These terms**: this document, together with the [Privacy Policy](/privacy).

## Who may use Uptizm, and how these terms are accepted

You must be able to enter a binding contract where you live. The product does not check your
age or capacity: that is a term you are relied on to honour, not a control that stops you.

These terms are accepted by creating an account and using the Service. Being honest about
what that looks like today: the sign-up screen in the application does not link to this page
yet, and the link that does exist is in the footer of every page on this website. A term you
had no real opportunity to read before you signed up is not enforced against you, and EU
consumer-protection law reaches the same result through its transparency requirement. If you
have just found this page and disagree with something in it, section 8 tells you how to
leave, and nothing here charges you for doing so.

**Whether you are a consumer or a business is decided by what you actually do, not by the
plan name.** Nothing in the product asks you to declare it: there is no company-name field,
no VAT-number field and no business-purpose confirmation at checkout. So paying with a
company card, or subscribing to a plan called Business, does not make you a business for the
purposes of these terms. Where it is unclear, you are treated as a consumer, because
misclassifying a consumer is the more expensive mistake for both of us.

**The free plan is inside this contract.** You pay nothing for it and you provide an email
address, your account data and the monitors you configure instead. The EU rules on digital
content and digital services treat a contract paid for with personal data the same as one
paid for with money, so the free plan carries the same duty to match its description and the
same remedies as a paid one. Nothing below narrows that.

## What the Service does, and what it does not do

The Service checks endpoints you nominate and tells you when they stop answering the way you
said they should:

- **Checks.** An HTTP or a TCP monitor, run from up to [[service.region_count]] regions per
  monitor at an interval your plan allows. HTTP and TCP are the only monitor types; there is no ping check, no DNS
  check and no browser check.
- **Records.** Each check stores its result, the response time, and a bounded excerpt of what
  came back. History is kept for a limited period, stated on the Privacy Policy rather than
  here so there is one number and not two.
- **Incidents.** Repeated failure, not the first failure, opens an incident. Escalation
  policies and on-call schedules decide who is told, through the notification channels you
  configure.
- **Status pages.** A page you publish about your own service, with email subscribers who
  opted in. There is no custom domain for a status page, no white-label mode and no single
  sign-on; if a plan description ever says otherwise, this sentence is the correct one.
- **AI assistance.** Where the deployment has an AI provider configured, an AI layer can
  write an analysis of an incident, and, in the mode you select, open and close anomaly
  incidents itself. It is switched off, advisory or automatic at your choice.

**What it is not.** The Service watches; it does not run, host, protect or repair anything of
yours. Monitoring from the outside cannot see what your own logs see, and it cannot prevent
an outage or shorten one. Do not make it the only safety mechanism anywhere a failure could
injure somebody, destroy data or breach an obligation you owe a third party.

**Checks can be wrong in both directions.** A network path can fail between our edge and your
endpoint, and the result is a failure that says nothing about your service. Our own edge can
refuse to run a probe, which is recorded and shown to you and deliberately pages nobody.
Notifications travel through third parties, which can delay or drop them.

**Two settings you can save are not honoured yet.** Monitor authentication and response
assertion rules can be entered and stored in the application, and the probe engine currently
ignores both. So a monitor whose endpoint requires authentication will be checked as if it
did not, and an assertion rule changes nothing. Do not rely on either. This paragraph stays
here until the probe engine honours them.

**AI output is generated by a machine.** It can be wrong, it can be confidently wrong, and it
sees only monitoring data: check results, the bounded response excerpt, the incident
timeline. It has no access to your deploys, your commits, your logs, your traces or your APM,
so it cannot know what caused anything outside what it was shown. Read it as a suggestion and
decide for yourself.

## Your account

- Give an email address you actually reach. Notifications, security notices and anything
  about this contract go there.
- Keep your credentials to yourself. Two-factor authentication is available and turning it on
  is a good idea. You are responsible for what happens under your account.
- Tell the operator at [[legal.contact_email]] as soon as you suspect your account has been
  reached by somebody else.
- One account per person. Add people to your team rather than sharing a login, so the audit
  of who did what stays meaningful.
- A team's owner and its members can see the team's monitors, incidents and status pages. Add
  somebody to a team only if you mean them to see that.
- The operator may need to look at your account data to investigate a fault you report. The
  Privacy Policy says how that is handled.

## Prices, payment, tax and renewal

The free plan costs nothing and needs no card. Paid plans, what each one includes and what
each one costs are listed in the application, in [[service.currency]].

Payment is taken by the payment provider (Stripe). The operator never sees or stores your
card number. The total you will be charged is shown before you confirm, and where a tax
applies to your purchase it is applied at that point.

**A paid plan renews automatically.** It runs for the period you chose, monthly or yearly,
and renews at the end of each period against the same payment method until it is cancelled.
Cancelling is section 8, and it costs nothing.

**Changing plan** takes effect through the payment provider, which adjusts what you owe for
the remainder of the period you have already paid for.

**A price change** to a plan you are already paying for is announced in advance, with the
reason, and never applies to a period already paid for. You can end the subscription before
the new price takes effect and pay nothing further.

**A failed payment** ends the paid entitlement and the account continues under what the free
plan allows. Nothing you created is deleted because a payment failed.

## Your right to withdraw

If you are a consumer, you have 14 days from the day the contract is concluded to withdraw
from it without giving a reason and without any penalty. This is your statutory right in the
EU and the EEA, and the operator applies the same 14 days to every consumer wherever they
live, so nobody has to work out which law reaches them first.

**The immediate-performance exception, and why it is not used here.** The law lets a trader
treat a service as no longer withdrawable once it has been fully performed, and lets the
trader charge for the part already supplied, but only where the trader obtained your express
request to begin performance inside the withdrawal period and told you, and had you
acknowledge, that you would lose the right once performance was complete. Uptizm's checkout
collects neither of those things today. The exception is therefore not relied on: if you
withdraw inside the 14 days, you get back what you paid for the current period and you are
not charged for the days already monitored.

**How to withdraw, using what exists today.** Send an unambiguous statement to
[[legal.contact_email]] before the 14 days are up. An email is enough; there is no form to
fill in and no reason to give. You may use this wording:

> I hereby give notice that I withdraw from my contract for the Uptizm service. Ordered on
> (date). Name (your name). Account email (your account email). Date (today's date).

The refund goes back to the payment method you paid with, without undue delay and in any
event within 14 days of the operator being told.

**What does not exist yet, said plainly.** There is no button in the application that
withdraws from or cancels the contract on its own. EU law now requires an online withdrawal
function for contracts concluded online and this product does not have one; building it is
tracked as its own piece of work. Until it exists, the email route above is the route, and
your right is not affected by the missing control: a statutory right does not depend on the
operator having shipped a button.

## Cancellation and termination

**You can cancel a paid plan at any time**, with no notice period and no fee. Send a message
to [[legal.contact_email]]. Cancellation takes effect at the end of the period you have
already paid for, and the account then continues on the free plan. Outside a withdrawal under
section 7, the part of the period already used is not refunded, which is what "at the end of
the period" means.

The application's plan and billing screen opens the payment provider's customer portal, where
your payment method and your invoices live. What can be changed there is the payment
provider's own configuration rather than the operator's, so treat the email above as the route
that reliably works.

**You can delete your account entirely**, in the application under Settings and then
Security, confirming with your password. Deleting the account ends this contract, on the free
plan as well as on a paid one. Export anything you want to keep first.

**The operator may end this contract** by giving you reasonable advance notice by email, and
may end it or suspend an account immediately only on serious grounds: unlawful use, use that
attacks the Service or another user, non-payment after a reminder, or a legal obligation that
leaves no choice. Where the ground is not serious, the notice comes first and you keep access
until it runs out.

**When the contract ends**, monitors stop, status pages stop being served, and your data is
deleted or retained as the Privacy Policy describes.

## Changes to the Service

The Service will change: things are added, things are altered, and things that do not work
are removed. Two different kinds of change are treated differently.

**Minor changes** (fixes, performance, wording, appearance, and anything that does not reduce
what you can do) happen without notice.

**A change that affects your access to or use of what you subscribed to** is only made for a
valid reason stated in this contract, and it is announced in advance by email to your account
address, saying what is changing and why. If the change is more than a minor inconvenience to
you, you may end this contract free of charge within thirty days of the announcement or of
the change taking effect, whichever is later, and you get back the part of anything you paid
in advance that covers the period you no longer use. The change costs you nothing extra
either way.

The reasons that count as valid are: a legal or regulatory requirement; security; a change
forced on the Service by something it depends on (the payment provider, the edge platform,
an AI provider, a notification channel); removing a feature that does not work as described;
and keeping the Service technically and economically viable. A preference is not a reason.

## Availability, and what is not promised

**No availability percentage is promised.** There is no service level agreement, no uptime
target, no maintenance window commitment and no support response time, in this document, on
this website or in the application. No figure of that kind is published anywhere, and the
absence is deliberate rather than an omission.

The reason is the honest one. One person operates this Service, on infrastructure other
companies run. A number would be a promise that is not the operator's to make, and under EU
law a public statement a trader makes about a service becomes part of what the service is
measured against, so publishing a figure would turn a guess into a contractual target. A
product whose entire subject is uptime is the last product that should quote one it cannot
keep.

**What is owed instead, and it is not nothing.** The Service must match the description in
section 4 and be fit for what such a service is normally used for. Where it does not, you can
require it to be put right and, if it is not, end this contract; on the free plan too. That
duty is the law's, not a term the operator granted, and nothing in section 14 removes it.

Interruptions happen, including planned ones. They are announced where that is practical.
Because of all of the above: do not let the monitoring you depend on rest on a single
supplier, this one included.

## Acceptable use, and suspension

Checks are frequent, automated and arrive from several regions at once, which makes the
Service capable of causing harm if it is pointed somewhere it should not be. So:

- Monitor only endpoints you own or have permission to monitor.
- Do not use the Service to load-test, stress, flood or attack anything, yours or anybody
  else's, and do not set an interval that damages the target.
- Do not publish unlawful, infringing or deliberately misleading content on a status page, and
  do not put other people's personal data there.
- Never put credentials, secrets or personal data into fields that appear on a public status
  page.
- Use a status page's subscriber list only for that page's updates. It is not a mailing list.
- Do not resell access or share it outside your team, and do not create accounts in bulk to
  get around a plan limit.
- Do not interfere with the Service, its rate limits or its infrastructure, and do not try to
  reach another customer's data.

**Suspension.** Where use appears to break the above, or endangers the Service, its
infrastructure or a third party, the operator may suspend a monitor, a status page or an
account. You are told beforehand where that is possible and always told afterwards, with the
reason, and you can reply and have it reconsidered. A suspension goes no wider than the cause
and is lifted when the cause is.

Reporting a security flaw to [[legal.contact_email]] is welcome and is never treated as a
breach of these terms.

## Intellectual property

The Service, its software, design, text and marks belong to the operator or to its licensors.
While this contract lasts you have a personal, non-exclusive, non-transferable right to use
the Service as it is meant to be used, and nothing else transfers to you.

What you put in stays yours: your monitor configuration, your incident notes and updates, and
your status-page content. You grant the operator only what running the Service requires:
storing that content, processing it, showing it on the status pages you choose to publish, and
sending it to the notification channels you configure.

The name Uptizm and [[legal.trade_name]] may not be used in a way that suggests endorsement or
a relationship that does not exist. Third-party components in the Service are used under their
own licences.

If you send feedback or a suggestion, the operator may act on it without owing you anything,
and you lose nothing by sending it.

## Personal data

How personal data is handled is described in the [Privacy Policy](/privacy), which forms part
of these terms. In short, there are two roles: the operator is the controller of your account
and billing data, and a processor of the monitoring data you configure, where you are the
controller and the operator's duty is to assist you.

Requests about your own data go to [[legal.rights_email]]. The operator's supervisory
authority is [[legal.authority]]. As section 1 says, there is no representative in the
European Union. Nothing in these terms reduces a data-protection right you hold, and where a
term here conflicted with one, the right would win.

## Liability

**Nothing in these terms limits or excludes liability for** death or personal injury caused by
the operator's negligence, for intent or gross negligence, for fraud, or for anything else
that cannot lawfully be limited, including your mandatory rights as a consumer. Those
carve-outs sit above everything that follows, and the cap below does not touch them.

**Otherwise, and only as far as the law allows**, the operator's total liability for all claims
connected with the Service in any twelve-month period is limited to the amount you paid for the
Service in the twelve months before the event that gave rise to the claim.

On the free plan you have paid nothing, so that cap leaves nothing in money. It still does not
touch the carve-outs above, and it does not touch the statutory remedies in section 10: having
a Service that does not match its description put right, and ending this contract if it is
not. Those exist on the free plan.

The operator is not liable for loss that neither of us could reasonably have foreseen when this
contract was made. And a distinction worth stating, because this product invites the confusion:
the Service reports outages, it does not cause them. Your own service being down is not a loss
the operator caused. Whether the Service failed to report something it should have reported is a
different question, and it is judged under this section and section 10 rather than excluded by
them.

**Indemnity.** If you use the Service for the purposes of your trade, business, craft or
profession, you will cover the operator against third-party claims arising from what you publish
on a status page or from monitoring you had no right to run. This paragraph does not apply to
consumers.

## Changes to these terms

These terms may change, for a valid reason stated here and for no other: a change in the law or
in a regulator's position, a change in the Service or in something the Service depends on,
security, or correcting an error or an ambiguity. They are not changed simply because a
different term would suit the operator better.

A change is announced at least thirty days before it takes effect, by email to your account
address and on this page, saying what changed and why. Where the change is to your disadvantage
you may end this contract before it takes effect, free of charge. Continuing to use the Service
after that date means the new version applies to you.

The version in force is the one published on this page. No effective date has been published for
this document yet, which is why the line under the title says so; once one is set it appears
there and every later version carries its own.

## Governing law and dispute resolution

Turkish law governs this contract and the courts of İzmir, Türkiye have jurisdiction, in each
case **without prejudice to the mandatory consumer-protection law of the country of the
consumer's habitual residence**.

For a consumer that proviso is the operative part, so here it is in plain words. You keep every
protection the law of your own country gives you, including any that is better than a term in
this document. You may bring proceedings in the courts of the place where you live, and the
operator will bring any proceedings against a consumer only there. If you are in Türkiye, the
consumer arbitration committee or the consumer court for your place of residence is open to you.

None of that is a precondition, but writing to [[legal.contact_email]] first settles most things
faster than any of it.

For a customer who is not a consumer, Turkish law and the courts of İzmir apply without the
proviso.

## Other terms

- **Severability.** If a term is unenforceable, the rest stands and the unenforceable one is
  read down to what the law allows rather than struck out wholesale.
- **No waiver.** Not enforcing a term once does not give it up.
- **Transfer.** You may not transfer this contract without the operator's consent. The operator
  may transfer it to whoever takes over the business, and will tell you in advance; if you do
  not want that, you may end this contract free of charge before it happens.
- **The whole agreement.** These terms and the Privacy Policy are the whole of what is agreed
  about the Service. That does not exclude anything the law adds, and it does not let the
  operator disown a public statement about the Service that formed part of what you signed up
  for.
- **Notices.** To you, at your account email address. To the operator, at
  [[legal.contact_email]].
- **No third-party rights.** Nobody outside this contract acquires rights under it.
- **Events outside anybody's control.** Neither of us is in breach for a delay caused by
  something genuinely outside our reasonable control, and your statutory rights are unaffected
  by that sentence.
- **Languages.** This document is published in English and in Turkish. If the two differ, the
  version in the language you were reading applies to you; a translation is never used to reduce
  a right.
