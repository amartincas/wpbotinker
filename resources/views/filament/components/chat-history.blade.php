<div wire:poll.5s class="w-full">
    @if ($record && $messages && $messages->count() > 0)
        <div class="flex flex-col max-h-[500px] overflow-y-auto bg-gray-50 rounded-lg border border-gray-200 p-4 space-y-3">
            @foreach ($messages as $message)
                @if ($message->role === 'user')
                    <!-- User Message (Left-aligned) -->
                    <div class="flex justify-start">
                        <div class="bg-white text-gray-900 rounded-lg rounded-tl-none px-4 py-2 max-w-xs shadow-sm">
                            <p class="text-sm break-words whitespace-pre-wrap">{{ $message->content }}</p>
                            <span class="text-xs text-gray-500 mt-1 block">{{ $message->created_at->format('H:i') }}</span>
                        </div>
                    </div>
                @else
                    <!-- Assistant Message (Right-aligned) -->
                    <div class="flex justify-end">
                        <div class="bg-green-500 text-white rounded-lg rounded-tr-none px-4 py-2 max-w-xs shadow-sm">
                            <p class="text-sm break-words whitespace-pre-wrap">{{ $message->content }}</p>
                            <span class="text-xs text-green-100 mt-1 block text-right">{{ $message->created_at->format('H:i') }}</span>
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    @else
        <div class="flex items-center justify-center bg-gray-50 rounded-lg border border-gray-200 p-8 text-center">
            <p class="text-gray-500 text-sm">Todavía no hay historial de conversación.</p>
        </div>
    @endif
</div>
