<?php

namespace App\Services\Defense;

/**
 * Quem redige o rascunho da defesa de um MED. A implementação de produção chama o
 * Claude; os testes trocam por uma falsa (o SDK não passa pelo Http do Laravel).
 */
interface DefenseWriter
{
    /**
     * @param  array<string, mixed>  $case  o caso já reduzido ao que pode sair do sistema (sem PII do pagador)
     * @return array{draft: array{narrative: string, arguments: array<int, string>, weaknesses: array<int, string>, missing_evidence: array<int, string>, recommendation: string, recommendation_reason: string}, model: string, usage: array{input_tokens: int, output_tokens: int}}
     *
     * @throws DefenseWriterException quando não há rascunho (recusa, erro da API, resposta inválida)
     */
    public function write(array $case): array;

    public function isConfigured(): bool;
}
