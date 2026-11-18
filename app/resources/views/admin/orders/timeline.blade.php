@php $events = $getRecord()->events()->with('user:id,name')->get(); @endphp
<ol class="relative border-s border-gray-200 dark:border-white/10 ms-2">
    @forelse ($events as $e)
        <li class="mb-4 ms-4">
            <span class="absolute -start-1.5 mt-1.5 h-3 w-3 rounded-full {{ in_array($e->type, ['payment', 'shipped', 'delivered']) ? 'bg-primary-500' : (in_array($e->type, ['fraud', 'cancel', 'refund']) ? 'bg-danger-500' : 'bg-gray-300') }}"></span>
            <time class="text-xs text-gray-500">{{ $e->created_at?->format('d/m/Y H:i') }}@if ($e->user) · {{ $e->user->name }}@endif</time>
            <p class="text-sm">{{ $e->message }}</p>
        </li>
    @empty
        <li class="ms-4 text-sm text-gray-500">Sem eventos.</li>
    @endforelse
</ol>
