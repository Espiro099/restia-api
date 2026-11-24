<?php

namespace App\PublicSite;

use App\Models\Branch;
use App\Models\Reservation;
use Illuminate\Database\Query\Builder;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use MongoDB\BSON\UTCDateTime;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

final class ReservationConfirmation
{
    private const CONSUMER = 'reservation-confirmation-email';

    public static function available(): bool
    {
        return config('restia.site.reservation_email') === true
            && config('mail.default') === 'resend'
            && filled(config('services.resend.key'))
            && filled(config('mail.from.address'));
    }

    public static function sendPending(int $limit = 4): int
    {
        if (! self::available()) {
            return 0;
        }

        $events = DB::connection('mongodb')->table('outbox_events')->where('type', 'reservation.confirmed')
            ->where('handled_by', '!=', self::CONSUMER)
            ->where('created_at', '>=', new UTCDateTime(now()->subHours(12)))
            ->where(fn (Builder $q) => $q->whereNull('confirmation_email_retry_at')
                ->orWhere('confirmation_email_retry_at', '<=', new UTCDateTime(now())))
            ->orderBy('created_at')->limit($limit)->get();

        $sent = 0;
        foreach ($events as $event) {
            $event = (array) $event;
            $row = DB::connection('mongodb')->table('outbox_events')->where('id', $event['id']);
            $reservation = Reservation::query()->find((string) $event['source_id']);
            $email = ((array) ($reservation?->customer_snapshot ?? []))['email'] ?? null;
            $branch = $reservation ? Branch::query()->find((string) $reservation->branch_id) : null;
            if ($branch && $email && $reservation->source === 'WEB'
                && $reservation->status === Reservation::CONFIRMED && $reservation->start_at->isFuture()) {
                try {
                    self::send($branch, $reservation, $email, (string) $event['id']);
                    $sent++;
                } catch (TransportExceptionInterface) {
                    Log::warning('No se pudo enviar un correo de confirmación de reserva.', ['reservationId' => $reservation->uuid]);
                    $row->update(['confirmation_email_retry_at' => new UTCDateTime(now()->addMinutes(5))]);

                    continue;
                }
            }
            $row->update(['$addToSet' => ['handled_by' => self::CONSUMER]]);
        }

        return $sent;
    }

    private static function send(Branch $branch, Reservation $reservation, string $email, string $eventId): void
    {
        $details = Site::presentRequest($branch, $reservation);
        $body = "Hola, {$details['name']}.\n\n"
            ."Tu reserva en {$details['branchName']} está confirmada.\n"
            ."Fecha {$details['date']} a las {$details['time']}\n"
            ."Personas {$details['partySize']}\n"
            ."Folio {$details['folio']}\n\n"
            ."Guarda tu folio para consultar tu reserva en nuestro sitio.\n\nTe esperamos en La Mesa.";

        Mail::raw($body, function (Message $message) use ($email, $eventId) {
            $message->to($email)->subject('Tu reserva en La Mesa está confirmada');
            $message->getSymfonyMessage()->getHeaders()->addTextHeader('Idempotency-Key', 'reservation-confirmation/'.$eventId);
        });
    }
}
