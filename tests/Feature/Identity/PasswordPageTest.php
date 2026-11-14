<?php

namespace Tests\Feature\Identity;

use App\Identity\Passwords;
use App\Models\User;
use Tests\TestCase;

/** Página de contraseña (U-2): sin recursos externos, sin caché, sin Referer y sin indexar. */
class PasswordPageTest extends TestCase
{
    public function test_se_sirve_con_csp_estricto_y_un_nonce_por_peticion(): void
    {
        $first = $this->get('/contrasena')->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString('no-store', $first->headers->get('Cache-Control'));
        $csp = $first->headers->get('Content-Security-Policy');
        preg_match("/script-src 'nonce-([^']+)'/", $csp, $m);
        $this->assertNotEmpty($m[1]);
        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString("connect-src 'self'", $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp);
        // El script y el estilo llevan el nonce de esta respuesta; no queda ningún marcador sin reemplazar.
        $this->assertSame(2, substr_count($first->getContent(), 'nonce="'.$m[1].'"'));
        $this->assertStringNotContainsString('{{NONCE}}', $first->getContent());
        $this->assertStringContainsString('auth/password/reset', $first->getContent());
        // Cada respuesta usa otro nonce.
        $this->assertNotSame($csp, $this->get('/contrasena')->headers->get('Content-Security-Policy'));
        $this->assertNull($first->headers->get('Set-Cookie'));
    }

    public function test_el_enlace_de_alta_lleva_a_esta_pagina(): void
    {
        config(['restia.web.url' => 'https://restia.ejemplo.mx']);
        $user = User::query()->create(['email' => 'a@ejemplo.mx', 'normalized_email' => 'a@ejemplo.mx', 'name' => 'A', 'status' => 'INVITED']);
        $link = Passwords::issue($user, Passwords::SETUP);
        $this->assertMatchesRegularExpression('~^https://restia\.ejemplo\.mx/contrasena#[A-Za-z0-9_-]{43}$~', $link['url']);
    }
}
