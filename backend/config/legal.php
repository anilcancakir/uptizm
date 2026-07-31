<?php

/*
|--------------------------------------------------------------------------
| Legal Identity Catalog
|--------------------------------------------------------------------------
|
| The single source of truth for the operator identity rendered on the Privacy,
| Terms and Contact pages (Turkish e-Commerce Law Art. 5 service-provider
| disclosure, plus the GDPR controller-identity fields). Every legal page
| interpolates these keys via bracketed placeholders instead of hardcoding the
| operator's name, address or contact details, so a change here is a one-line
| fix everywhere instead of a Markdown hunt.
|
| Every value is env-overridable so a production deployment (or a future
| operator change) never needs a code change, only an env update.
|
*/

return [

    // The legal person behind the service. A Turkish sole proprietorship has no
    // separate corporate identity from its owner, so the legal person IS the
    // individual, not just the trade name. Art. 5 asks for the service
    // provider's name; this is it.
    'operator' => env('LEGAL_OPERATOR', 'Anılcan Çakır (Kodizm)'),

    // The trading name the product is marketed under, distinct from the legal
    // person above.
    'trade_name' => env('LEGAL_TRADE_NAME', 'Kodizm'),

    // Verbatim as supplied: do not reformat or "clean up" the address, commas
    // included.
    'address' => env('LEGAL_ADDRESS', 'Akdeniz, Akdeniz Cd. Akdeniz İş Hanı No:5 D:519, 35210 Konak/İzmir'),

    // A mobile number, not a landline. Held here rather than inline in a
    // Markdown file so a future landline switch is a config change, not a
    // content edit.
    'phone' => env('LEGAL_PHONE', '+90 534 212 46 60'),

    // For a sole proprietorship the tax number IS the national ID (TC kimlik
    // number), not a separate business VAT number. Publishing it therefore
    // publishes the operator's national identity number; see `tax_number_kind`
    // for why the page must label it honestly rather than presenting it as an
    // ordinary VAT id.
    'tax_number' => env('LEGAL_TAX_NUMBER', '44938660202'),

    // Discriminates what `tax_number` actually is, so a page can render the
    // correct label ("TC Kimlik No" for `tc`) instead of assuming every
    // operator publishes a VAT number. Kept separate from `tax_number` itself
    // because the two facts (the value and its kind) can change independently.
    'tax_number_kind' => env('LEGAL_TAX_NUMBER_KIND', 'tc'),

    // The address a visitor or regulator contacts for anything (Art. 5's
    // general contact channel).
    'contact_email' => env('LEGAL_CONTACT_EMAIL', 'info@kodizm.com'),

    // The address a data subject uses to exercise a GDPR/KVKK rights request
    // (access, deletion, correction). Currently the same inbox as
    // `contact_email`, per the operator; kept as a distinct key because the
    // two addresses are allowed to diverge later without touching every page
    // that names the general contact address.
    'rights_email' => env('LEGAL_RIGHTS_EMAIL', 'info@kodizm.com'),

    // GDPR Art. 27: a non-EU controller offering services to EU data subjects
    // and engaging in continuous monitoring should designate an EU
    // representative unless the processing is occasional and low risk, which
    // this product's uptime monitoring is not. No representative has been
    // designated. This is a recorded, accepted gap rather than an oversight;
    // it stays null on purpose and the Privacy page must render this absence
    // honestly instead of inventing a name. Not env-overridable by design:
    // there is nothing to override until a representative is actually
    // appointed, at which point this line changes along with the page copy.
    'eu_representative' => null,

    // The operator's own supervisory authority for data protection complaints.
    // The operator is Turkey-based, so this is KVKK, not a GDPR lead
    // authority; a future EU representative appointment would not change this
    // key, only add `eu_representative` above.
    'authority' => env('LEGAL_AUTHORITY', 'KVKK (Kişisel Verileri Koruma Kurumu)'),

    // No publish date was supplied for the legal pages and this catalog does
    // not invent one. Null here means the page must render an honest "not yet
    // set" state rather than a guessed date; setting LEGAL_EFFECTIVE_DATE in a
    // deployment is what turns the pages "live" with a real date.
    'effective_date' => env('LEGAL_EFFECTIVE_DATE'),

];
