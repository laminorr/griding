<x-filament-panels::page>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <x-filament::section>
            <x-slot name="heading">Plan</x-slot>
            <pre class="text-xs overflow-auto">{{ json_encode($plan, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Diff</x-slot>
            <pre class="text-xs overflow-auto">{{ json_encode($diff, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Summary</x-slot>
            <pre class="text-xs overflow-auto">{{ json_encode($summary, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>
        </x-filament::section>
    </div>
</x-filament-panels::page>