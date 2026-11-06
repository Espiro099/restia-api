<?php

namespace Tests\Feature;

use App\Exceptions\ServiceBusy;
use App\Models\Concerns\HasVersion;
use App\Models\Document;
use App\Support\Mongo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;
use MongoDB\Client;
use RuntimeException;
use Tests\TestCase;

class SampleDocument extends Document
{
    use HasVersion;

    protected $table = 'sample_documents';

    protected $hidden = ['secret'];

    protected $casts = ['branch_id' => \MongoDB\Laravel\Eloquent\Casts\ObjectId::class];
}

/** Requiere el replica set local (scripts/mongo-local.ps1): las transacciones no existen en un nodo suelto. */
class MongoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->freshDatabase();
    }

    /** Query builder: participa en la transacción. getCollection() no, porque no lleva la sesión. */
    private function counters()
    {
        return DB::connection('mongodb')->table('counters');
    }

    /** Escritura fuera de la transacción, desde otro cliente: provoca un choque real. */
    private function outsideWrite(): void
    {
        $client = new Client(config('database.connections.mongodb.dsn'));
        $client->selectCollection('restia_test', 'counters')->updateOne(['_id' => 'c'], ['$inc' => ['outside' => 1]]);
    }

    public function test_la_conexion_es_un_replica_set_con_primario(): void
    {
        $hello = DB::connection('mongodb')->getDatabase()->command(['hello' => 1])->toArray()[0];
        $this->assertTrue($hello['isWritablePrimary']);
        $this->assertNotEmpty($hello['setName']);
    }

    public function test_transaccion_confirma_todo_junto(): void
    {
        Mongo::transaction(function () {
            $this->counters()->insert(['_id' => 'a']);
            $this->counters()->insert(['_id' => 'b']);
        });
        $this->assertSame(2, $this->counters()->count());
    }

    public function test_una_falla_deshace_toda_la_transaccion(): void
    {
        try {
            Mongo::transaction(function () {
                $this->counters()->insert(['_id' => 'a']);
                throw new RuntimeException('falla de negocio');
            });
            $this->fail('debía lanzar');
        } catch (RuntimeException $e) {
            $this->assertSame('falla de negocio', $e->getMessage());
        }
        $this->assertSame(0, $this->counters()->count());
    }

    public function test_un_choque_se_reintenta_y_ve_lo_nuevo(): void
    {
        $this->counters()->insert(['_id' => 'c', 'inside' => 0, 'outside' => 0]);
        $attempts = 0;
        $seen = [];

        Mongo::transaction(function () use (&$attempts, &$seen) {
            $attempts++;
            $doc = $this->counters()->where('_id', 'c')->first();
            $seen[] = $doc->outside;
            if ($attempts === 1) {
                $this->outsideWrite();
            }
            $this->counters()->where('_id', 'c')->increment('inside');
        });

        $this->assertSame(2, $attempts);
        $this->assertSame([0, 1], $seen, 'el reintento debe leer la escritura que causó el choque');
        $doc = $this->counters()->where('_id', 'c')->first();
        $this->assertSame(1, $doc->inside);
        $this->assertSame(1, $doc->outside);
    }

    public function test_reintentos_agotados_lanzan_service_busy(): void
    {
        $this->counters()->insert(['_id' => 'c', 'inside' => 0, 'outside' => 0]);
        $attempts = 0;
        try {
            Mongo::transaction(function () use (&$attempts) {
                $attempts++;
                $this->counters()->where('_id', 'c')->first();
                $this->outsideWrite();
                $this->counters()->where('_id', 'c')->increment('inside');
            });
            $this->fail('debía lanzar ServiceBusy');
        } catch (ServiceBusy $e) {
            $this->assertTrue(Mongo::isTransient($e->getPrevious()));
        }
        $this->assertSame(Mongo::ATTEMPTS, $attempts);
        $this->assertSame(0, $this->counters()->where('_id', 'c')->first()->inside);
    }

    public function test_get_collection_no_participa_en_la_transaccion(): void
    {
        // Advertencia de la guía: escribir con getCollection() dentro de la transacción no se deshace.
        try {
            Mongo::transaction(function () {
                DB::connection('mongodb')->getCollection('counters')->insertOne(['_id' => 'fuera']);
                throw new RuntimeException('falla');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame(1, $this->counters()->count());
    }

    public function test_detecta_llave_duplicada(): void
    {
        $raw = DB::connection('mongodb')->getCollection('counters');
        $raw->createIndex(['code' => 1], ['unique' => true]);
        $raw->insertOne(['code' => 'X']);
        try {
            $raw->insertOne(['code' => 'X']);
            $this->fail('debía rechazar');
        } catch (\Throwable $e) {
            $this->assertTrue(Mongo::isDuplicateKey($e));
        }
        $this->assertFalse(Mongo::isDuplicateKey(new RuntimeException('otra')));
    }

    public function test_documento_tiene_uuid_version_y_oculta_id(): void
    {
        $doc = SampleDocument::create(['name' => 'a', 'secret' => 's']);
        $this->assertTrue(Str::isUuid($doc->uuid));
        $this->assertSame(1, $doc->version);
        $json = $doc->toArray();
        $this->assertArrayNotHasKey('_id', $json);
        $this->assertArrayNotHasKey('id', $json);
        $this->assertArrayNotHasKey('secret', $json);
        $this->assertSame('uuid', $doc->getRouteKeyName());
    }

    public function test_update_if_version_rechaza_version_vieja(): void
    {
        $doc = SampleDocument::create(['name' => 'a']);
        $this->assertTrue($doc->updateIfVersion(1, ['name' => 'b']));
        $this->assertSame(2, $doc->version);
        $this->assertFalse($doc->updateIfVersion(1, ['name' => 'c']));
        $this->assertSame('b', $doc->fresh()->name);
    }

    public function test_for_branch_consulta_por_object_id(): void
    {
        $branch = new ObjectId;
        SampleDocument::create(['branch_id' => (string) $branch]);
        SampleDocument::create(['branch_id' => (string) new ObjectId]);

        $raw = DB::connection('mongodb')->getCollection('sample_documents')->findOne();
        $this->assertInstanceOf(ObjectId::class, $raw['branch_id']);
        $this->assertSame(1, SampleDocument::forBranch($branch)->count());
        $this->assertSame(1, SampleDocument::forBranch((string) $branch)->count());
        // El error fácil de la guía: comparar contra un string no encuentra nada.
        $this->assertSame(0, SampleDocument::where('branch_id', (string) $branch)->count());
    }
}
