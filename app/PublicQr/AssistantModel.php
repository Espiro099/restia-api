<?php

namespace App\PublicQr;

/**
 * El modelo que responde en el asistente del menú QR (QR-14). Una interfaz para que las pruebas usen uno
 * falso y nunca llamen a la API de Claude.
 */
interface AssistantModel
{
    /** ¿Hay credencial configurada? Sin ella el asistente no se ofrece. */
    public function available(): bool;

    /**
     * Responde al último mensaje del cliente.
     *
     * @param  list<array{type: string, text: string, cacheControl?: array<string, string>}>  $system
     * @param  list<array{role: string, content: string}>  $messages
     *
     * @throws AssistantUnavailable si la API no responde o rechaza la petición
     */
    public function reply(array $system, array $messages): string;
}
