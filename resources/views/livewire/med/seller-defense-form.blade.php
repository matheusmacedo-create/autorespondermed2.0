<div class="mx-auto max-w-3xl">
    <div class="rounded-2xl bg-white p-6 shadow sm:p-8">
        <h1 class="text-[25px] font-extrabold tracking-tight text-primary-600">Contestação de Pix (MED)</h1>
        <p class="mt-1 text-sm text-slate-600">
            O pagador de um Pix que você recebeu pediu a devolução pelo Mecanismo Especial de Devolução do Banco Central.
            Mostre que a venda foi legítima para que a devolução seja recusada.
        </p>

        @if ($dispute)
            <dl class="mt-6 grid grid-cols-2 gap-4 rounded-xl bg-slate-50 p-4 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-xs text-slate-500">Valor</dt>
                    <dd class="font-semibold text-slate-900">{{ $dispute->formattedValue() }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Recebido por</dt>
                    <dd class="font-semibold text-slate-900">{{ $dispute->wooviAccount->name }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Motivo informado</dt>
                    <dd class="font-semibold text-slate-900">{{ $dispute->dispute_reason ?: 'Não informado' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Envie até</dt>
                    <dd class="font-semibold text-coral-600">{{ $dispute->respond_by?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? '—' }}</dd>
                </div>
                <div class="col-span-2 sm:col-span-4">
                    <dt class="text-xs text-slate-500">Identificador do Pix (endToEndId)</dt>
                    <dd class="break-all font-mono text-xs text-slate-700">{{ $dispute->end_to_end_id }}</dd>
                </div>
            </dl>
        @endif

        @if ($dossierItems && ! $blockedMessage && ! $submitted && $dispute && $dispute->status->acceptsSellerSubmission())
            <div class="mt-6 rounded-xl border border-green-200 bg-green-50 p-4 text-sm text-green-900">
                <p class="font-semibold">A ÚnicoPag já reuniu para a sua defesa:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach ($dossierItems as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>
                <p class="mt-2 text-xs text-green-800">Não precisa enviar estes de novo. Mande o seu relato e o que só você tem: nota fiscal, conversa com o comprador, comprovante de entrega.</p>
            </div>
        @endif

        @if ($blockedMessage)
            <div class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">{{ $blockedMessage }}</div>
        @elseif ($submitted || ($dispute && ! $dispute->status->acceptsSellerSubmission()))
            <div class="mt-6 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800">
                Recebemos sua defesa. Nossa equipe vai analisar e enviar as evidências à instituição do pagador.
                Você recebe o resultado por e-mail.
                @if ($duplicatesSkipped > 0)
                    <span class="mt-1 block text-xs">{{ $duplicatesSkipped }} arquivo(s) iguais aos que já tínhamos foram ignorados.</span>
                @endif
            </div>
        @else
            @if ($dispute->complement_request)
                <div class="mt-6 rounded-lg border border-primary-200 bg-primary-50 p-4 text-sm text-primary-700">
                    <p class="font-semibold">Nossa equipe pediu mais informações:</p>
                    <p class="mt-1 whitespace-pre-line">{{ $dispute->complement_request }}</p>
                </div>
            @endif

            <form class="mt-8 space-y-6" wire:submit.prevent="submit"
                x-data="{ uploadsInFlight: 0 }"
                x-on:livewire-upload-start="uploadsInFlight++"
                x-on:livewire-upload-finish="uploadsInFlight = Math.max(0, uploadsInFlight - 1)"
                x-on:livewire-upload-cancel="uploadsInFlight = Math.max(0, uploadsInFlight - 1)"
                x-on:livewire-upload-error="uploadsInFlight = Math.max(0, uploadsInFlight - 1)">

                <div>
                    <label for="contactName" class="text-sm font-medium text-slate-700">Seu nome (responsável pela conta)</label>
                    <input id="contactName" type="text" wire:model="contactName"
                        class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500" />
                    @error('contactName') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="statement" class="text-sm font-medium text-slate-700">O que aconteceu nesta venda?</label>
                    <p class="text-xs text-slate-500">Diga o que foi vendido, para quem, quando e como foi entregue. Se conversou com o comprador depois, conte também.</p>
                    <textarea id="statement" rows="6" wire:model="statement"
                        class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"></textarea>
                    @error('statement') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                @if ($askOrder || $askTracking)
                    <div class="grid gap-4 sm:grid-cols-2">
                        @if ($askOrder)
                            <div>
                                <label for="orderReference" class="text-sm font-medium text-slate-700">Número do pedido <span class="font-normal text-slate-400">(se tiver)</span></label>
                                <input id="orderReference" type="text" wire:model="orderReference" maxlength="100"
                                    class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900" />
                                @error('orderReference') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>
                        @endif
                        @if ($askTracking)
                            <div>
                                <label for="trackingCode" class="text-sm font-medium text-slate-700">Código de rastreio <span class="font-normal text-slate-400">(se houve envio)</span></label>
                                <input id="trackingCode" type="text" wire:model="trackingCode" maxlength="100"
                                    class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900" />
                                @error('trackingCode') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>
                        @endif
                    </div>
                @endif

                <div class="space-y-4">
                    <div>
                        <p class="text-sm font-medium text-slate-700">Comprovantes @if ($filesOptional)<span class="font-normal text-slate-400">(opcional - já temos o comprovante do Pix)</span>@endif</p>
                        <p class="text-xs text-slate-500">Nota fiscal, comprovante de entrega, conversa com o comprador... PDF, JPG, PNG ou WEBP, até 10 MB cada.</p>
                        @error('evidences') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    @foreach ($evidences as $index => $evidence)
                        <div class="rounded-xl border border-slate-200 p-4" wire:key="evidence-{{ $index }}">
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label class="text-xs font-medium text-slate-600">Tipo do documento</label>
                                    <select wire:model="evidences.{{ $index }}.kind"
                                        class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900">
                                        <option value="">Selecione</option>
                                        @foreach ($kinds as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @error("evidences.$index.kind") <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="text-xs font-medium text-slate-600">Arquivo</label>
                                    <input type="file" wire:model="evidences.{{ $index }}.file" accept=".pdf,.png,.jpg,.jpeg,.webp"
                                        class="mt-1 block w-full text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-primary-600" />
                                    @error("evidences.$index.file") <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                                </div>
                            </div>
                            <div class="mt-3">
                                <label class="text-xs font-medium text-slate-600">O que este documento mostra?</label>
                                <input type="text" wire:model="evidences.{{ $index }}.description" maxlength="450"
                                    placeholder="Ex.: nota fiscal da venda de 12/10 em nome do comprador"
                                    class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900" />
                                @error("evidences.$index.description") <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>
                            @if (count($evidences) > 1)
                                <button type="button" wire:click="removeEvidence({{ $index }})" class="mt-3 text-xs font-medium text-coral-600 hover:underline">Remover</button>
                            @endif
                        </div>
                    @endforeach

                    @if (count($evidences) < config('med.evidencias.max_arquivos'))
                        <button type="button" wire:click="addEvidence" class="text-sm font-semibold text-primary-600 hover:underline">+ Adicionar outro comprovante</button>
                    @endif
                </div>

                <label class="flex items-start gap-2 text-sm text-slate-700">
                    <input type="checkbox" wire:model="declaration" class="mt-0.5 rounded border-slate-300" />
                    <span>Declaro que as informações e os documentos enviados são verdadeiros e se referem a esta venda.</span>
                </label>
                @error('declaration') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

                <button type="submit" wire:loading.attr="disabled" wire:target="submit" x-bind:disabled="uploadsInFlight > 0"
                    class="inline-flex items-center justify-center rounded-[10px] bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-700 disabled:cursor-not-allowed disabled:opacity-60">
                    <span x-show="uploadsInFlight > 0" x-cloak>Aguarde o envio dos arquivos...</span>
                    <span x-show="uploadsInFlight === 0">
                        <span wire:loading.remove wire:target="submit">Enviar defesa</span>
                        <span wire:loading wire:target="submit">Enviando...</span>
                    </span>
                </button>
            </form>
        @endif
    </div>
</div>
