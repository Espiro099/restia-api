<?php

namespace App\Mail;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;
use Throwable;

/**
 * Correo por la API HTTP de Resend (https://resend.com/docs/api-reference/emails/send-email), sin
 * dependencias: el plan gratuito de Render no deja salir por SMTP. MAIL_MAILER=resend y RESEND_API_KEY.
 */
final class ResendTransport extends AbstractTransport
{
    private const URL = 'https://api.resend.com/emails';

    public function __construct(private readonly ?string $key)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        if (! $this->key) {
            throw new TransportException('Falta RESEND_API_KEY para mandar correo con Resend.');
        }

        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $list = fn (array $addresses) => array_map(fn (Address $a) => $a->toString(), $addresses);
        $payload = array_filter([
            'from' => $email->getFrom()[0]->toString(),
            'to' => $list($email->getTo()),
            'cc' => $list($email->getCc()),
            'bcc' => $list($email->getBcc()),
            'reply_to' => $list($email->getReplyTo()),
            'subject' => (string) $email->getSubject(),
            'text' => $email->getTextBody(),
            'html' => $email->getHtmlBody(),
        ], fn ($v) => $v !== null && $v !== []);

        try {
            $response = Http::withToken($this->key)->acceptJson()->timeout(15)->post(self::URL, $payload);
        } catch (Throwable $e) {
            throw new TransportException('No se pudo conectar con Resend: '.$e->getMessage(), 0, $e);
        }
        if ($response->failed()) {
            // Sin el cuerpo del correo: puede llevar enlaces con token.
            throw new TransportException('Resend respondió '.$response->status().': '.($response->json('message') ?? 'sin detalle'));
        }
    }

    public function __toString(): string
    {
        return 'resend+api://api.resend.com';
    }
}
