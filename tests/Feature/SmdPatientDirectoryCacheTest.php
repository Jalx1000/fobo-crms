<?php

/*
|--------------------------------------------------------------------------
| Fichas de SMD en Redis, también las que NO existen (7 días)
|--------------------------------------------------------------------------
| 2026-10-13: la sync de Dropbox preguntaba cada 30 min por los mismos
| pacientes que SMD no encuentra (por id y por nombre): ~250 consultas/día.
| Ahora "no existe" se recuerda 7 días. Un error de SMD NO se recuerda.
*/

require_once __DIR__.'/../Support/SmdAuditHelpers.php';

use Illuminate\Support\Facades\Http;
use Webkul\Admin\Services\SmdPatientDirectory;

beforeEach(function () {
    $this->smd = auditBootFakes();
    $this->id = 'pac-'.uniqid();
    $this->name = 'Zq Paciente '.uniqid();
});

/** Directorio nuevo: sin la memoria del proceso, solo lo que quedó en Redis. */
function directory(): SmdPatientDirectory
{
    app()->forgetInstance(SmdPatientDirectory::class);

    return app(SmdPatientDirectory::class);
}

it('una ficha que no existe se recuerda: la próxima sync no vuelve a preguntar', function () {
    expect(directory()->patient($this->id))->toBeNull();
    expect(directory()->patient($this->id))->toBeNull();

    expect(auditSent('/patients/'.$this->id))->toHaveCount(1);
});

it('si SMD falla, no se recuerda: se vuelve a intentar', function () {
    $this->smd['patient'] = fn () => Http::response(['error' => 'down'], 500);

    directory()->patient($this->id);
    directory()->patient($this->id);

    expect(auditSent('/patients/'.$this->id))->toHaveCount(2);
});

it('una ficha encontrada se recuerda y se devuelve igual', function () {
    $this->smd['patient'] = fn ($id) => ['_id' => $id, 'fullName' => 'Ana Prueba'];

    directory()->patient($this->id);
    $ficha = directory()->patient($this->id);

    expect($ficha['fullName'])->toBe('Ana Prueba')
        ->and(auditSent('/patients/'.$this->id))->toHaveCount(1);
});

it('una búsqueda por nombre sin resultados se recuerda (no vuelve a buscar)', function () {
    directory()->resolve($this->id, $this->name);
    $antes = count(auditSent('/patients'));

    expect(directory()->resolve($this->id, $this->name))->toBeNull()
        ->and(count(auditSent('/patients')))->toBe($antes);
});

it('una búsqueda por nombre que falla no se recuerda', function () {
    // SMD no responde la búsqueda: se cuentan los intentos con el medidor.
    $this->smd['search'] = fn () => throw new RuntimeException('SMD caído');
    $budget = app(\Webkul\Admin\Services\Smd\ApiBudget::class);

    directory()->resolve(null, $this->name);
    $antes = $budget->today();
    directory()->resolve(null, $this->name);

    expect($budget->today())->toBeGreaterThan($antes);
});

it('los "no existe" duran 7 días por defecto', function () {
    expect(config('smd.patient_missing_cache_hours'))->toBe(168)
        ->and(config('smd.patient_cache_hours'))->toBe(168);
});
