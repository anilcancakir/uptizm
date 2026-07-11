{{-- Footer: the page's own public URL (never the request URL) plus the brand line. --}}
<footer class="flex flex-col items-center gap-1 py-6 text-center text-xs text-gray-500">
    <a href="{{ route('status.show', $vm->page['slug']) }}" class="hover:underline">
        {{ route('status.show', $vm->page['slug']) }}
    </a>
    <span>Powered by Uptizm</span>
</footer>
