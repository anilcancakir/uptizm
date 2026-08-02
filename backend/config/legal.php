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
| WHY THE PERSONAL VALUES ARE ENV-ONLY, WITH NO DEFAULT
|
| They used to be code defaults: a legal name, a street address, a mobile
| number and an 11-digit tax number that, for a Turkish sole proprietor, IS the
| national identity number. That put four pieces of one person's personal data
| in version control and on eight public URLs, and the product has not launched
| yet, so it published them before anything was being sold. The operator's
| decision is that the registered company details arrive WITH the launch. Until
| then every one of those slots is null, nothing personal ships in this
| repository, and the pages OMIT the row entirely rather than rendering a
| blank, a dash, a "not published yet" notice or an invented value (see
| `identityRow()` in ShowTermsController and ShowPrivacyController). The pages
| grow one identity row at a time as this catalog is filled in, never sooner.
|
| WHAT MUST BE FILLED BEFORE THESE PAGES GO LIVE
|
| `operator`, `address`, `phone`, `kep_address`, and ONE of `registry_number`
| (MERSİS) or `tax_number` (VKN). The 29.12.2022 / 32058 e-commerce regulation
| (the 2015 one everyone cites is repealed by its Madde 34(1)) asks for those
| under an "iletişim" heading "eksiksiz olarak", and Madde 5(1)(a) splits on
| tacir vs esnaf rather than on natural vs legal person: a tacir publishes
| MERSİS and is never asked for a VKN, an esnaf publishes the VKN. Which one
| this operator is (TTK 11(2)) is an open question for the accountant, so the
| catalog carries both slots and the page labels whichever is filled.
| `tests/Feature/Legal/LegalIdentityTest.php` holds that checklist as a check
| that ENUMERATES what is unfilled rather than failing on it, because a suite
| that is red as a matter of course gates nothing.
|
| Sourced in .ac/plans/legal-support-pages-uptizm-marketing/research/
| librarian-identity-and-ai-refunds.md. None of it is legal advice, and the
| pages say so on their face.
|
*/

return [

    // The party the customer contracts with: the ticaret unvanı for a tacir, or
    // the name and surname for an esnaf (TTK 41 makes the legal name of a
    // gerçek kişi tacir its ticaret unvanı, so an işletme adı may sit beside it
    // and can never replace it). Null until launch: this is personal data for a
    // sole proprietor, and a default here republishes it on every deployment.
    'operator' => env('LEGAL_OPERATOR'),

    // The trading name the product is marketed under, distinct from the legal
    // person above. A BRAND rather than personal data, so it keeps its default:
    // the pages interpolate it mid-sentence (the intellectual-property clause),
    // where an empty value would be a hole in a sentence rather than an honest
    // absence.
    'trade_name' => env('LEGAL_TRADE_NAME', 'Kodizm'),

    // The merkez adresi as entered in the ticaret sicili (Madde 4(l) defines
    // it; Madde 5(1)(a), Mesafeli Sözleşmeler Yönetmeliği Madde 5(1)(c) and CRD
    // Art. 6(1)(c) require it). Keeping a REGISTERED OFFICE is what keeps a
    // home address off a public page: the residence fallback bites only for a
    // trader with no fixed premises. Null until launch.
    'address' => env('LEGAL_ADDRESS'),

    // Required outright by both Turkish regulations, and post-Omnibus CRD Art.
    // 6(1)(c) dropped the "where available" qualifier. Nothing says it must be
    // a mobile, so a business line is the real mitigation. Null until launch.
    'phone' => env('LEGAL_PHONE'),

    // MERSİS numarası: the registry identifier a TACIR publishes, and the one
    // that REPLACES a published tax number for that operator (Madde 5(1)(a)).
    // New slot: the pages carried no registry id at all and this catalog does
    // not invent one. Null until it exists.
    'registry_number' => env('LEGAL_REGISTRY_NUMBER'),

    // KEP adresi (kayıtlı elektronik posta), required by Madde 5(1)(b). New
    // slot: the pages carried none and nobody had raised it. It is not an
    // ordinary mailbox and cannot be satisfied by `contact_email`, so it stays
    // null rather than being filled with the wrong kind of address.
    'kep_address' => env('LEGAL_KEP_ADDRESS'),

    // The VKN an ESNAF publishes, which for a natural person is the TC kimlik
    // number. Null until launch and quite possibly forever: if the operator is
    // a tacir, `registry_number` above is what gets published and this slot is
    // never used. `tax_number_kind` is what stops a page mislabelling whatever
    // does land here.
    'tax_number' => env('LEGAL_TAX_NUMBER'),

    // Discriminates what `tax_number` actually is, so a page can render the
    // correct label ("TC Kimlik No" for `tc`, a VAT number for `vat`) instead
    // of assuming every operator publishes the same kind of identifier. Kept
    // separate from `tax_number` because the two facts (the value and its kind)
    // can change independently, and null with it: an unlabelled number would be
    // published as something it is not.
    'tax_number_kind' => env('LEGAL_TAX_NUMBER_KIND'),

    // The address a visitor or regulator contacts for anything (Art. 5's
    // general contact channel). A BUSINESS address, not personal data, and it
    // keeps its default because it is the channel every page falls back to
    // while the identity block above is unfilled: the Contact page is built
    // around it. On the product's own domain rather than the operator's
    // former placeholder inbox, because this is the address the pages now
    // publish as the whole of the identity block that renders today.
    'contact_email' => env('LEGAL_CONTACT_EMAIL', 'hello@uptizm.com'),

    // The address a data subject uses to exercise a GDPR/KVKK rights request
    // (access, deletion, correction). Currently the same inbox as
    // `contact_email`, per the operator; kept as a distinct key because the
    // two addresses are allowed to diverge later without touching every page
    // that names the general contact address.
    'rights_email' => env('LEGAL_RIGHTS_EMAIL', 'hello@uptizm.com'),

    // GDPR Art. 27: a non-EU controller offering services to EU data subjects
    // and engaging in continuous monitoring should designate an EU
    // representative unless the processing is occasional and low risk, which
    // this product's uptime monitoring is not. No representative has been
    // designated. This is a recorded, accepted gap rather than an oversight;
    // it stays null on purpose and the Privacy page must render this absence
    // honestly instead of inventing a name. Not env-overridable by design:
    // there is nothing to override until a representative is actually
    // appointed, at which point this line changes along with the page copy.
    // Art. 27(4) is also the mitigation worth knowing about: a representative
    // may be addressed INSTEAD of the controller, which would give the
    // EU-facing pages a lawful contact that is not the operator's own address.
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
