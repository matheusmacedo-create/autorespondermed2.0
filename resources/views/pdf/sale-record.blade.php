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
        th { background: #f2f5f9; width: 34%; }
        .muted { color: #64748b; font-size: 9px; }
        .ok { color: #166534; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Registro da venda - Pix {{ $endToEndId }}</h1>
    <p class="muted">Reunido em {{ $generatedAt->format('d/m/Y H:i') }} pela ÚnicoPag a partir dos registros da Woovi (instituição do recebedor){{ $sale ? ' e da plataforma ÚnicoPag' : '' }}. Dados pessoais parcialmente mascarados.</p>

    <h2>Pagamento</h2>
    <table>
        @foreach ($payment as $label => $value)
            <tr><th>{{ $label }}</th><td>{{ $value }}</td></tr>
        @endforeach
        @foreach ($payer as $label => $value)
            <tr><th>Pagador - {{ $label }}</th><td>{{ $value }}</td></tr>
        @endforeach
    </table>

    @if ($charge)
        <h2>Cobrança que o pagamento quitou</h2>
        <p>O Pix pagou uma cobrança gerada pelo recebedor para esta venda - não foi uma transferência avulsa para a chave.</p>
        <table>
            @foreach ($charge as $label => $value)
                <tr><th>{{ $label }}</th><td>{{ $value }}</td></tr>
            @endforeach
            @foreach ($chargeInfo as $label => $value)
                <tr><th>{{ $label }}</th><td>{{ $value }}</td></tr>
            @endforeach
            @foreach ($customer as $label => $value)
                <tr><th>Cliente da cobrança - {{ $label }}</th><td>{{ $value }}</td></tr>
            @endforeach
        </table>
        @if ($samePerson === true)
            <p class="ok">O CPF/CNPJ de quem pagou é o mesmo do cliente cadastrado na cobrança.</p>
        @endif
    @endif

    @if ($sale)
        <h2>Pedido na plataforma ÚnicoPag</h2>
        <table>
            @foreach ($sale['details'] as $label => $value)
                <tr><th>{{ $label }}</th><td>{{ $value }}</td></tr>
            @endforeach
        </table>
        @if ($sale['items'])
            <table style="margin-top: 6px">
                <tr><th style="width: 60%">Item</th><th style="width: 15%">Qtd.</th><th>Valor unitário</th></tr>
                @foreach ($sale['items'] as $item)
                    <tr><td>{{ $item['name'] }}</td><td>{{ $item['quantity'] }}</td><td>{{ $item['amount'] }}</td></tr>
                @endforeach
            </table>
        @endif
        @if ($sale['fulfillment'])
            <h2>Entrega</h2>
            <table>
                @foreach ($sale['fulfillment'] as $label => $value)
                    <tr><th>{{ $label }}</th><td>{{ $value }}</td></tr>
                @endforeach
            </table>
        @endif
    @endif

    @if ($sellerDetails)
        <h2>Informado pelo recebedor</h2>
        <table>
            @foreach ($sellerDetails as $label => $value)
                <tr><th>{{ $label }}</th><td>{{ $value }}</td></tr>
            @endforeach
        </table>
    @endif

    @if ($refundsKnown)
        <h2>Devoluções desta cobrança</h2>
        @if ($refunds)
            <table>
                <tr><th>Valor</th><th>Situação</th><th>Data</th></tr>
                @foreach ($refunds as $refund)
                    <tr><td>{{ $refund['valor'] ?? '—' }}</td><td>{{ $refund['status'] ?? '—' }}</td><td>{{ $refund['em'] ?? '—' }}</td></tr>
                @endforeach
            </table>
        @else
            <p>Nenhuma devolução registrada na Woovi para esta cobrança.</p>
        @endif
    @endif
</body>
</html>
