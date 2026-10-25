<!DOCTYPE html>
<html lang="pt-BR">
<body style="font-family: Arial, sans-serif; color: #1c3154; line-height: 1.5;">
    <p>Olá{{ $dispute->seller()['contact_name'] ? ', '.$dispute->seller()['contact_name'] : '' }}!</p>

    @if ($complementRequest)
        <p>Nossa equipe analisou a defesa da contestação de Pix abaixo e precisa de mais informações:</p>
        <blockquote style="border-left: 3px solid #dc3645; margin: 0; padding-left: 12px;">{!! nl2br(e($complementRequest)) !!}</blockquote>
    @else
        <p>O pagador de um Pix recebido pela conta <strong>{{ $dispute->seller()['name'] }}</strong> pediu a devolução pelo
            MED (Mecanismo Especial de Devolução do Banco Central).</p>
    @endif

    <ul>
        <li><strong>Valor:</strong> {{ $dispute->formattedValue() }}</li>
        <li><strong>Motivo informado:</strong> {{ $dispute->dispute_reason ?: 'não informado' }}</li>
        <li><strong>Identificador do Pix:</strong> {{ $dispute->end_to_end_id }}</li>
        @if ($deadline)
            <li><strong>Envie até:</strong> {{ $deadline }}</li>
        @endif
    </ul>

    <p>Se a venda foi legítima, envie seu relato e os comprovantes (nota fiscal, comprovante de entrega, conversa com o
        comprador) pelo link abaixo. Sem defesa, o valor pode ser devolvido ao pagador.</p>

    <p><a href="{{ $link }}" style="display: inline-block; background: #1c3154; color: #fff; padding: 10px 16px; border-radius: 8px; text-decoration: none;">Enviar minha defesa</a></p>

    <p style="font-size: 12px; color: #64748b;">Se o botão não abrir, copie este endereço no navegador: {{ $link }}</p>
    <p>Equipe ÚnicoPag</p>
</body>
</html>
