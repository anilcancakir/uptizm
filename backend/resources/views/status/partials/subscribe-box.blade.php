{{--
    Subscribe form: only rendered when the page owner has turned subscriptions
    on. Posts to the already-registered `status.subscribe` route name; the
    controller behind it lands in a later step. No CSRF token: the public
    status page runs on the session-less `static` middleware group, so there
    is no session to mint one from.
--}}
<section class="mb-6 rounded-lg border border-gray-200 bg-white px-5 py-4">
    <h2 class="mb-1 text-sm font-semibold text-gray-500 uppercase">Subscribe to updates</h2>
    <p class="mb-3 text-sm text-gray-500">Get an email when {{ $vm->page['name'] }} posts a new incident.</p>

    <form method="POST" action="{{ route('status.subscribe', $vm->page['slug']) }}" class="flex flex-col gap-2 sm:flex-row">
        <input
            type="email"
            name="email"
            required
            placeholder="you@example.com"
            class="w-full flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm"
        >
        <button
            type="submit"
            class="rounded-md px-4 py-2 text-sm font-semibold text-white"
            style="background-color: {{ $vm->page['brand_color'] ?? '#111827' }}"
        >
            Subscribe
        </button>
    </form>
</section>
