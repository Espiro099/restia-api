<?php

namespace App\Console\Commands;

use App\Cash\Cash;
use App\Identity\MobileAuth;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashShift;
use App\Models\Membership;
use App\Models\Printer;
use App\Models\PrintStation;
use App\Models\User;
use App\Printing\PrintStations;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;

/**
 * Deja una sucursal de prueba lista para la app Android: una caja con turno abierto, una ruta para la
 * precuenta, el recibo y el reporte de caja, y un código de alta para el dispositivo. Mientras no exista
 * el login Web, es la única forma de obtener ese código. Se puede repetir: solo crea lo que falta.
 */
class DemoMobile extends Command
{
    protected $signature = 'restia:demo-mobile
        {--branch= : Código de la sucursal; por omisión, la única que exista}
        {--opening=500.00 : Fondo inicial del turno, si hay que abrirlo}';

    protected $description = 'Prepara caja, ruta de impresión y código de alta para probar la app Android';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('restia:demo-mobile no corre en producción.');

            return self::FAILURE;
        }
        $branch = DemoOrder::branch($this->option('branch'));
        if (! $branch) {
            $this->error('Indica la sucursal con --branch; ejecuta antes restia:install y restia:demo.');

            return self::FAILURE;
        }
        $admin = $this->admin($branch);
        if (! $admin) {
            $this->error('La sucursal no tiene un ADMIN activo; ejecuta antes restia:install.');

            return self::FAILURE;
        }
        $opening = (string) $this->option('opening');
        if (! preg_match('/^\d{1,12}(\.\d{1,2})?$/', $opening)) {
            $this->error('--opening debe ser un importe, por ejemplo 500.00.');

            return self::FAILURE;
        }

        $register = $this->register($branch, $admin);
        $this->line('Caja: '.$register->name.' ('.$this->shift($branch, $admin, $register, $opening).')');
        $this->line('Impresión de caja: '.$this->printRoute($branch, $admin));

        $code = MobileAuth::createEnrollmentCode($branch, $admin);
        $this->newLine();
        $this->warn('Código de alta del dispositivo (vence '.$code['expiresAt']->toDateTimeString().' UTC, sirve una vez):');
        $this->line($code['code']);

        return self::SUCCESS;
    }

    private function register(Branch $branch, User $admin): CashRegister
    {
        return CashRegister::query()->forBranch($branch)->where('status', CashRegister::ACTIVE)->orderBy('code')->first()
            ?? Cash::createRegister($branch, $admin, ['code' => 'CAJA-1', 'name' => 'Caja principal']);
    }

    private function shift(Branch $branch, User $admin, CashRegister $register, string $opening): string
    {
        $active = CashShift::query()->where('cash_register_id', new ObjectId($register->getKey()))->where('active', true)->first();
        if ($active) {
            return 'turno '.$active->status.' desde antes';
        }
        Cash::open($branch, $admin, (string) Str::uuid(), [
            'cashRegisterId' => $register->uuid, 'openingAmount' => ['amount' => $opening],
            // Si un turno anterior dejó otro fondo, el motivo queda en la auditoría.
            'openingDifferenceReason' => 'Turno de prueba abierto por restia:demo-mobile',
        ], fn (CashShift $s) => $s);

        return 'turno abierto con $'.$opening;
    }

    private function printRoute(Branch $branch, User $admin): string
    {
        $covered = PrintStation::query()->forBranch($branch)->where('status', PrintStation::ACTIVE)->get()
            ->contains(fn (PrintStation $r) => in_array('PRE_CHECK', $r->job_types ?? [], true));
        if ($covered) {
            return 'ya había una ruta para la precuenta';
        }
        $printer = Printer::query()->forBranch($branch)->where('status', Printer::ACTIVE)->orderBy('code')->first();
        if (! $printer) {
            return 'sin impresoras; ejecuta restia:demo-printing y vuelve a correr este comando';
        }
        PrintStations::create($branch, $admin, [
            'code' => 'CAJA', 'name' => 'Caja', 'jobTypes' => ['PRE_CHECK', 'PAYMENT_RECEIPT', 'CASH_SHIFT_REPORT'], 'printerId' => $printer->uuid,
        ]);

        return 'ruta CAJA en '.$printer->code;
    }

    private function admin(Branch $branch): ?User
    {
        $membership = Membership::query()->where('branch_id', new ObjectId($branch->getKey()))->where('role', 'ADMIN')
            ->where('status', Membership::ACTIVE)->first();

        return $membership ? User::query()->where('_id', new ObjectId((string) $membership->user_id))->where('status', User::ACTIVE)->first() : null;
    }
}
