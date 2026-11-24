<?php

namespace App\PublicSite;

use App\Exceptions\DomainConflict;
use App\Identity\Branches;
use App\Models\Branch;
use App\Models\Reservation;
use App\Reservations\Availability;
use App\Reservations\Reservations;
use App\Support\Audit;
use App\Support\Idempotency;
use App\Support\Outbox;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Sitio público de La Mesa (Decisiones_Public_Site): sucursales con su punto en el mapa, horarios con lugar y
 * solicitudes de reserva. Una solicitud es una reserva PENDING con `source: WEB`: no aparta mesas (R-1),
 * vence sola si nadie la confirma (R-7) y el personal la confirma o cancela desde la app o el panel (SITE-2).
 */
final class Site
{
    /** Duración que se aparta al confirmar una solicitud: la misma que usa el personal por omisión. */
    public const DURATION_MINUTES = 90;

    /** Entre horarios ofrecidos. */
    public const STEP_MINUTES = 30;

    /** Antelación mínima: una solicitud para dentro de menos tiempo no le da tiempo al personal. */
    public const LEAD_MINUTES = 60;

    public const MAX_PARTY = 12;

    public const MAX_DAYS_AHEAD = 60;

    /** Solicitudes futuras activas (PENDING o CONFIRMED) por teléfono y sucursal. */
    public const ACTIVE_PER_PHONE = 2;

    /** Las solicitudes no tienen usuario: se registran a nombre de este actor fijo en la idempotencia. */
    private const WEB_ACTOR = '000000000000000000000000';

    /** @return list<array<string, mixed>> */
    public static function branches(): array
    {
        return Branch::query()->where('status', Branch::ACTIVE)->get()->sortBy('name')
            ->map(fn (Branch $b) => self::present($b))->values()->all();
    }

    public static function branch(string $code): Branch
    {
        $branch = Branch::query()->where('code', $code)->where('status', Branch::ACTIVE)->first();

        return $branch ?? throw new NotFoundHttpException;
    }

    /** @return array<string, mixed> */
    public static function present(Branch $b): array
    {
        $a = $b->address ? (array) json_decode(json_encode($b->address), true) : null;
        $address = $a && ! empty($a['line1']) ? [
            'line1' => $a['line1'], 'line2' => $a['line2'] ?? null, 'city' => $a['city'] ?? null,
            'state' => $a['state'] ?? null, 'postalCode' => $a['postalCode'] ?? null,
        ] : null;
        $location = Branches::location($b);
        $hours = array_values(array_map(fn ($h) => (array) $h, json_decode(json_encode($b->reservation_hours ?? []), true)));
        // "Cómo llegar": por coordenadas si las hay; si no, buscando la dirección. Sin llave de Google.
        $query = $location ? "{$location['lat']},{$location['lng']}" : ($address ? self::addressLine($address) : null);

        return [
            'code' => $b->code,
            'name' => $b->name,
            'phone' => $b->phone ?: null,
            'address' => $address,
            'addressText' => $address ? self::addressLine($address) : null,
            'location' => $location,
            'hours' => $hours,
            'reservations' => $hours !== [],
            'mapsUrl' => $query ? 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($query) : null,
        ];
    }

    /** "Av. Juárez 120, Centro, 44100 Guadalajara, Jalisco" */
    public static function addressLine(array $a): string
    {
        $place = trim(($a['postalCode'] ?? '').' '.($a['city'] ?? ''));

        return implode(', ', array_filter([$a['line1'], $a['line2'] ?? null, $place ?: null, $a['state'] ?? null]));
    }

    /**
     * Horarios con lugar para `partySize` en una fecha local de la sucursal: cada STEP_MINUTES dentro del
     * horario de reservas, con al menos LEAD_MINUTES de antelación y mesas que alcancen (R-2).
     *
     * @return list<string> horas locales "HH:MM"
     */
    public static function slots(Branch $branch, string $date, int $partySize): array
    {
        $tz = $branch->timezone ?: 'America/Mexico_City';
        $day = self::day($branch, $date);
        $earliest = now()->addMinutes(self::LEAD_MINUTES);
        $slots = [];
        foreach ($branch->reservation_hours ?? [] as $hours) {
            $hours = (array) $hours;
            if ((int) $hours['dayOfWeek'] !== $day->isoWeekday()) {
                continue;
            }
            $start = Carbon::parse("$date {$hours['opensAt']}", $tz);
            $closes = Carbon::parse("$date {$hours['closesAt']}", $tz);
            if ($closes->lessThanOrEqualTo($start)) {
                $closes->addDay();
            }
            for ($s = $start->copy(); $s->copy()->addMinutes(self::DURATION_MINUTES)->lessThanOrEqualTo($closes); $s->addMinutes(self::STEP_MINUTES)) {
                $utc = $s->copy()->utc();
                if ($utc->lessThan($earliest)) {
                    continue;
                }
                if (Availability::options($branch, $utc, $utc->copy()->addMinutes(self::DURATION_MINUTES), $partySize)) {
                    $slots[] = $s->format('H:i');
                }
            }
        }
        sort($slots);

        return array_values(array_unique($slots));
    }

    /**
     * Crea la solicitud (PENDING, source WEB). Vuelve a revisar el lugar al momento, porque la página pudo
     * mostrar los horarios hace rato.
     *
     * @param  array{date: string, time: string, partySize: int, name: string, phone: string, notes?: ?string, email?: ?string}  $in
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function request(Branch $branch, string $key, array $in): array
    {
        if (! self::present($branch)['reservations']) {
            throw new DomainConflict('RESERVATION_NOT_AVAILABLE');
        }
        $tz = $branch->timezone ?: 'America/Mexico_City';
        self::day($branch, $in['date']);
        $start = Carbon::parse("{$in['date']} {$in['time']}", $tz)->utc();
        $end = $start->copy()->addMinutes(self::DURATION_MINUTES);
        if (! in_array($in['time'], self::slots($branch, $in['date'], (int) $in['partySize']), true)) {
            throw new DomainConflict('RESERVATION_NOT_AVAILABLE');
        }
        $branchId = new ObjectId($branch->getKey());
        $phone = $in['phone'];
        $active = Reservation::query()->where('branch_id', $branchId)
            ->whereIn('status', [Reservation::PENDING, Reservation::CONFIRMED])->where('start_at', '>', now())
            ->where('customer_snapshot.phone', $phone)->count();
        if ($active >= self::ACTIVE_PER_PHONE) {
            throw new DomainConflict('RESERVATION_ACTIVE_LIMIT_EXCEEDED');
        }
        $actor = new ObjectId(self::WEB_ACTOR);

        return Idempotency::run($branchId, $key, $actor, 'createReservationRequest', Idempotency::hash('createReservationRequest', $in),
            function () use ($branch, $branchId, $in, $phone, $start, $end) {
                $r = Reservation::query()->create([
                    'branch_id' => $branchId, 'status' => Reservation::PENDING,
                    'start_at' => $start, 'end_at' => $end, 'party_size' => (int) $in['partySize'], 'customer_id' => null,
                    'customer_snapshot' => ['name' => trim($in['name']), 'phone' => $phone]
                        + (empty($in['email']) ? [] : ['email' => $in['email']]),
                    'notes' => isset($in['notes']) && trim((string) $in['notes']) !== '' ? trim($in['notes']) : null,
                    'source' => 'WEB', 'confirmation_code' => Reservations::code($branchId),
                    'planned_table_ids' => [], 'plan_conflict' => false, 'arrival_registered_at' => null, 'session_id' => null,
                    'cancel_reason' => null, 'personal_data_removed_at' => null, 'created_by' => null,
                ]);
                // Sin actor: el registro queda como SYSTEM, con el origen en los cambios.
                Audit::record('reservation.created', 'reservation', $r->getKey(), $branchId,
                    changes: ['source' => 'WEB', 'startAt' => $start->toIso8601ZuluString(), 'partySize' => (int) $in['partySize']]);
                Outbox::publish('reservation.created', 'reservation', $r->getKey(), $branchId, [
                    'reservationId' => $r->uuid,
                    'sendReceipt' => ! empty($in['email']),
                ]);

                return [201, self::presentRequest($branch, $r), $r->uuid];
            });
    }

    /** La solicitud por folio y teléfono; los dos deben coincidir. */
    public static function lookup(Branch $branch, string $folio, string $phone): array
    {
        $r = Reservation::query()->where('branch_id', new ObjectId($branch->getKey()))
            ->where('confirmation_code', strtoupper(trim($folio)))->first();
        $stored = $r ? (((array) ($r->customer_snapshot ?? []))['phone'] ?? null) : null;
        // Un folio con otro teléfono responde igual que uno que no existe.
        if ($stored === null || ! hash_equals($stored, $phone)) {
            throw new NotFoundHttpException;
        }

        return self::presentRequest($branch, $r);
    }

    /** Lo que ve la persona: sin mesas, notas internas ni quién la atendió. */
    public static function presentRequest(Branch $branch, Reservation $r): array
    {
        $tz = $branch->timezone ?: 'America/Mexico_City';
        $start = Carbon::instance($r->start_at)->setTimezone($tz);

        return [
            'folio' => $r->confirmation_code,
            'status' => $r->status,
            'branchCode' => $branch->code,
            'branchName' => $branch->name,
            'date' => $start->format('Y-m-d'),
            'time' => $start->format('H:i'),
            'partySize' => (int) $r->party_size,
            'name' => ((array) ($r->customer_snapshot ?? []))['name'] ?? '',
        ];
    }

    /** La fecha local, entre hoy y MAX_DAYS_AHEAD. */
    private static function day(Branch $branch, string $date): Carbon
    {
        $tz = $branch->timezone ?: 'America/Mexico_City';
        $day = Carbon::createFromFormat('!Y-m-d', $date, $tz);
        $today = now($tz)->startOfDay();
        if (! $day || $day->lessThan($today) || $day->greaterThan($today->copy()->addDays(self::MAX_DAYS_AHEAD))) {
            throw ValidationException::withMessages(['date' => 'Elige una fecha entre hoy y los próximos '.self::MAX_DAYS_AHEAD.' días.']);
        }

        return $day;
    }
}
