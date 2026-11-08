<?php

namespace Tests\Feature;

use Tests\TestCase;

/** La matriz de config/permissions.php cubre todo permiso que el contrato menciona (U-3). */
class PermissionsTest extends TestCase
{
    public function test_cada_permiso_del_contrato_esta_en_la_matriz(): void
    {
        $yaml = file_get_contents(base_path('openapi/restia-api.yaml'));
        preg_match_all('/^\s+permission: (.+)$/m', $yaml, $m);
        $named = [];
        foreach ($m[1] as $line) {
            preg_match_all('/\b([a-z_]+\.[a-z_]+(?:\.[a-z_]+)?)\b/', $line, $p);
            array_push($named, ...$p[1]);
        }
        $known = config('permissions.known');
        $missing = array_values(array_diff(array_unique($named), $known));
        $this->assertSame([], $missing, 'Permisos del contrato que faltan en la matriz');
    }

    public function test_roles_y_reglas_basicas(): void
    {
        $roles = config('permissions.roles');
        $this->assertSame(['ADMIN', 'MANAGER', 'WAITER', 'KITCHEN'], array_keys($roles));
        $this->assertContains('users.sessions.revoke', $roles['ADMIN']);
        $this->assertNotContains('users.sessions.revoke', $roles['MANAGER']);
        $this->assertContains('order_units.prepare', $roles['KITCHEN']);
        $this->assertNotContains('order_units.deliver', $roles['KITCHEN']);
        $this->assertNotContains('order_units.void', $roles['WAITER']);
        foreach ($roles as $permissions) {
            $this->assertSame([], array_diff($permissions, config('permissions.known')));
        }
    }
}
