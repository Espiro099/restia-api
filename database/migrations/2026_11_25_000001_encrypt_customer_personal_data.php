<?php

use App\Models\Customer;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * Teléfono y correo de clientes cifrados en reposo (AES-256-CBC). Cifra los que aún estén en claro,
 * agrega el índice ciego `phone_index` y cambia el índice de búsqueda. Repetirla no cambia nada.
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        $table = DB::connection('mongodb')->table('customers');
        foreach ($table->get() as $row) {
            $row = (array) $row;
            // Valor en claro, venga cifrado o no; luego se escribe todo cifrado con su índice.
            $plain = [];
            foreach (Customer::ENCRYPTED as $column) {
                $value = $row[$column] ?? null;
                $plain[$column] = $value === null ? null : self::decrypted((string) $value);
            }
            DB::connection('mongodb')->table('customers')->where('_id', $row['_id'] ?? $row['id'])->update(Customer::encryptChanges($plain));
        }

        Schema::connection('mongodb')->table('customers', function (Blueprint $c) {
            $c->dropIndex(['phone']);
            $c->index('phone_index');
        });
    }

    public function down(): void
    {
        // No se descifra de vuelta: guardar datos personales en claro no es un estado al que volver.
        Schema::connection('mongodb')->table('customers', function (Blueprint $c) {
            $c->dropIndex(['phone_index']);
            $c->index('phone');
        });
    }

    private static function decrypted(string $value): string
    {
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return $value;
        }
    }
};
