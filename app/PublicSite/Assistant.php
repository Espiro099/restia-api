<?php

namespace App\PublicSite;

use App\Floor\Menu;
use App\Models\Branch;

/**
 * Asistente del sitio de La Mesa (SITE-3): responde dudas de la carta, las sucursales y los horarios,
 * recibe comentarios y ayuda a pedir una reserva. Para reservar solo PROPONE los datos con la herramienta
 * `proponer_reserva`; la página los muestra en el formulario y la persona decide si envía la solicitud
 * (SITE-2). No guarda la conversación: la página la manda completa en cada pregunta.
 */
final class Assistant
{
    public const REFUSAL = 'Con eso no te puedo ayudar. Si quieres, pregúntame por el menú, las sucursales o una reserva.';

    public const DRAFT_TEXT = 'Listo, revisa los datos de tu solicitud y toca «Enviar solicitud». La sucursal la confirmará contigo.';

    public const MAX_TURNS = 16;

    public const MAX_CHARS = 600;

    public const TOOL = 'proponer_reserva';

    /** Fijas: van primero para que el prefijo sea siempre igual y se guarde en caché. */
    private const INSTRUCTIONS = <<<'TXT'
Eres el asistente del sitio web de La Mesa, una cadena de restaurantes en México. Te escribe un cliente desde la página pública.

Ayudas con:
- La carta de cada sucursal: platillos, descripciones, precios (con IVA) y recomendaciones.
- Las sucursales: dirección, teléfono y horario.
- Dudas y comentarios sobre el restaurante. Si es una queja o un comentario, agradécelo y sugiere también contarlo en la sucursal o por teléfono; tú no lo guardas.
- Pedir una reserva.

Para reservar:
- Necesitas: sucursal, fecha, hora, número de personas, nombre y teléfono a 10 dígitos. Pregunta lo que falte, de uno o dos datos a la vez.
- Cuando tengas todo, usa la herramienta proponer_reserva. Eso NO crea la reserva: la página le muestra los datos a la persona para que los revise y toque «Enviar solicitud». La sucursal confirma después.
- Nunca digas que la reserva ya quedó hecha o confirmada. Usa fechas de los próximos 60 días y horas dentro del horario de la sucursal; si piden algo fuera de horario, dilo y ofrece otra hora.
- Para grupos de más de 12 personas, sugiere llamar a la sucursal.

Reglas:
- Español de México, amable y breve: dos a cuatro oraciones, sin títulos ni tablas.
- Usa solo los datos que se te dan. Si algo no aparece (alérgenos, promociones, estacionamiento), dilo y sugiere llamar a la sucursal. No inventes.
- No tomas pedidos para llevar ni cobras. Ante alergias, recomienda confirmarlo con la sucursal.
- Si la pregunta no tiene que ver con La Mesa, responde con amabilidad que solo ayudas con el restaurante.
TXT;

    public function __construct(private readonly AssistantModel $model) {}

    public function available(): bool
    {
        return $this->model->available();
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{reply: string, reservationDraft: ?array<string, mixed>}
     */
    public function answer(array $messages): array
    {
        $branches = Branch::query()->where('status', Branch::ACTIVE)->get()->sortBy('name')->values();
        $codes = $branches->filter(fn (Branch $b) => Site::present($b)['reservations'])->pluck('code')->values()->all();
        $now = now('America/Mexico_City');
        $days = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];
        $system = [
            ['type' => 'text', 'text' => self::INSTRUCTIONS],
            // Sucursales y cartas: cambian poco, así que van en caché.
            ['type' => 'text', 'text' => self::context($branches->all()), 'cacheControl' => ['type' => 'ephemeral']],
            // La fecha cambia cada día: después del bloque en caché, para no invalidarlo.
            ['type' => 'text', 'text' => 'Hoy es '.$days[$now->isoWeekday()].' '.$now->format('Y-m-d').', hora del centro de México '.$now->format('H:i').'.'],
        ];
        $tools = $codes ? [self::tool($codes)] : [];

        $reply = $this->model->reply($system, $messages, $tools);
        $draft = $reply->tool === self::TOOL && is_array($reply->toolInput) ? self::draft($reply->toolInput, $codes) : null;
        $text = $reply->text !== '' ? $reply->text : ($draft ? self::DRAFT_TEXT : self::REFUSAL);

        return ['reply' => $text, 'reservationDraft' => $draft];
    }

    /** @param list<string> $codes */
    private static function tool(array $codes): array
    {
        return [
            'name' => self::TOOL,
            'description' => 'Propone una solicitud de reserva con los datos que dio la persona. No la crea: la página se la muestra para que la revise y la envíe. Úsala solo cuando tengas todos los datos.',
            'strict' => true,
            'inputSchema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['branchCode', 'date', 'time', 'partySize', 'name', 'phone'],
                'properties' => [
                    'branchCode' => ['type' => 'string', 'enum' => $codes, 'description' => 'Código de la sucursal.'],
                    'date' => ['type' => 'string', 'description' => 'Fecha local AAAA-MM-DD.'],
                    'time' => ['type' => 'string', 'description' => 'Hora local HH:MM en formato de 24 horas.'],
                    'partySize' => ['type' => 'integer', 'description' => 'Número de personas, de 1 a 12.'],
                    'name' => ['type' => 'string', 'description' => 'Nombre de quien reserva.'],
                    'phone' => ['type' => 'string', 'description' => 'Teléfono a 10 dígitos.'],
                ],
            ],
        ];
    }

    /**
     * La propuesta tal como la verá la persona en el formulario. Solo se limpian tipos y formatos; si algo no
     * sirve se manda vacío para que lo complete ella. La validación real es la de la solicitud.
     *
     * @param  list<string>  $codes
     */
    public static function draft(array $in, array $codes): array
    {
        $str = fn ($v, int $max) => is_string($v) ? mb_substr(trim($v), 0, $max) : '';
        $date = $str($in['date'] ?? null, 10);
        $time = $str($in['time'] ?? null, 5);
        $party = is_int($in['partySize'] ?? null) ? $in['partySize'] : (int) ($in['partySize'] ?? 0);

        return [
            'branchCode' => in_array($in['branchCode'] ?? null, $codes, true) ? $in['branchCode'] : null,
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null,
            'time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : null,
            'partySize' => $party >= 1 && $party <= Site::MAX_PARTY ? $party : null,
            'name' => $str($in['name'] ?? null, 120) ?: null,
            'phone' => Phone::national($str($in['phone'] ?? null, 20)),
        ];
    }

    /** Sucursales, horarios y cartas en texto, en orden estable. @param list<Branch> $branches */
    public static function context(array $branches): string
    {
        $days = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];
        $lines = ['Sucursales de La Mesa:'];
        foreach ($branches as $b) {
            $p = Site::present($b);
            $lines[] = '';
            $lines[] = "# {$p['name']} (código {$p['code']})";
            if ($p['addressText']) {
                $lines[] = "Dirección: {$p['addressText']}";
            }
            if ($p['phone']) {
                $lines[] = "Teléfono: {$p['phone']}";
            }
            $lines[] = $p['hours']
                ? 'Horario de reservas: '.implode('; ', array_map(fn ($h) => ($days[$h['dayOfWeek']] ?? $h['dayOfWeek'])." de {$h['opensAt']} a {$h['closesAt']}", $p['hours']))
                : 'No acepta reservas en línea; se reserva por teléfono.';
            $menu = Menu::build($b);
            $lines[] = 'Carta:';
            foreach ($menu['products'] as $item) {
                $line = "- [{$item['category']}] {$item['name']}: $".number_format((float) $item['price']['amount'], 2);
                if (! empty($item['description'])) {
                    $line .= ". {$item['description']}";
                }
                if (! empty($item['recommended'])) {
                    $line .= ' (recomendación del día)';
                }
                if ($item['availability'] !== 'AVAILABLE') {
                    $line .= ' [agotado hoy]';
                }
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }
}
