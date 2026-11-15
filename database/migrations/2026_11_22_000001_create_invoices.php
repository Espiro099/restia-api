<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/** Facturas, sus asignaciones a cuentas, eventos y contadores de folio (Decisiones_Fiscal, sección 9). */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        $schema = Schema::connection('mongodb');
        $schema->create('invoices', function (Blueprint $c) {
            $c->unique('uuid');
            $c->index(['branch_id' => 1, 'created_at' => -1]);
            $c->index(['branch_id' => 1, 'check_id' => 1]);
            // El proceso interno busca por estado y plazo de ocupación.
            $c->index(['status' => 1, 'lease_until' => 1]);
        });
        $schema->create('invoice_source_allocations', function (Blueprint $c) {
            // F-3: una factura activa por cuenta. La otra petición recibe INVOICE_CHECK_ALREADY_INVOICED.
            $c->unique(['branch_id' => 1, 'check_id' => 1], 'una_factura_activa_por_cuenta', options: ['partialFilterExpression' => ['active' => true]]);
            $c->index('invoice_id');
        });
        $schema->create('invoice_events', function (Blueprint $c) {
            $c->index(['invoice_id' => 1, 'created_at' => 1]);
        });
        $schema->create('invoice_counters', function (Blueprint $c) {
            $c->unique(['issuer_id', 'series']);
        });
        $schema->create('simulated_pac_documents', function (Blueprint $c) {
            $c->unique('reference');
        });
    }

    public function down(): void
    {
        foreach (['invoices', 'invoice_source_allocations', 'invoice_events', 'invoice_counters', 'simulated_pac_documents'] as $collection) {
            Schema::connection('mongodb')->dropIfExists($collection);
        }
    }
};
