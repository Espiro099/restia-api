<?php

namespace App\Printing;

use Closure;

/**
 * Agente de impresión simulado: habla con la API igual que el agente real (config, latido, reclamo,
 * start y result), pero "imprime" el documento como texto. Sirve para probar a mano el ciclo de
 * impresión y las fallas de impresora (fase 4) sin hardware.
 *
 * Modos de falla, por trabajo:
 * - none: todo sale.
 * - not-sent: la impresora no recibe nada; el trabajo se reintenta solo hasta 3 veces.
 * - uncertain: la conexión se corta a media impresión; queda UNKNOWN y lo resuelve una persona.
 * - crash: el agente "se cae" después de start y no reporta; al vencer el plazo queda UNKNOWN.
 * - random: falla con la probabilidad de `failRate`, con uno de los tres modos anteriores.
 */
final class Simulator
{
    public const MODES = ['none', 'not-sent', 'uncertain', 'crash', 'random'];

    /** @var array<string, int> ancho de papel en mm → columnas */
    private const COLUMNS = [58 => 32, 80 => 48];

    /** @var array<string, int> impresora → columnas */
    private array $widths = [];

    /** @var array<string, string> impresora → nombre */
    private array $names = [];

    /** Segundos entre vueltas, según la configuración que manda la API. */
    public int $interval = 3;

    /**
     * @param  Closure(string, string, array): array{0: int, 1: array|null}  $send  método, ruta bajo /api/v1 y cuerpo → estado y JSON
     * @param  Closure(string): void  $out  dónde se escribe cada línea
     * @param  (Closure(): float)|null  $random  número entre 0 y 1; inyectable para las pruebas
     */
    public function __construct(
        private readonly Closure $send,
        private readonly Closure $out,
        private readonly string $mode = 'none',
        private readonly float $failRate = 0.3,
        private ?Closure $random = null,
    ) {
        $this->random ??= fn () => mt_rand() / mt_getrandmax();
    }

    /** Lee la configuración del agente. Devuelve false si la API rechaza el token o el agente. */
    public function boot(): bool
    {
        [$status, $body] = ($this->send)('GET', 'print-agent/config', []);
        if ($status === 200 && ! isset($body['data']['printers'])) {
            $this->say('La API respondió 200 pero sin la configuración en JSON. ¿Es la URL de la API (termina en /api/v1)?');

            return false;
        }
        if ($status !== 200) {
            $this->say("La API respondió $status al leer la configuración: ".rtrim($body['error']['message'] ?? 'sin detalle', '.').'.');

            return false;
        }
        $config = $body['data'];
        $this->interval = max(1, (int) $config['pollIntervalSeconds']);
        foreach ($config['printers'] as $p) {
            $this->widths[$p['id']] = self::COLUMNS[$p['paperWidth']] ?? 48;
            $this->names[$p['id']] = $p['name'];
        }
        $this->say("Agente \"{$config['agent']['name']}\" con ".count($config['printers']).' impresora(s); modo de falla: '.$this->mode.'.');

        return true;
    }

    /**
     * Una vuelta: latido, reclamo y cada trabajo. Devuelve los resultados, por ejemplo
     * ['PRINTED', 'NOT_SENT', 'CRASH'], para mostrarlos o revisarlos en las pruebas.
     *
     * @return list<string>
     */
    public function tick(): array
    {
        ($this->send)('POST', 'print-agent/heartbeat', [
            'agentVersion' => 'simulado-1',
            'printers' => array_map(fn ($id) => ['printerId' => $id, 'online' => true], array_keys($this->widths)),
        ]);
        [$status, $body] = ($this->send)('POST', 'print-agent/jobs/claim', ['limit' => 5]);
        if ($status !== 200) {
            $this->say("La API respondió $status al reclamar trabajos.");

            return [];
        }
        $results = [];
        foreach ($body['data'] as $claim) {
            $results[] = $this->handle($claim);
        }

        return $results;
    }

    /** @param array<string, mixed> $claim */
    private function handle(array $claim): string
    {
        $job = $claim['job'];
        $attempt = $claim['attemptId'];
        // Hasta que start responde bien, no se envía nada (PR-5).
        [$status, $body] = ($this->send)('POST', "print-agent/attempts/$attempt/start", []);
        if ($status !== 200) {
            $this->say("Trabajo {$job['id']}: start respondió $status (".($body['error']['code'] ?? '?').'); se descarta sin imprimir.');

            return 'DISCARDED';
        }
        $failure = $this->failure();
        $printer = $this->names[$claim['printerId']] ?? $claim['printerId'];
        $this->say("── {$job['type']} en \"$printer\", intento {$job['attempts']} ──");

        if ($failure === 'not-sent') {
            return $this->report($attempt, $job, 'NOT_SENT', 'SIM_OFFLINE', 'Falla simulada: la impresora no respondió.');
        }
        $lines = $this->render($claim['document'], $this->widths[$claim['printerId']] ?? 48);
        if ($failure === 'uncertain' || $failure === 'crash') {
            // Se corta a la mitad: parte del papel pudo salir.
            foreach (array_slice($lines, 0, max(1, intdiv(count($lines), 2))) as $line) {
                $this->say($line);
            }
            if ($failure === 'crash') {
                $this->say('   [falla simulada: el agente se cae sin reportar; al vencer el plazo el trabajo queda UNKNOWN]');

                return 'CRASH';
            }

            return $this->report($attempt, $job, 'UNCERTAIN', 'SIM_CONNECTION_LOST', 'Falla simulada: la conexión se cortó a media impresión.');
        }
        foreach ($lines as $line) {
            $this->say($line);
        }

        return $this->report($attempt, $job, 'PRINTED');
    }

    private function report(string $attempt, array $job, string $outcome, ?string $code = null, ?string $detail = null): string
    {
        $body = ['outcome' => $outcome] + array_filter(['errorCode' => $code, 'detail' => $detail]);
        [$status, $response] = ($this->send)('POST', "print-agent/attempts/$attempt/result", $body);
        $state = $status === 200 ? $response['data']['status'] : "error $status";
        $this->say("   resultado $outcome → el trabajo queda $state");

        return $outcome;
    }

    private function failure(): string
    {
        if ($this->mode !== 'random') {
            return $this->mode;
        }
        if (($this->random)() >= $this->failRate) {
            return 'none';
        }
        $modes = ['not-sent', 'uncertain', 'crash'];

        return $modes[min(2, (int) floor(($this->random)() * 3))];
    }

    /**
     * Documento neutro (PR-4) a texto de ancho fijo, como lo haría el agente real antes de ESC/POS.
     *
     * @param  list<array<string, mixed>>  $document
     * @return list<string>
     */
    public function render(array $document, int $width): array
    {
        $lines = [];
        foreach ($document as $line) {
            $text = (string) ($line['text'] ?? '');
            if (($line['size'] ?? 'NORMAL') === 'LARGE') {
                $text = mb_strtoupper($text);
            }
            $lines = [...$lines, ...match ($line['kind']) {
                'SEPARATOR' => [str_repeat('-', $width)],
                'CUT' => [str_repeat('✂ ', intdiv($width, 2))],
                'ROW' => [self::row($text, (string) ($line['right'] ?? ''), $width)],
                'QR' => [self::align('[QR] '.$text, 'CENTER', $width)],
                default => array_map(fn ($t) => self::align($t, $line['align'] ?? 'LEFT', $width), self::wrap($text, $width)),
            }];
        }

        return array_map(fn ($l) => '   |'.self::pad($l, $width).'|', $lines);
    }

    private static function row(string $left, string $right, int $width): string
    {
        $space = $width - mb_strlen($right) - 1;

        return self::pad(mb_substr($left, 0, max(0, $space)), $space).' '.$right;
    }

    private static function align(string $text, string $align, int $width): string
    {
        $gap = max(0, $width - mb_strlen($text));

        return match ($align) {
            'CENTER' => str_repeat(' ', intdiv($gap, 2)).$text,
            'RIGHT' => str_repeat(' ', $gap).$text,
            default => $text,
        };
    }

    /** @return list<string> */
    private static function wrap(string $text, int $width): array
    {
        if ($text === '') {
            return [''];
        }

        return explode("\n", wordwrap($text, $width, "\n", true));
    }

    private static function pad(string $text, int $width): string
    {
        return $text.str_repeat(' ', max(0, $width - mb_strlen($text)));
    }

    private function say(string $line): void
    {
        ($this->out)($line);
    }
}
