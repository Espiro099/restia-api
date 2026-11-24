<?php

namespace App\PublicQr;

use App\Floor\Menu;
use App\Models\Branch;
use App\Models\RestaurantTable;

/**
 * Asistente del menú QR (QR-14): responde dudas del cliente sobre la carta y la sucursal. No toma pedidos ni
 * ve la cuenta, la sesión o datos de otras personas (QR-2), y no guarda la conversación: la página la manda
 * completa en cada mensaje y aquí solo se valida y se reenvía.
 */
final class Assistant
{
    /** Respuesta cuando el modelo no contesta algo (filtros de seguridad o texto vacío). */
    public const REFUSAL = 'Con eso no te puedo ayudar. Para cualquier otra cosa, pregúntale a tu mesero.';

    /**
     * Instrucciones fijas: van primero para que el prefijo sea siempre igual y se pueda guardar en caché.
     * Lo que cambia por sucursal (la carta) va después, en su propio bloque.
     */
    private const INSTRUCTIONS = <<<'TXT'
Eres el asistente de la página del menú de un restaurante. El cliente escaneó el código QR de su mesa y te escribe desde su teléfono.

Ayudas con:
- Qué hay en la carta, qué lleva cada platillo según su descripción, precios, qué está agotado y qué opciones o extras tiene.
- Recomendaciones dentro de la carta (por ejemplo, algo para compartir, una bebida sin alcohol o un postre).
- La dirección, el teléfono y el horario de la sucursal, si aparecen en los datos.

Reglas:
- Responde en español de México, en tono amable y breve: dos a cuatro oraciones, sin títulos ni tablas. Usa una lista corta solo si te piden varias opciones.
- Usa solo los datos de la carta que se te dan. Si algo no aparece (ingredientes exactos, calorías, alérgenos, promociones, platillos que no están), dilo y sugiere preguntarle al mesero. No inventes.
- Los precios ya incluyen IVA; escríbelos con el signo de pesos.
- No tomas pedidos, no ves la cuenta ni el estado de la mesa y no puedes llamar al mesero. Si te piden pedir, pagar o la cuenta, explica que eso se hace con el mesero.
- Ante alergias o restricciones médicas, recomienda confirmarlo siempre con el mesero o la cocina.
- Si la pregunta no tiene que ver con el restaurante, responde con amabilidad que solo ayudas con el menú y la visita.
TXT;

    public const MAX_TURNS = 12;

    public const MAX_CHARS = 500;

    public function __construct(private readonly AssistantModel $model) {}

    public function available(): bool
    {
        return $this->model->available();
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages  conversación que manda la página, terminando en el cliente
     */
    public function answer(Branch $branch, RestaurantTable $table, array $messages): string
    {
        $system = [
            ['type' => 'text', 'text' => self::INSTRUCTIONS],
            // La carta cambia poco: con la caché, las preguntas de la misma sucursal no la vuelven a cobrar completa.
            ['type' => 'text', 'text' => self::context($branch), 'cacheControl' => ['type' => 'ephemeral']],
        ];
        // La mesa va en la conversación y no en el prefijo, para no romper la caché de la sucursal.
        $first = $messages[0];
        $messages[0] = ['role' => 'user', 'content' => "(Mesa {$table->number})\n".$first['content']];

        return $this->model->reply($system, $messages);
    }

    /** Datos de la sucursal y la carta en texto, en orden estable (mismo texto para la misma versión del menú). */
    public static function context(Branch $branch): string
    {
        $menu = Menu::build($branch);
        $modifiers = [];
        foreach ($menu['modifiers'] as $m) {
            $opts = array_map(function ($o) {
                $delta = (float) ($o['priceDelta']['amount'] ?? 0);

                return $o['name'].($delta > 0 ? ' (+$'.number_format($delta, 2).')' : '');
            }, $m['options'] ?? []);
            $rule = $m['minSelections'] > 0 ? "elegir {$m['minSelections']}" : 'opcional';
            $modifiers[$m['id']] = "{$m['name']} ({$rule}): ".implode(', ', $opts);
        }

        $lines = ["Restaurante: {$branch->name}"];
        $address = $branch->address ? (array) json_decode(json_encode($branch->address), true) : null;
        if ($address && ! empty($address['line1'])) {
            $lines[] = 'Dirección: '.PublicQr::addressLine($address);
        }
        if ($branch->phone) {
            $lines[] = "Teléfono: {$branch->phone}";
        }
        $hours = json_decode(json_encode($branch->reservation_hours ?? []), true);
        if ($hours) {
            $days = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];
            $lines[] = 'Horario: '.implode('; ', array_map(fn ($h) => ($days[$h['dayOfWeek']] ?? $h['dayOfWeek'])." de {$h['opensAt']} a {$h['closesAt']}", $hours));
        }

        $lines[] = '';
        $lines[] = 'Carta (precio con IVA):';
        $category = null;
        foreach ($menu['products'] as $p) {
            if ($p['category'] !== $category) {
                $category = $p['category'];
                $lines[] = '';
                $lines[] = "## {$category}";
            }
            $price = '$'.number_format((float) $p['price']['amount'], 2);
            $line = "- {$p['name']}: {$price}";
            if (! empty($p['description'])) {
                $line .= ". {$p['description']}";
            }
            if ($p['availability'] !== 'AVAILABLE') {
                $line .= ' [AGOTADO hoy]';
            }
            foreach ($p['modifierIds'] as $id) {
                if (isset($modifiers[$id])) {
                    $line .= " | {$modifiers[$id]}";
                }
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }
}
