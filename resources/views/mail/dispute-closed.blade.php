<!DOCTYPE html>
<html lang="pt-BR">
<body style="font-family: Arial, sans-serif; color: #1c3154; line-height: 1.5;">
    <p>Olá{{ $dispute->wooviAccount->contact_name ? ', '.$dispute->wooviAccount->contact_name : '' }}!</p>

    <p>A contestação de Pix de <strong>{{ $dispute->formattedValue() }}</strong> recebida pela conta
        <strong>{{ $dispute->wooviAccount->name }}</strong> foi encerrada.</p>

    @if ($dispute->status === \App\Enums\DisputeStatus::REJECTED)
        <p><strong>Resultado: a devolução foi recusada.</strong> O valor permanece na sua conta.</p>
    @elseif ($dispute->status === \App\Enums\DisputeStatus::ACCEPTED)
        <p><strong>Resultado: a devolução foi aceita.</strong> O valor foi devolvido ao pagador.</p>
    @else
        <p><strong>Resultado: {{ $dispute->status->label() }}.</strong></p>
    @endif

    <p style="font-size: 12px; color: #64748b;">Identificador do Pix: {{ $dispute->end_to_end_id }}</p>
    <p>Equipe ÚnicoPag</p>
</body>
</html>
