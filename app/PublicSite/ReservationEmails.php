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

final class ReservationEmails
{
    private const CONSUMER = 'reservation-confirmation-email';

    private const RECEIVED = 'reservation.created';

    private const CONFIRMED = 'reservation.confirmed';

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

        $events = DB::connection('mongodb')->table('outbox_events')
            ->where(fn (Builder $q) => $q->where('type', self::CONFIRMED)
                ->orWhere(fn (Builder $created) => $created->where('type', self::RECEIVED)->where('payload.sendReceipt', true)))
            ->where('handled_by', '!=', self::CONSUMER)
            ->where('created_at', '>=', new UTCDateTime(now()->subHours(12)))
            ->where(fn (Builder $q) => $q->whereNull('confirmation_email_retry_at')
                ->orWhere('confirmation_email_retry_at', '<=', new UTCDateTime(now())))
            ->orderBy('created_at')->orderBy('_id')->limit($limit)->get();

        $sent = 0;
        foreach ($events as $event) {
            $event = (array) $event;
            $row = DB::connection('mongodb')->table('outbox_events')->where('id', $event['id']);
            $reservation = Reservation::query()->find((string) $event['source_id']);
            $email = ((array) ($reservation?->customer_snapshot ?? []))['email'] ?? null;
            $branch = $reservation ? Branch::query()->find((string) $reservation->branch_id) : null;
            $received = $event['type'] === self::RECEIVED;
            $status = $reservation?->status;
            $eligible = $received
                ? in_array($status, [Reservation::PENDING, Reservation::CONFIRMED], true)
                : $status === Reservation::CONFIRMED;
            if ($branch && $email && $eligible && $reservation->source === 'WEB' && $reservation->start_at->isFuture()) {
                try {
                    self::send($branch, $reservation, $email, (string) $event['id'], $received);
                    $sent++;
                } catch (TransportExceptionInterface) {
                    Log::warning('No se pudo enviar un correo de reserva.', ['reservationId' => $reservation->uuid, 'event' => $event['type']]);
                    $row->update(['confirmation_email_retry_at' => new UTCDateTime(now()->addMinutes(5))]);

                    continue;
                }
            }
            $row->update(['$addToSet' => ['handled_by' => self::CONSUMER]]);
        }

        return $sent;
    }

    private static function send(Branch $branch, Reservation $reservation, string $email, string $eventId, bool $received): void
    {
        $details = Site::presentRequest($branch, $reservation);
        $subject = $received ? 'Recibimos tu solicitud en La Mesa' : 'Tu reserva en La Mesa está confirmada';
        $status = $received
            ? "Recibimos tu solicitud para {$details['branchName']}. Puedes consultar su estado con el folio. Te avisaremos cuando la sucursal confirme."
            : "Tu reserva en {$details['branchName']} está confirmada. Te esperamos en La Mesa.";
        $body = "Hola, {$details['name']}.\n\n"
            .$status."\n\n"
            ."Fecha {$details['date']} a las {$details['time']}\n"
            ."Personas {$details['partySize']}\n"
            ."Folio {$details['folio']}\n";

        Mail::raw($body, function (Message $message) use ($email, $eventId, $subject) {
            $message->to($email)->subject($subject);
            $message->getSymfonyMessage()->getHeaders()->addTextHeader('Idempotency-Key', 'reservation-confirmation/'.$eventId);
        });
    }
}
