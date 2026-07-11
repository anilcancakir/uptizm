{{-- Brand header: the page's logo tile (in its brand color) plus its name. --}}
<header class="flex items-center gap-3 pb-6">
    <div
        class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg text-lg font-semibold text-white"
        style="background-color: {{ $vm->page['brand_color'] ?? '#111827' }}"
    >
        {{ $vm->page['logo_text'] ?? \Illuminate\Support\Str::substr($vm->page['name'], 0, 2) }}
    </div>

    <div>
        <h1 class="text-xl font-semibold">{{ $vm->page['name'] }}</h1>
        @if ($vm->page['description'])
            <p class="text-sm text-gray-500">{{ $vm->page['description'] }}</p>
        @endif
    </div>
</header>
