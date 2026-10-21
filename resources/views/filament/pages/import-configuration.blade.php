<x-filament-panels::page>
    <x-filament::section heading="Archivo" description="Sube el configuracion.json que generó el skill. Primero se revisa; nada se guarda hasta que confirmes.">
        <form wire:submit="review" class="grid gap-4">
            @if (auth()->user()?->is_super_admin)
                <label class="grid gap-1 text-sm">
                    <span class="font-medium">Tienda</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="storeId">
                            @foreach ($this->getStoreOptions() as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
            @endif

            <label class="grid gap-1 text-sm">
                <span class="font-medium">configuracion.json</span>
                <input type="file" wire:model="file" accept=".json,application/json" class="text-sm">
                @error('file') <span class="text-danger-600 text-sm">{{ $message }}</span> @enderror
            </label>

            <label class="flex items-start gap-2 text-sm">
                <x-filament::input.checkbox wire:model.live="updateStoreTexts" />
                <span>
                    Actualizar también el system prompt y la bienvenida de la tienda.
                    <span class="block text-gray-500">Afectan a todos los productos de la tienda.</span>
                </span>
            </label>

            <div>
                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="file,review">Revisar</x-filament::button>
            </div>
        </form>
    </x-filament::section>

    @if ($report)
        @if ($report['errors'])
            <x-filament::section heading="Errores: no se puede importar" icon="heroicon-o-x-circle" icon-color="danger">
                <ul class="list-disc ps-5 text-sm">
                    @foreach ($report['errors'] as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        @if ($report['warnings'])
            <x-filament::section heading="Avisos" icon="heroicon-o-exclamation-triangle" icon-color="warning">
                <ul class="list-disc ps-5 text-sm">
                    @foreach ($report['warnings'] as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        @if ($report['rows'])
            <x-filament::section :heading="$report['applied'] ? 'Resultado' : 'Qué se hará'">
                <div class="grid gap-3 text-sm">
                    @foreach ($report['rows'] as $row)
                        <div class="border-b border-gray-200 pb-2 dark:border-white/10">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-filament::badge :color="match ($row['action']) { 'create', 'new_version' => 'success', 'update' => 'warning', default => 'gray' }">
                                    {{ $this->actionLabel($row['action']) }}
                                </x-filament::badge>
                                <span class="font-medium">{{ $row['element'] }}</span>
                                @if ($row['note'])
                                    <span class="text-gray-500">· {{ $row['note'] }}</span>
                                @endif
                            </div>

                            @if ($row['changes'])
                                <details class="mt-2">
                                    <summary class="cursor-pointer text-gray-600 dark:text-gray-400">{{ count($row['changes']) }} campo(s) cambian</summary>
                                    @foreach ($row['changes'] as $field => $change)
                                        <div class="mt-2 grid gap-1">
                                            <span class="font-mono text-xs">{{ $field }}</span>
                                            <div class="grid gap-2 md:grid-cols-2">
                                                <pre class="whitespace-pre-wrap rounded bg-gray-50 p-2 text-xs dark:bg-white/5">Actual: {{ $this->show($change['old']) }}</pre>
                                                <pre class="whitespace-pre-wrap rounded bg-gray-50 p-2 text-xs dark:bg-white/5">Nuevo: {{ $this->show($change['new']) }}</pre>
                                            </div>
                                        </div>
                                    @endforeach
                                </details>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        @if ($report['pending'])
            <x-filament::section heading="Pendiente por completar" description="La importación no los toca: complétalos en el panel.">
                <ul class="list-disc ps-5 text-sm">
                    @foreach ($report['pending'] as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        @if (! $report['applied'] && ! $report['errors'])
            <div>
                @if ($report['has_changes'])
                    <x-filament::button color="success" wire:click="import" wire:loading.attr="disabled" wire:target="import">
                        Importar
                    </x-filament::button>
                @else
                    <p class="text-sm text-gray-500">No hay nada que cambiar: la configuración ya está cargada.</p>
                @endif
            </div>
        @endif
    @endif
</x-filament-panels::page>
