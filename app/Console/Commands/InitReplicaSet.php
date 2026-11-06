<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use MongoDB\Driver\Command as MongoCommand;
use MongoDB\Driver\Exception\Exception as MongoException;
use MongoDB\Driver\Manager;

/** Inicia el replica set local de un nodo. Solo para desarrollo: en staging y producción lo hace la infraestructura. */
class InitReplicaSet extends Command
{
    protected $signature = 'restia:replica-init {--host=127.0.0.1} {--port=27018} {--name=rs-restia}';

    protected $description = 'Inicia el replica set local de MongoDB, si aún no existe';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('No se ejecuta en producción.');

            return self::FAILURE;
        }
        $host = $this->option('host').':'.$this->option('port');
        // Conexión directa: antes de iniciarlo, el nodo no responde como miembro de un replica set.
        $manager = new Manager("mongodb://$host/?directConnection=true&serverSelectionTimeoutMS=5000");

        try {
            $status = $manager->executeCommand('admin', new MongoCommand(['replSetGetStatus' => 1]))->toArray()[0];
            $this->info("El replica set {$status->set} ya está iniciado.");

            return self::SUCCESS;
        } catch (MongoException $e) {
            // NotYetInitialized (94): se inicia abajo. Cualquier otro error se muestra.
            if ($e->getCode() !== 94) {
                $this->error('MongoDB no responde en '.$host.': '.$e->getMessage());

                return self::FAILURE;
            }
        }

        $manager->executeCommand('admin', new MongoCommand(['replSetInitiate' => [
            '_id' => $this->option('name'),
            'members' => [['_id' => 0, 'host' => $host]],
        ]]));

        for ($i = 0; $i < 30; $i++) {
            $hello = $manager->executeCommand('admin', new MongoCommand(['hello' => 1]))->toArray()[0];
            if ($hello->isWritablePrimary ?? false) {
                $this->info('Replica set '.$this->option('name').' iniciado en '.$host.'.');

                return self::SUCCESS;
            }
            sleep(1);
        }
        $this->error('El nodo no llegó a primario en 30 segundos.');

        return self::FAILURE;
    }
}
