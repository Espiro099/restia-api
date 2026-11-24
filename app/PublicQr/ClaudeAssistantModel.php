<?php

namespace App\PublicQr;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\RateLimitException;
use Illuminate\Support\Facades\Log;

/**
 * El asistente del menú con Claude, por el SDK oficial de Anthropic para PHP. Respuestas cortas de chat:
 * esfuerzo bajo y pocos tokens de salida. El modelo y la llave vienen de config/restia.php (assistant).
 */
final class ClaudeAssistantModel implements AssistantModel
{
    private ?Client $client = null;

    public function __construct(private readonly ?string $apiKey, private readonly string $model) {}

    public function available(): bool
    {
        return (string) $this->apiKey !== '';
    }

    public function reply(array $system, array $messages): string
    {
        if (! $this->available()) {
            throw new AssistantUnavailable('Sin llave de la API de Claude.');
        }
        $this->client ??= new Client(apiKey: $this->apiKey, requestOptions: ['timeout' => 30, 'maxRetries' => 2]);
        try {
            $message = $this->client->messages->create(
                maxTokens: 1024,
                messages: $messages,
                model: $this->model,
                system: $system,
                // Charla de servicio: respuestas breves; el esfuerzo bajo basta y responde más rápido.
                outputConfig: ['effort' => 'low'],
            );
        } catch (RateLimitException $e) {
            Log::warning('Asistente QR: límite de la API de Claude', ['type' => $e->type?->value]);
            throw new AssistantUnavailable('Límite de la API de Claude.', previous: $e);
        } catch (APIStatusException $e) {
            Log::warning('Asistente QR: error de la API de Claude', ['type' => $e->type?->value]);
            throw new AssistantUnavailable('Error de la API de Claude.', previous: $e);
        } catch (APIConnectionException $e) {
            Log::warning('Asistente QR: sin conexión con la API de Claude');
            throw new AssistantUnavailable('Sin conexión con la API de Claude.', previous: $e);
        }

        // Un rechazo de los filtros de seguridad llega como 200 con stop_reason "refusal": se contesta genérico.
        if ($message->stopReason === 'refusal') {
            return Assistant::REFUSAL;
        }
        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        return trim($text) !== '' ? trim($text) : Assistant::REFUSAL;
    }
}
