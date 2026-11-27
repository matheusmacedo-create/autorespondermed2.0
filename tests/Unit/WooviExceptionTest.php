<?php

namespace Tests\Unit;

use App\Services\Woovi\WooviException;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\TestCase;

class WooviExceptionTest extends TestCase
{
    private function refusal(int $status, string $body): WooviException
    {
        return WooviException::fromResponse(
            new Response(new Psr7Response($status, ['Content-Type' => 'application/json'], $body)),
            'a consulta da transação',
        );
    }

    /**
     * O mesmo JSON nas duas formas válidas: com o acento em UTF-8 e com o acento
     * escapado, em que a frase não aparece no corpo bruto.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string}
     */
    private function bothEncodings(array $payload): array
    {
        $plain = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $escaped = json_encode($payload);

        $this->assertNotSame($plain, $escaped);

        return [$plain, $escaped];
    }

    public function test_not_found_is_a_404_or_a_400_saying_so_in_english_or_portuguese(): void
    {
        $bodies = [
            // Como está na doc da Woovi.
            '{"error":"Transaction not found"}',
            // Como veio em produção (caso #508, 27/11/2026).
            ...$this->bothEncodings(['error' => 'Transação não encontrada']),
            ...$this->bothEncodings(['message' => 'Disputa não encontrada']),
            ...$this->bothEncodings(['error' => 'Comprovante não encontrado']),
            ...$this->bothEncodings(['error' => 'TRANSAÇÃO NÃO ENCONTRADA']),
            '{"error":"Transacao nao encontrada"}',
            // Formato que o fromResponse não lê: vale o corpo.
            '{"errors":[{"message":"Transação não encontrada"}]}',
        ];

        foreach ($bodies as $body) {
            $this->assertTrue($this->refusal(400, $body)->isNotFound(), $body);
        }

        $this->assertTrue($this->refusal(404, '')->isNotFound());
    }

    public function test_other_refusals_are_not_a_not_found(): void
    {
        $refusals = [
            [400, '{"error":"Você não pode enviar uma evidência após o prazo ter expirado"}'],
            [400, '{"error":"You cannot send a evidence for a accepted dispute"}'],
            [400, ''],
            [401, '{"error":"missing the required scope"}'],
            [403, '{"error":"Empresa não possui parceirias"}'],
            // A frase só vale com 400: erro do servidor da Woovi é falha, tenta de novo.
            [500, '{"error":"Transação não encontrada"}'],
        ];

        foreach ($refusals as [$status, $body]) {
            $this->assertFalse($this->refusal($status, $body)->isNotFound(), "{$status} {$body}");
        }

        // Sem resposta (timeout, conexão): não há status nem corpo.
        $this->assertFalse((new WooviException('Woovi não respondeu a consulta da transação: timeout'))->isNotFound());
    }

    public function test_message_carries_what_the_woovi_said_even_when_the_json_escapes_it(): void
    {
        [, $escaped] = $this->bothEncodings(['error' => 'Transação não encontrada']);
        $this->assertStringNotContainsString('não encontrada', $escaped);

        $e = $this->refusal(400, $escaped);

        $this->assertSame('Woovi recusou a consulta da transação (HTTP 400): Transação não encontrada', $e->getMessage());
        $this->assertSame('Transação não encontrada', $e->error);
        $this->assertSame(400, $e->status);
    }
}
