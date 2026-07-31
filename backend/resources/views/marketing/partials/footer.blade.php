{{--
    Footer. Four columns, so it carries the page's structure rather than reading
    as a copyright line with two links stapled to it.

    The status-page link is the one thing in this category that reads as an
    admission when it is missing, so it appears the moment we run a public page of
    our own and stays out until then (see ShowLandingController::ownStatusPageUrl)
    rather than pointing at a 404. Same rule for the platform links: a store link
    exists only once there is a listing behind it.
--}}
<footer class="bg-surface-container">
    <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6 lg:px-8">
        <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-[1.4fr_1fr_1fr_1fr]">
            <div class="max-w-xs">
                <a href="/" class="flex items-center gap-2.5 text-fg">
                    @include('marketing.partials.mark', ['class' => 'size-6 text-primary'])
                    <span class="text-title-lg tracking-tight">{{ config('app.name') }}</span>
                </a>
                <p class="mt-3 text-body-md text-fg-muted">
                    {{ __('Uptime, incidents and status pages, with a bias toward telling you the truth.') }}
                </p>

                @if ($ownStatusPageUrl !== null)
                    <a
                        href="{{ $ownStatusPageUrl }}"
                        class="mt-5 inline-flex items-center gap-2 rounded-full bg-up-soft px-3 py-1.5 text-label-sm text-up-soft-foreground"
                    >
                        <span class="size-1.5 rounded-full bg-up"></span>
                        {{ __('Our own status page') }}
                    </a>
                @endif
            </div>

            @foreach ([
                [
                    'title' => __('Product'),
                    'links' => [
                        '#pipeline' => __('How it works'),
                        '#signal' => __('Signal, not noise'),
                        '#capabilities' => __('Capabilities'),
                        '#regions' => __('Regions'),
                    ],
                ],
                [
                    'title' => __('Platforms'),
                    'links' => [
                        '#platforms' => __('Web, iOS and Android'),
                        '#status-pages' => __('Status pages'),
                    ],
                ],
                [
                    'title' => __('Account'),
                    'links' => [
                        // Keys are the URLs, so these two are resolved at runtime.
                        'signUp' => __('Start free'),
                        'signIn' => __('Sign in'),
                    ],
                ],
            ] as $column)
                <div>
                    <p class="text-label-sm uppercase tracking-[0.14em] text-fg-muted">{{ $column['title'] }}</p>
                    <ul class="mt-4 space-y-2.5">
                        @foreach ($column['links'] as $href => $label)
                            <li>
                                <a
                                    href="{{ match ($href) { 'signUp' => $signUpUrl, 'signIn' => $signInUrl, default => $href } }}"
                                    class="text-body-md text-fg-muted transition-colors hover:text-fg"
                                >{{ $label }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>

        <div class="mt-12 flex flex-col gap-3 border-t border-border-subtle pt-6 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-label-sm text-fg-muted">&copy; {{ now()->year }} {{ config('app.name') }}</p>
            <p class="text-label-sm text-fg-muted">
                {{ __('Probes run at the Cloudflare edge. Built with Flutter and Laravel.') }}
            </p>
        </div>
    </div>
</footer>
