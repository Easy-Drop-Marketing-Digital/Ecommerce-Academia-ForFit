<?php

namespace App\Services\Payments;

use RuntimeException;

/** Erro do gateway com mensagem segura para exibir à cliente. */
class GatewayException extends RuntimeException {}
