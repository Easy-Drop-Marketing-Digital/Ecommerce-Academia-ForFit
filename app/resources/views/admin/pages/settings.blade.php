<x-filament-panels::page>
    <p class="text-sm text-gray-500 -mt-2">Clique em um módulo para editar. Chaves e tokens ficam criptografados e nunca são exibidos de volta.</p>

    <div class="sd-modules">
        @foreach ($this->modules() as $key => $m)
            <button type="button" class="sd-module" wire:click="mountAction('editGroup', @js(['group' => $key]))">
                <span class="sd-module__icon">@svg($m['icon'])</span>
                <span class="sd-module__title">{{ $m['label'] }}</span>
                <span class="sd-module__desc">{{ $m['description'] }}</span>
                <span @class(['sd-module__status', 'sd-module__status--ok' => $m['ok'], 'sd-module__status--pending' => ! $m['ok']])>{{ $m['status'] }}</span>
            </button>
        @endforeach
    </div>
</x-filament-panels::page>
