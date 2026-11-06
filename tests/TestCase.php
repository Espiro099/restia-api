<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /** Las pruebas usan restia_test en el replica set local (phpunit.xml) y empiezan con la base vacía. */
    protected function freshDatabase(): void
    {
        $database = DB::connection('mongodb')->getDatabase();
        if ($database->getDatabaseName() !== 'restia_test') {
            $this->fail('Las pruebas solo borran la base restia_test, no '.$database->getDatabaseName());
        }
        $database->drop();
    }
}
