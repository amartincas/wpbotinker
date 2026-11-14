<div>
    {{-- 
        Alpine component registered globally via Alpine.data().
        Lives in a <script> tag inside the single root div but BEFORE
        any Alpine-controlled elements so it's available at init time.
    --}}
    <script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('templateModal', () => ({
            open: false,
            currentTemplateId: null,
            currentParamsMap: {},
            templateValues: [],
            templateFormVisible: false,
            selectedTemplateName: '',

            init() {
                this.leadId    = parseInt(this.$el.dataset.leadId)  || 0;
                this.apiToken  = this.$el.dataset.apiToken           || '';
                this.csrfToken = this.$el.dataset.csrfToken          || '';
                // Expose instance globally so the ⚡ button (inside Livewire DOM) can reach it
                window.__templateModal = this;
            },

            openModal() {
                this.open                 = true;
                this.templateFormVisible  = false;
                this.currentTemplateId    = null;
                this.selectedTemplateName = '';
                this.currentParamsMap     = {};
                this.templateValues       = [];
                this.selectedTemplateRequiresPhone = false;
                this.externalPhone = '';
            },

            closeModal() {
                this.open = false;
            },

            selectTemplate(id, name, paramsMap, requiresPhone = false) {
                this.currentTemplateId    = id;
                this.selectedTemplateName = name;
                this.currentParamsMap     = paramsMap;
                this.templateValues = {};
                Object.keys(paramsMap).forEach(k => {
                    this.templateValues[k] = '';
                });
                this.selectedTemplateRequiresPhone = !!requiresPhone;
                this.externalPhone = '';
                console.log('selectTemplate paramsMap', paramsMap);
                console.log('templateValues inicializado', this.templateValues);
                this.templateFormVisible  = true;
            },

            async submitTemplate() {
                const customValues = Object.keys(this.currentParamsMap)
                .sort((a, b) => Number(a) - Number(b))
                .map(k => (this.templateValues[k] || '').trim());

                console.log('submitTemplate customValues', customValues, 'requiresPhone', this.selectedTemplateRequiresPhone, 'externalPhone', this.externalPhone);

                const chatComponent = Livewire.all().find(c => c.name === 'whats-app-chat-center');

                if (!chatComponent) {
                    return;
                }

                await chatComponent.$wire.sendTemplate(this.currentTemplateId, customValues, this.selectedTemplateRequiresPhone ? (this.externalPhone || null) : null);
                this.closeModal();
            }
        }));
    });
    </script>

    {{-- ── MAIN CHAT GRID ───────────────────────────────────────────────────── --}}
    <div style="display: grid; grid-template-columns: repeat(12, 1fr); gap: 0; border: 1px solid #e5e7eb; height: 85vh; overflow: hidden; border-radius: 12px; background: white;">

        {{-- LEFT PANEL: Conversation list --}}
        <div wire:poll.10s style="grid-column: span 4 / span 4; border-right: 1px solid #e5e7eb; display: flex; flex-direction: column; background: #f9fafb; height: 100%; min-height: 0; overflow: hidden;">

            @if(Auth::user()->is_super_admin)
                <div style="padding: 1rem; border-bottom: 1px solid #e5e7eb; background: white; flex-shrink: 0;">
                    <select wire:model.live="filterStoreId" style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.875rem; background: white;">
                        <option value="">Todas las Tiendas</option>
                        @foreach($stores as $store)
                            <option value="{{ $store->id }}">{{ $store->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div style="padding: 16px; border-bottom: 1px solid #e5e7eb; background: white; flex-shrink: 0;">
                <h2 style="font-weight: bold; color: black; margin: 0;">WhatsApp Chats</h2>
            </div>

            <div style="flex: 1; overflow-y: auto; min-height: 0; background: white;" class="custom-scrollbar">
                @foreach($conversations as $conversation)
                    <button wire:click="selectConversation('{{ $conversation->customer_phone }}')"
                        style="width: 100%; padding: 12px; text-align: left; border: none; border-bottom: 1px solid #f3f4f6; cursor: pointer; {{ $selectedPhone === $conversation->customer_phone ? 'background-color: #f0fdf4;' : 'background: white;' }}">
                        <div style="font-size: 14px; font-weight: 500; color: black;">
                            {{ $conversation->customer_phone }}
                        </div>
                        <div style="font-size: 11px; color: #6b7280;">
                            {{ \Carbon\Carbon::parse($conversation->last_message_at)->diffForHumans() }}
                        </div>
                    </button>
                @endforeach
            </div>
        </div>

        {{-- RIGHT PANEL: Chat area --}}
        <div style="grid-column: span 8 / span 8; display: flex; flex-direction: column; background: #e5ddd5; position: relative; height: 100%; overflow: hidden;">
            @if ($selectedPhone)

                <div style="display: flex; flex-direction: column; height: 100%;">

                    {{-- Chat header --}}
                    <div style="padding: 1rem; border-bottom: 1px solid #e5e7eb; background: white; flex-shrink: 0;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <h3 style="font-weight: 700; color: #111827; margin: 0;">{{ $selectedPhone }}</h3>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="font-size: 12px; font-weight: 700; color: #4b5563;">BOT</span>
                                <input type="checkbox" wire:model.live="botActive" style="cursor: pointer; width: 16px; height: 16px;">
                            </div>
                        </div>
                    </div>

                    {{-- Messages --}}
                    <div id="chat-container" wire:poll.3s="getMessageStatuses" style="flex: 1; overflow-y: auto; padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem;">
                        @foreach ($messages as $message)
                            <div wire:key="msg-{{ $message->id }}" style="display: flex; width: 100%; justify-content: {{ $message->role === 'user' ? 'flex-start' : 'flex-end' }};">
                                <div style="display: flex; align-items: flex-end; gap: 0.3rem; max-width: 75%;">
                                    <div style="padding: 0.6rem 1rem; border-radius: 12px; background: {{ $message->role === 'user' ? 'white' : '#dcf8c6' }}; box-shadow: 0 1px 1px rgba(0,0,0,0.1);">
                                        <p style="font-size: 14px; margin: 0; word-wrap: break-word; white-space: pre-wrap; color: #111827;">{{ $message->content }}</p>
                                        <div style="font-size: 10px; color: #6b7280; text-align: right; margin-top: 4px;">{{ $message->displayedAt() }}</div>
                                    </div>
                                    @if($message->role === 'assistant')
                                        <div style="font-size: 12px; margin-bottom: 2px; min-width: 20px; text-align: center;">
                                            @php
                                                $status = $messageStatuses[$message->id] ?? null;
                                                $statusValue = $status['status'] ?? 'pending';
                                            @endphp
                                            @if($statusValue === 'pending')
                                                <span title="Enviando..." style="color: #9ca3af;">⏱️</span>
                                            @elseif($statusValue === 'sent')
                                                <span title="Enviado" style="color: #6b7280;">✓</span>
                                            @elseif($statusValue === 'delivered')
                                                <span title="Entregado" style="color: #6b7280;">✓✓</span>
                                            @elseif($statusValue === 'read')
                                                <span title="Leído" style="color: #3b82f6;">✓✓</span>
                                            @elseif($statusValue === 'failed')
                                                <span title="No enviado" style="color: #ef4444;">✗</span>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    {{-- Input bar --}}
                    <div style="padding: 1rem; background: #f0f0f0; border-top: 1px solid #e5e7eb; flex-shrink: 0;">
                        <div style="display: flex; gap: 10px; align-items: center;">
                            {{-- Plain onclick — reaches the Alpine instance via window.__templateModal --}}
                            <button
                                onclick="window.__templateModal && window.__templateModal.openModal()"
                                title="Enviar Plantilla"
                                style="background: #3b82f6; color: white; padding: 0.6rem; border-radius: 50%; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center;">
                                ⚡
                            </button>

                            <input type="text" wire:model="newMessage" wire:keydown.enter="sendMessage"
                                placeholder="Escribe un mensaje..."
                                style="flex: 1; padding: 0.6rem; border-radius: 20px; border: 1px solid #ccc; outline: none; color: black;">

                            <button wire:click="sendMessage" style="background: #25d366; color: white; padding: 0.5rem 1.2rem; border-radius: 20px; font-weight: bold; border: none; cursor: pointer;">
                                Enviar
                            </button>
                        </div>
                    </div>
                </div>

            @else
                <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: #6b7280;">
                    <p>Seleccione un chat para comenzar a conversar</p>
                </div>
            @endif
        </div>
    </div>

    {{-- ── TEMPLATE MODAL ───────────────────────────────────────────────────── --}}
    {{--
        Sits inside the single root <div> but AFTER the grid, so it is a sibling
        of the chat grid — not a child. Livewire morphs children of its component
        root but never re-initialises siblings that already have Alpine state.
        position:fixed means it visually escapes the grid regardless of DOM position.
    --}}
    <div
        x-data="templateModal"
        wire:ignore
        data-lead-id="{{ $selectedLeadId ?? 0 }}"
        data-api-token="{{ Auth::user()->api_token ?? '' }}"
        data-csrf-token="{{ csrf_token() }}"
        x-show="open"
        @click.self="closeModal()"
        style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; padding: 2rem;"
    >
        <div style="background: white; border-radius: 12px; width: 100%; max-width: 500px; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);">

            {{-- Modal header --}}
            <div style="padding: 1rem; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                <h4 style="font-weight: 700; color: black; margin: 0;">Plantillas de WhatsApp</h4>
                <button @click="closeModal()" style="border: none; background: none; font-size: 1.5rem; cursor: pointer; color: black; line-height: 1;">&times;</button>
            </div>

            {{-- Modal body --}}
            <div style="padding: 1rem; overflow-y: auto; max-height: 450px;">

                <p style="font-size: 0.875rem; color: #4b5563; margin-bottom: 1rem;">
                    Selecciona una plantilla para enviar.
                </p>

                {{-- Template list --}}
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    @foreach(\App\Models\WhatsAppTemplate::where('store_id', Auth::user()->store_id)->get() as $tpl)
                        <div
                            style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px; cursor: pointer; background: white;"
                            @click="selectTemplate('{{ $tpl->id }}', '{{ $tpl->name }}', {{ Js::from($tpl->parameters_map) }}, {{ $tpl->requires_phone_input ? 'true' : 'false' }})"
                            @mouseenter="$el.style.background='#f9fafb'"
                            @mouseleave="$el.style.background='white'">
                            <div style="font-weight: bold; font-size: 13px; color: #2563eb;">{{ $tpl->name }}</div>
                            <div style="font-size: 12px; color: #6b7280; margin-top: 4px;">{{ $tpl->body_preview }}</div>
                        </div>
                    @endforeach
                </div>

                {{-- Parameter form --}}
                <div x-show="templateFormVisible" style="margin-top: 1.5rem; border-top: 2px solid #f3f4f6; padding-top: 1rem;">

                    <h5
                        x-text="'Configurar: ' + selectedTemplateName"
                        style="font-size: 14px; font-weight: bold; margin-bottom: 10px; color: black;">
                    </h5>

                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        <div x-show="selectedTemplateRequiresPhone" style="display: none;">
                            <label style="font-size: 11px; font-weight: bold; color: #374151; display: block; margin-bottom: 4px;">Enviar a (telefono):</label>
                            <input type="text" x-model="externalPhone" placeholder="593995909073 or +593995909073" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; color: black; box-sizing: border-box;" />
                        </div>
                        <template x-for="(fieldName, key) in currentParamsMap" :key="key">
                            <div>
                                <label
                                    style="font-size: 11px; font-weight: bold; color: #374151; display: block; margin-bottom: 4px;"
                                    x-text="`Dato para {${key}} (${fieldName}):`">
                                </label>
                                <input
                                    type="text"
                                    x-model="templateValues[key]"
                                    style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; color: black; box-sizing: border-box;">
                            </div>
                        </template>
                    </div>

                    <button
                        @click="submitTemplate()"
                        style="margin-top: 15px; width: 100%; background: #2563eb; color: white; padding: 10px; border-radius: 8px; font-weight: bold; border: none; cursor: pointer;">
                        Enviar Plantilla Oficial
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('livewire:initialized', () => {
            const container = document.getElementById('chat-container');
            const scrollDown = () => { if (container) container.scrollTop = container.scrollHeight; }
            scrollDown();
            Livewire.on('scroll-down', () => { setTimeout(scrollDown, 50); });
        });

        // Suppress Livewire internal poll unhandled rejections
        window.addEventListener('unhandledrejection', function(event) {
            const reason = event.reason;
            if (
                reason &&
                typeof reason === 'object' &&
                reason.status === null &&
                reason.body === null &&
                reason.json === null &&
                reason.errors === null
            ) {
                event.preventDefault();
            }
        });
    </script>
</div>