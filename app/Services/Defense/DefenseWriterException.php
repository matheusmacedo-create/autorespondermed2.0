<?php

namespace App\Services\Defense;

use RuntimeException;

/**
 * Sem rascunho: a mensagem vai para a linha do tempo e para o analista, então
 * precisa ser legível e nunca carregar o conteúdo do caso.
 */
class DefenseWriterException extends RuntimeException {}
