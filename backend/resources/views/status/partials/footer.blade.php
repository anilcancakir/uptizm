{{-- Footer: the page's own public URL (never the request URL) plus the brand line.

     The ONE partial on this surface with no `__()` in it, deliberately. "Powered
     by Uptizm" is a brand line rather than copy: it names the product the same
     way in every language, exactly as the URL above it does. A locale key here
     would invite a translation of a proper noun. --}}
<footer class="flex flex-col items-center gap-1 py-6 text-center text-xs text-fg-muted">
    <a href="{{ route('status.show', $vm->page['slug']) }}" class="hover:underline">
        {{ route('status.show', $vm->page['slug']) }}
    </a>
    <span>Powered by Uptizm</span>
</footer>
