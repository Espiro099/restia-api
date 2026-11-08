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

/**
 * Crea la primera sucursal y su ADMIN (B-2). Solo funciona con la base vacía: las siguientes sucursales
 * se crean por la API. La contraseña se pide sin eco o se lee de RESTIA_ADMIN_PASSWORD, nunca de un argumento,
 * para que no quede en el historial del shell.
 */
class Install extends Command
{
    protected $signature = 'restia:install
        {--branch-code= : Código corto de la sucursal, por ejemplo CENTRO}
        {--branch-name= : Nombre visible de la sucursal}
        {--timezone=America/Mexico_City : Zona horaria IANA de la sucursal}
        {--email= : Correo del ADMIN}
        {--name= : Nombre del ADMIN}';

    protected $description = 'Crea la primera sucursal y su administrador';

    public function handle(): int
    {
        if (Branch::query()->exists()) {
            $this->error('Ya hay sucursales. Las siguientes se crean con POST /branches.');

            return self::FAILURE;
        }

        $input = [
            'branchCode' => $this->option('branch-code') ?? $this->ask('Código de la sucursal'),
            'branchName' => $this->option('branch-name') ?? $this->ask('Nombre de la sucursal'),
            'timezone' => $this->option('timezone'),
            'email' => $this->option('email') ?? $this->ask('Correo del administrador'),
            'name' => $this->option('name') ?? $this->ask('Nombre del administrador'),
            'password' => env('RESTIA_ADMIN_PASSWORD') ?: $this->secret('Contraseña (mínimo 12 caracteres)'),
        ];
        $v = Validator::make($input, [
            'branchCode' => ['required', 'string', 'regex:/^[A-Z0-9_-]{2,20}$/'],
            'branchName' => ['required', 'string', 'max:120'],
            'timezone' => ['required', 'timezone:all'],
            'email' => ['required', 'email', 'max:254'],
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'min:12', 'max:1024'],
        ]);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        [$branch, $user] = Mongo::transaction(function () use ($input) {
            $branch = Branch::query()->create([
                'code' => $input['branchCode'],
                'name' => $input['branchName'],
                'status' => Branch::ACTIVE,
                'timezone' => $input['timezone'],
                'currency' => 'MXN',
                'operations_epoch' => 0,
                'menu_version' => 0,
            ]);
            $user = User::query()->create([
                'email' => trim($input['email']),
                'normalized_email' => User::normalizeEmail($input['email']),
                'name' => $input['name'],
                'password_hash' => Hash::make($input['password']),
                'status' => User::ACTIVE,
            ]);
            Membership::query()->create([
                'user_id' => $user->getKey(),
                'branch_id' => $branch->getKey(),
                'role' => 'ADMIN',
                'status' => Membership::ACTIVE,
            ]);
            Audit::record('restia.installed', 'branch', $branch->getKey(), $branch->getKey(), actorType: 'SYSTEM');

            return [$branch, $user];
        });

        $this->info("Sucursal {$branch->code} ({$branch->uuid}) y ADMIN {$user->email} creados.");

        return self::SUCCESS;
    }
}
