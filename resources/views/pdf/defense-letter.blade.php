<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111827; line-height: 1.55; }
        h1 { font-size: 14px; margin: 0 0 14px; }
        h2 { font-size: 12px; margin: 22px 0 6px; border-bottom: 1px solid #d1d5db; padding-bottom: 3px; }
        p { margin: 0 0 10px; }
        .header p { margin: 0 0 4px; }
        .statement { white-space: pre-wrap; }
        table { width: 100%; border-collapse: collapse; font-size: 10px; }
        td, th { text-align: left; vertical-align: top; padding: 4px 6px; border: 1px solid #e5e7eb; }
        th { background: #f3f4f6; width: 32%; }
        ul.facts { margin: 0; padding-left: 16px; font-size: 10px; }
        .muted { color: #6b7280; font-size: 9px; }
        .annex { page-break-before: always; }
    </style>
</head>
<body>
    <h1>{{ strtoupper((string) $dispute->type) === 'MED' || blank($dispute->type) ? 'DEFESA – Mecanismo Especial de Devolução (MED)' : 'DEFESA – Contestação ('.$dispute->type.')' }}</h1>

    <div class="header">
        @if ($requester)
            <p><strong>Requerente:</strong> {{ $requester }}</p>
        @endif
        <p><strong>Data da Transação:</strong> {{ $paidAt ?? ($dispute->opened_at?->timezone('America/Sao_Paulo')->format('d/m/Y') ?? '—') }}</p>
        <p><strong>Valor:</strong> {{ $dispute->formattedValue() }}</p>
        @if ($products)
            <p><strong>Produto/Serviço:</strong> {{ $products }}</p>
        @endif
        <p><strong>Identificador do Pix (endToEndId):</strong> {{ $dispute->end_to_end_id }}</p>
    </div>

    <p style="margin-top: 16px"><strong>Prezados,</strong></p>

    @if ($defense)
        <div class="statement">{{ $defense }}</div>
    @elseif ($analystNotes)
        <div class="statement">{{ $analystNotes }}</div>
    @else
        <p>Após análise, verificamos que a transação contestada corresponde a uma venda registrada na plataforma, conforme os fatos conferidos em anexo.</p>
    @endif

    @if ($channels)
        <p style="margin-top: 10px">Reiteramos nossa disposição em atender o cliente por meio dos canais oficiais da empresa, adequados para tratativas relacionadas a prazos, trocas ou reembolsos:</p>
        <p>
            @foreach ($channels as $label => $value)
                {{ $label }}: {{ $value }}<br>
            @endforeach
        </p>
    @endif

    <p>Diante do exposto, solicitamos que o presente pedido de devolução seja indeferido, por não se enquadrar na finalidade do MED.</p>

    <p style="margin-top: 16px">Atenciosamente,</p>
    <p><em>{{ $signature }}</em></p>

    <div class="annex">
        <h1>Anexo – dados conferidos pela ÚnicoPag</h1>
        <p class="muted">Documento gerado em {{ $generatedAt->format('d/m/Y H:i') }} pela ÚnicoPag, instituição do recebedor.</p>

        <h2>Recebedor</h2>
        <table>
            <tr><th>Nome / razão social</th><td>{{ $accountName }}</td></tr>
            @if ($tradeName)
                <tr><th>Nome fantasia</th><td>{{ $tradeName }}</td></tr>
            @endif
            @if ($accountTaxId)
                <tr><th>CPF / CNPJ</th><td>{{ $accountTaxId }}</td></tr>
            @endif
            <tr><th>Motivo informado pelo pagador</th><td>{{ $dispute->dispute_reason ?: 'Não informado' }}</td></tr>
            @if ($dispute->situation_type)
                <tr><th>Situação</th><td>{{ $dispute->situation_type }}</td></tr>
            @endif
            <tr><th>MED aberto em</th><td>{{ $dispute->opened_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? '—' }}</td></tr>
            @if ($sellerContactName)
                <tr><th>Responsável que enviou a defesa</th><td>{{ $sellerContactName }}</td></tr>
            @endif
            @if ($sellerSubmittedAt)
                <tr><th>Defesa enviada em</th><td>{{ $sellerSubmittedAt }}</td></tr>
            @endif
        </table>

        @if ($facts)
            <h2>Fatos conferidos</h2>
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

        @if ($defense && $analystNotes)
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
    </div>
</body>
</html>
