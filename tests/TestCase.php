<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // La suite corre con el .env de PRODUCCIÓN (credenciales reales de
        // WhatsApp, SMD, Kommo, Dropbox…). Un request saliente que el test no
        // simuló falla acá en vez de llegar a un servicio real. Solo las pruebas
        // live (tests/Live) lo liberan a propósito.
        Http::preventStrayRequests();
    }
}
