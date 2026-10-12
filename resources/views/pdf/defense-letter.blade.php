<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1c3154; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        h2 { font-size: 12px; margin: 18px 0 6px; border-bottom: 1px solid #c3d0e2; padding-bottom: 3px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { text-align: left; vertical-align: top; padding: 4px 6px; border: 1px solid #e3e9f2; }
        th { background: #f2f5f9; width: 32%; }
        .muted { color: #64748b; font-size: 9px; }
        .statement { white-space: pre-wrap; line-height: 1.5; }
    </style>
</head>
<body>
    <h1>Carta de defesa - contestação de Pix ({{ $dispute->type }})</h1>
    <p class="muted">Documento gerado em {{ $generatedAt->format('d/m/Y H:i') }} pela ÚnicoPag, instituição do recebedor.</p>

    <h2>Transação contestada</h2>
    <table>
        <tr><th>Identificador do Pix (endToEndId)</th><td>{{ $dispute->end_to_end_id }}</td></tr>
        <tr><th>Valor</th><td>{{ $dispute->formattedValue() }}</td></tr>
        <tr><th>Motivo informado pelo pagador</th><td>{{ $dispute->dispute_reason ?: 'Não informado' }}</td></tr>
        @if ($dispute->situation_type)
            <tr><th>Situação</th><td>{{ $dispute->situation_type }}</td></tr>
        @endif
        <tr><th>Aberta em</th><td>{{ $dispute->opened_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? '—' }}</td></tr>
    </table>

    <h2>Recebedor</h2>
    <table>
        <tr><th>Nome / razão social</th><td>{{ $account->name }}</td></tr>
        @if ($account->tax_id)
            <tr><th>CPF / CNPJ</th><td>{{ \App\Support\TaxId::format($account->tax_id) }}</td></tr>
        @endif
        @if ($dispute->seller_contact_name)
            <tr><th>Responsável que enviou a defesa</th><td>{{ $dispute->seller_contact_name }}</td></tr>
        @endif
        @if ($dispute->seller_submitted_at)
            <tr><th>Defesa enviada em</th><td>{{ $dispute->seller_submitted_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</td></tr>
        @endif
    </table>

    @if ($dispute->seller_statement)
        <h2>Relato do recebedor</h2>
        <div class="statement">{{ $dispute->seller_statement }}</div>
    @endif

    @if ($dispute->analyst_notes)
        <h2>Parecer da ÚnicoPag</h2>
        <div class="statement">{{ $dispute->analyst_notes }}</div>
    @endif

    <h2>Evidências anexadas</h2>
    @if ($evidences->isEmpty())
        <p>Nenhum documento anexado além desta carta.</p>
    @else
        <table>
            <tr><th style="width: 5%">#</th><th style="width: 35%">Tipo</th><th>Descrição</th></tr>
            @foreach ($evidences as $evidence)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $evidence->kind->label() }}</td>
                    <td>{{ $evidence->description }}</td>
                </tr>
            @endforeach
        </table>
    @endif
</body>
</html>
