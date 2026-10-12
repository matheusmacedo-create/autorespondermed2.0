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
        ul.facts { margin: 0; padding-left: 16px; line-height: 1.6; }
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
        <tr><th>Nome / razão social</th><td>{{ $accountName }}</td></tr>
        @if ($tradeName)
            <tr><th>Nome fantasia</th><td>{{ $tradeName }}</td></tr>
        @endif
        @if ($accountTaxId)
            <tr><th>CPF / CNPJ</th><td>{{ $accountTaxId }}</td></tr>
        @endif
        @if ($sellerContactName)
            <tr><th>Responsável que enviou a defesa</th><td>{{ $sellerContactName }}</td></tr>
        @endif
        @if ($sellerSubmittedAt)
            <tr><th>Defesa enviada em</th><td>{{ $sellerSubmittedAt }}</td></tr>
        @endif
    </table>

    @if ($facts)
        <h2>Fatos conferidos pela ÚnicoPag</h2>
        <ul class="facts">
            @foreach ($facts as $fact)
                <li>{{ $fact }}</li>
            @endforeach
        </ul>
    @endif

    @if ($statement)
        <h2>Relato do recebedor</h2>
        <div class="statement">{{ $statement }}</div>
    @endif

    @if ($sellerDetails)
        <table style="margin-top: 6px">
            @foreach ($sellerDetails as $label => $value)
                <tr><th>{{ $label }}</th><td>{{ $value }}</td></tr>
            @endforeach
        </table>
    @endif

    @if ($analystNotes)
        <h2>Parecer da ÚnicoPag</h2>
        <div class="statement">{{ $analystNotes }}</div>
    @endif

    <h2>Evidências anexadas</h2>
    @if (! $evidences)
        <p>Nenhum documento anexado além desta carta.</p>
    @else
        <table>
            <tr><th style="width: 5%">#</th><th style="width: 33%">Tipo</th><th>Descrição</th><th style="width: 18%">Origem</th></tr>
            @foreach ($evidences as $evidence)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $evidence['kind'] }}</td>
                    <td>{{ $evidence['description'] }}</td>
                    <td>{{ $evidence['origin'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif
</body>
</html>
