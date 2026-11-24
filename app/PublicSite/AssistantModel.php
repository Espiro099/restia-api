<?php

namespace App\PublicSite;

/**
 * El modelo que responde en el asistente del sitio de La Mesa (SITE-3). Una interfaz para que las pruebas
 * usen uno falso y nunca llamen a la API de Claude.
 */
interface AssistantModel
{
    /** ¿Hay credencial configurada? Sin ella el asistente no se ofrece. */
    public function available(): bool;

    /**
     * Responde al último mensaje de la persona. Si el modelo usa una de `$tools`, su entrada viene en
     * `AssistantReply::$toolInput` para que la página la muestre; aquí no se ejecuta nada.
     *
     * @param  list<array<string, mixed>>  $system
     * @param  list<array{role: string, content: string}>  $messages
     * @param  list<array<string, mixed>>  $tools
     *
     * @throws AssistantUnavailable si la API no responde o rechaza la petición
     */
    public function reply(array $system, array $messages, array $tools = []): AssistantReply;
}
