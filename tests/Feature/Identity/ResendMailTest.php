<?php

namespace Tests\Feature\Identity;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/** Correo por la API HTTP de Resend (MAIL_MAILER=resend). Render gratuito no deja salir por SMTP. */
class ResendMailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mail.default' => 'resend',
            'services.resend.key' => 're_prueba_123',
            'mail.from' => ['address' => 'no-responder@restia.test', 'name' => 'Restia'],
        ]);
    }

    public function test_manda_el_correo_a_la_api_de_resend(): void
    {
        Http::fake(['api.resend.com/*' => Http::response(['id' => 'abc'], 200)]);

        Mail::raw("Hola:\n\nTu enlace.", fn ($m) => $m->to('ana@ejemplo.mx', 'Ana')->subject('Restia: recupera tu contraseña'));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.resend.com/emails'
            && $r->method() === 'POST'
            && $r->hasHeader('Authorization', 'Bearer re_prueba_123')
            && $r['from'] === '"Restia" <no-responder@restia.test>'
            && $r['to'] === ['"Ana" <ana@ejemplo.mx>']
            && $r['subject'] === 'Restia: recupera tu contraseña'
            && $r['text'] === "Hola:\n\nTu enlace."
            && ! isset($r['html']));
    }

    public function test_un_rechazo_de_resend_es_un_error(): void
    {
        Http::fake(['api.resend.com/*' => Http::response(['message' => 'The restia.test domain is not verified.'], 403)]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('403');

        Mail::raw('x', fn ($m) => $m->to('ana@ejemplo.mx')->subject('x'));
    }

    public function test_sin_clave_no_intenta_mandar(): void
    {
        config(['services.resend.key' => null]);
        Http::fake();

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('RESEND_API_KEY');

        try {
            Mail::raw('x', fn ($m) => $m->to('ana@ejemplo.mx')->subject('x'));
        } finally {
            Http::assertNothingSent();
        }
    }
}
