<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Membership;
use App\Models\User;
use App\Support\Audit;
use App\Support\Mongo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Adopta sucursales cargadas fuera de la API (por ejemplo, a mano en Atlas).
 *
 * La API busca cada sucursal por un UUID real (AuthorizeBranch); un `uuid` como "sucursal-001" deja la sucursal
 * inalcanzable. Aquí se le asigna uno nuevo, conservando su `_id`: las demás colecciones apuntan a `_id`
 * (branch_id), no al uuid, así que nada más cambia. Y como restia:install se niega si ya hay sucursales,
 * aquí también se puede crear el primer ADMIN, solo si todavía no hay ningún usuario.
 *
 * La contraseña se pide sin eco o se lee de RESTIA_ADMIN_PASSWORD, igual que en restia:install.
 */
class AdoptBranches extends Command
{
    protected $signature = 'restia:adopt-branches
        {--dry-run : Solo muestra lo que cambiaría}
        {--admin-email= : Correo del primer ADMIN (solo si no hay usuarios)}
        {--admin-name= : Nombre del primer ADMIN}';

    protected $description = 'Da UUID reales a sucursales cargadas fuera de la API y, si no hay usuarios, crea el primer ADMIN';

    public function handle(): int
    {
        $branches = Branch::query()->orderBy('code')->get();
        if ($branches->isEmpty()) {
            $this->error('No hay sucursales. Para una base vacía usa restia:install.');

            return self::FAILURE;
        }

        $toFix = $branches->filter(fn (Branch $b) => ! is_string($b->uuid) || ! Str::isUuid($b->uuid) || $b->uuid !== strtolower($b->uuid));
        $email = $this->option('admin-email');
        if ($email !== null && User::query()->exists()) {
            $this->error('Ya hay usuarios: el ADMIN se da de alta por la API (POST /branches/{id}/members).');

            return self::FAILURE;
        }

        $this->table(['Código', 'Nombre', 'uuid actual', 'Acción'], $branches->map(fn (Branch $b) => [
            $b->code, $b->name, (string) $b->uuid, $toFix->contains($b) ? 'nuevo UUID' : 'sin cambios',
        ])->all());

        if ($toFix->isEmpty() && $email === null) {
            $this->info('Todas las sucursales ya tienen un UUID válido. Nada que hacer.');

            return self::SUCCESS;
        }
        if ($email !== null) {
            $this->line("Se creará el ADMIN {$email} con acceso a las {$branches->count()} sucursales.");
        }
        if ($this->option('dry-run')) {
            $this->warn('Prueba en seco: no se escribió nada.');

            return self::SUCCESS;
        }

        $admin = null;
        if ($email !== null) {
            $admin = $this->adminInput($email);
            if ($admin === null) {
                return self::FAILURE;
            }
        }

        $changes = Mongo::transaction(function () use ($branches, $toFix, $admin) {
            $changes = [];
            foreach ($toFix as $branch) {
                $old = (string) $branch->uuid;
                $new = Str::isUuid($old) ? strtolower($old) : (string) Str::uuid();
                // Sube `version`: un cliente con el ETag viejo debe volver a leer la sucursal.
                if (! $branch->updateIfVersion((int) ($branch->version ?? 1), ['uuid' => $new])) {
                    throw new \RuntimeException("La sucursal {$branch->code} cambió mientras se adoptaba; vuelve a correr el comando.");
                }
                Audit::record('branch.uuid_adopted', 'branch', $branch->getKey(), $branch->getKey(), actorType: 'SYSTEM', changes: ['uuid' => ['from' => $old, 'to' => $new]]);
                $changes[] = [$branch->code, $old, $branch->uuid];
            }
            if ($admin !== null) {
                $user = User::query()->create([
                    'email' => trim($admin['email']),
                    'normalized_email' => User::normalizeEmail($admin['email']),
                    'name' => $admin['name'],
                    'password_hash' => Hash::make($admin['password']),
                    'status' => User::ACTIVE,
                ]);
                foreach ($branches as $branch) {
                    Membership::query()->create([
                        'user_id' => $user->getKey(),
                        'branch_id' => $branch->getKey(),
                        'role' => 'ADMIN',
                        'status' => Membership::ACTIVE,
                    ]);
                    Audit::record('restia.installed', 'branch', $branch->getKey(), $branch->getKey(), actorType: 'SYSTEM');
                }
            }

            return $changes;
        });

        foreach ($changes as [$code, $old, $new]) {
            $this->info("{$code}: {$old} → {$new}");
        }
        if ($admin !== null) {
            $this->info("ADMIN {$admin['email']} creado.");
        }

        return self::SUCCESS;
    }

    /** @return array{email: string, name: string, password: string}|null */
    private function adminInput(string $email): ?array
    {
        $input = [
            'email' => $email,
            'name' => $this->option('admin-name') ?? $this->ask('Nombre del administrador'),
            'password' => env('RESTIA_ADMIN_PASSWORD') ?: $this->secret('Contraseña (mínimo 12 caracteres)'),
        ];
        $v = Validator::make($input, [
            'email' => ['required', 'email', 'max:254'],
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'min:12', 'max:1024'],
        ]);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $message) {
                $this->error($message);
            }

            return null;
        }

        return $input;
    }
}
