{{--
    Footer. The status-page link is the one thing in this category that reads as
    an admission when it is missing, so it appears the moment we run a public
    page of our own and stays out until then (see
    ShowLandingController::ownStatusPageUrl) rather than pointing at a 404.
--}}
<footer>
    <div class="mx-auto max-w-6xl px-4 py-12 sm:px-6 lg:px-8">
        <div class="flex flex-col gap-10 sm:flex-row sm:justify-between">
            <div class="max-w-xs">
                <a href="/" class="flex items-center gap-2.5 text-fg">
                    @include('marketing.partials.mark', ['class' => 'size-5 text-primary'])
                    <span class="text-title-lg tracking-tight">{{ config('app.name') }}</span>
                </a>
                <p class="mt-3 text-body-md text-fg-muted">
                    {{ __('Uptime, incidents and status pages, with a bias toward telling you the truth.') }}
                </p>
            </div>

            <nav class="flex gap-12 sm:gap-16">
                <div>
                    <p class="text-label-sm uppercase tracking-[0.14em] text-fg-muted">{{ __('Product') }}</p>
                    <ul class="mt-4 space-y-2.5">
                        @foreach ([
                            '#pipeline' => __('How it works'),
                            '#signal' => __('Signal, not noise'),
                            '#capabilities' => __('Capabilities'),
                            '#regions' => __('Regions'),
                        ] as $href => $label)
                            <li>
                                <a href="{{ $href }}" class="text-body-md text-fg-muted transition-colors hover:text-fg">{{ $label }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div>
                    <p class="text-label-sm uppercase tracking-[0.14em] text-fg-muted">{{ __('Account') }}</p>
                    <ul class="mt-4 space-y-2.5">
                        <li><a href="{{ $signUpUrl }}" class="text-body-md text-fg-muted transition-colors hover:text-fg">{{ __('Start free') }}</a></li>
                        <li><a href="{{ $signInUrl }}" class="text-body-md text-fg-muted transition-colors hover:text-fg">{{ __('Sign in') }}</a></li>
                        @if ($ownStatusPageUrl !== null)
                            <li>
                                <a href="{{ $ownStatusPageUrl }}" class="inline-flex items-center gap-2 text-body-md text-fg-muted transition-colors hover:text-fg">
                                    <span class="size-1.5 rounded-full bg-up"></span>
                                    {{ __('Our status') }}
                                </a>
                            </li>
                        @endif
                    </ul>
                </div>
            </nav>
        </div>

        <p class="mt-12 border-t border-border-subtle pt-6 text-label-sm text-fg-muted">
            &copy; {{ now()->year }} {{ config('app.name') }}
        </p>
    </div>
</footer>
