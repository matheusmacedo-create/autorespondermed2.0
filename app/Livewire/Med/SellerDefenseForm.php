<?php

namespace App\Livewire\Med;

use App\Actions\Med\SubmitSellerDefenseAction;
use App\Enums\DisputeEventType;
use App\Enums\EvidenceKind;
use App\Models\Dispute;
use App\Services\DisputeEventRecorder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Formulário público de defesa do MED, autenticado pelo token do link - o
 * mesmo modelo do onboarding por link do KYC.
 */
#[Layout('layouts.public')]
class SellerDefenseForm extends Component
{
    use WithFileUploads;

    public string $token = '';

    public ?Dispute $dispute = null;

    public ?string $blockedMessage = null;

    public bool $submitted = false;

    public string $contactName = '';

    public string $statement = '';

    public bool $declaration = false;

    /**
     * @var array<int, array{kind: string, description: string, file: mixed}>
     */
    public array $evidences = [];

    public function mount(string $token, DisputeEventRecorder $events): void
    {
        $this->token = $token;
        $this->dispute = Dispute::query()->with('wooviAccount')->where('token', $token)->first();

        if (! $this->dispute) {
            $this->blockedMessage = 'Link inválido. Confira o endereço recebido ou fale com o atendimento da ÚnicoPag.';

            return;
        }

        if (! $this->dispute->seller_opened_at) {
            $this->dispute->forceFill(['seller_opened_at' => now()])->save();
            $events->record(DisputeEventType::SELLER_OPENED_LINK, $this->dispute, 'Seller abriu o link da defesa.', source: 'seller');
        }

        $this->blockedMessage = $this->blockReason();
        $this->contactName = (string) ($this->dispute->seller_contact_name ?? $this->dispute->wooviAccount->contact_name ?? '');

        if ($this->evidences === []) {
            $this->addEvidence();
        }
    }

    public function addEvidence(): void
    {
        if (count($this->evidences) >= (int) config('med.evidencias.max_arquivos')) {
            return;
        }

        $this->evidences[] = ['kind' => '', 'description' => '', 'file' => null];
    }

    public function removeEvidence(int $index): void
    {
        unset($this->evidences[$index]);
        $this->evidences = array_values($this->evidences);
    }

    /**
     * Valida o arquivo assim que o upload temporário termina, para o erro de
     * tamanho/formato aparecer na seleção e não só no envio.
     */
    public function updated(string $property): void
    {
        if (preg_match('/^evidences\.(\d+)\.file$/', $property)) {
            $this->validateOnly($property, $this->rules(), $this->messages(), $this->validationAttributes());
        }
    }

    public function submit(SubmitSellerDefenseAction $submit): void
    {
        if (! $this->dispute || ($this->blockedMessage = $this->blockReason($this->dispute->fresh()))) {
            return;
        }

        $this->evidences = array_values(array_filter(
            $this->evidences,
            fn (array $row): bool => $row['file'] !== null || $row['kind'] !== '' || trim($row['description']) !== '',
        ));

        $validated = $this->validate($this->rules(), $this->messages(), $this->validationAttributes());

        $isComplement = $this->dispute->seller_submitted_at !== null;

        if (! $isComplement && count($validated['evidences'] ?? []) === 0) {
            $this->addError('evidences', 'Anexe pelo menos um comprovante da venda ou do serviço.');
            $this->addEvidence();

            return;
        }

        try {
            $this->dispute = $submit->handle(
                $this->dispute,
                $validated['contactName'],
                $validated['statement'],
                $validated['evidences'] ?? [],
                $this->submissionMetadata(),
            );
        } catch (RuntimeException $e) {
            $this->blockedMessage = 'Este caso não aceita mais envio pelo link. Fale com o atendimento da ÚnicoPag.';

            return;
        }

        $this->submitted = true;
        $this->evidences = [];
        $this->statement = '';
        $this->declaration = false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $fileRules = [
            'required',
            'file',
            'max:'.config('med.evidencias.max_kb'),
            'mimes:'.implode(',', config('med.evidencias.mimes')),
        ];

        return [
            'contactName' => ['required', 'string', 'min:3', 'max:255'],
            'statement' => ['required', 'string', 'min:30', 'max:5000'],
            'declaration' => ['accepted'],
            'evidences' => ['array', 'max:'.config('med.evidencias.max_arquivos')],
            'evidences.*.kind' => ['required', Rule::in(array_keys(EvidenceKind::sellerOptions()))],
            'evidences.*.description' => ['required', 'string', 'min:5', 'max:450'],
            'evidences.*.file' => $fileRules,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'statement.min' => 'Conte com mais detalhes o que foi vendido e como foi entregue (mínimo de 30 caracteres).',
            'declaration.accepted' => 'Confirme que as informações são verdadeiras.',
            'evidences.*.file.max' => 'Cada arquivo pode ter no máximo 10 MB.',
            'evidences.*.file.mimes' => 'Formatos aceitos: PDF, JPG, PNG ou WEBP.',
            'evidences.*.file.required' => 'Escolha o arquivo.',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'contactName' => 'nome do responsável',
            'statement' => 'relato',
            'evidences.*.kind' => 'tipo do documento',
            'evidences.*.description' => 'descrição',
            'evidences.*.file' => 'arquivo',
        ];
    }

    private function blockReason(?Dispute $dispute = null): ?string
    {
        $dispute ??= $this->dispute;

        if ($dispute->status->isClosed()) {
            return "Este caso já foi encerrado ({$dispute->status->label()}).";
        }

        if (! $dispute->status->acceptsSellerSubmission()) {
            return null;
        }

        if ($dispute->isTokenExpired()) {
            return 'O prazo para enviar a defesa por este link terminou. Fale com o atendimento da ÚnicoPag.';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function submissionMetadata(): array
    {
        $request = request();

        return array_filter([
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'accept_language' => $request->header('Accept-Language'),
            'submitted_at' => now()->toIso8601String(),
        ]);
    }

    public function render()
    {
        return view('livewire.med.seller-defense-form', [
            'pageTitle' => 'Defesa de contestação Pix',
            'kinds' => EvidenceKind::sellerOptions(),
        ]);
    }
}
