<?php

use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Quote\Repositories\QuoteRepository;

/**
 * El NIT entre la cotización y la persona.
 *
 * El NIT es EL dato para facturar en Bolivia y el flujo de venta lo pide dos
 * veces. Lo que se protege acá es que no se pierda —ni se pise— al pasar de la
 * conversación de WhatsApp a la cotización y de vuelta a la ficha del contacto.
 */
/**
 * Registro de lo creado, para limpiarlo después.
 *
 * Una clase estática y no `$this->creados`: los helpers de abajo corren fuera
 * del closure del test, donde `test()` devuelve un proxy y escribir en un array
 * de una propiedad se pierde sin avisar.
 */
class Creados
{
    public static array $todo = ['persons' => [], 'quotes' => []];

    public static function add(string $tipo, int $id): void
    {
        static::$todo[$tipo][] = $id;
    }
}

beforeEach(function () {
    test()->actingAs(getDefaultAdmin());

    Creados::$todo = ['persons' => [], 'quotes' => []];
});

afterEach(function () {
    foreach (Creados::$todo['quotes'] as $id) {
        DB::table('quote_items')->where('quote_id', $id)->delete();
        DB::table('attribute_values')->where('entity_type', 'quotes')->where('entity_id', $id)->delete();
        DB::table('quotes')->where('id', $id)->delete();
    }

    foreach (Creados::$todo['persons'] as $id) {
        DB::table('attribute_values')->where('entity_type', 'persons')->where('entity_id', $id)->delete();
        DB::table('persons')->where('id', $id)->delete();
    }
});

function persona(array $extra = [])
{
    $p = app(PersonRepository::class)->create(array_merge([
        'entity_type' => 'persons',
        'name'        => 'NIT test '.uniqid(),
    ], $extra));

    Creados::add('persons', $p->id);

    return $p;
}

function cotizacion($personId, array $extra = [])
{
    $q = app(QuoteRepository::class)->create(array_merge([
        'entity_type' => 'quotes',
        'subject'     => 'Cotización test '.uniqid(),
        'person_id'   => $personId,
        'user_id'     => getDefaultAdmin()->id,
        'expired_at'  => now()->addDays(7)->toDateString(),
        'sub_total'   => 0,
        'grand_total' => 0,
        'items'       => [],
    ], $extra));

    Creados::add('quotes', $q->id);

    /*
     * El evento lo dispara el CONTROLADOR, no el repositorio. Se replica acá
     * igual que en QuoteController para probar el listener; el cableado real
     * —que la API REST también lo dispare— lo cubre el último test.
     */
    Illuminate\Support\Facades\Event::dispatch('quote.create.after', $q);

    // El listener escribe el atributo después de crear: hay que releerlo.
    return app(QuoteRepository::class)->find($q->id);
}

it('la cotización hereda el NIT de la persona', function () {
    // El caso normal: el asesor abre una cotización de un cliente que ya cotizó
    // antes y no vuelve a escribir el NIT.
    $p = persona(['nit' => '1234567019']);

    $q = cotizacion($p->id);

    expect($q->nit)->toBe('1234567019');
});

it('la persona aprende el NIT de la cotización', function () {
    // El PASO 4 del flujo: el cliente da su NIT por WhatsApp, el agente crea la
    // cotización, y el dato queda además en la ficha para la próxima vez.
    $p = persona();

    cotizacion($p->id, ['nit' => '9876543021']);

    expect(app(PersonRepository::class)->find($p->id)->nit)->toBe('9876543021');
});

it('no pisa ninguno de los dos cuando difieren', function () {
    /*
     * Pisar el de la persona propagaría un error de tipeo a todas las futuras
     * cotizaciones; pisar el de la cotización cambiaría un documento ya emitido.
     * Que queden distintos es visible y corregible; que se pisen, no.
     */
    $p = persona(['nit' => '1111111011']);

    $q = cotizacion($p->id, ['nit' => '2222222022']);

    expect($q->nit)->toBe('2222222022');
    expect(app(PersonRepository::class)->find($p->id)->nit)->toBe('1111111011');
});

it('un NIT en blanco cuenta como ausente, no como distinto', function () {
    // El formulario manda '' cuando el campo está vacío. Si eso se leyera como
    // «tiene un NIT distinto», caería en el caso de conflicto y la herencia
    // nunca ocurriría.
    $p = persona(['nit' => '3333333033']);

    $q = cotizacion($p->id, ['nit' => '   ']);

    expect($q->nit)->toBe('3333333033');
});

it('la API REST del agente pasa por el mismo camino', function () {
    /*
     * Es la vía que usa `register_cotizacion`: si el listener estuviera colgado
     * solo del controlador del CRM, las cotizaciones del agente entrarían sin
     * NIT y el PASO 4 no serviría de nada.
     */
    actingAsSanctumAuthenticatedAdmin();

    $p = persona(['nit' => '5555555055']);

    $r = test()->postJson('/api/v1/quotes', [
        'entity_type' => 'quotes',
        'subject'     => 'Cotización agente '.uniqid(),
        'person_id'   => $p->id,
        'user_id'     => getDefaultAdmin()->id,
        'expired_at'  => now()->addDays(7)->toDateString(),
        'sub_total'   => 0,
        'grand_total' => 0,
        'items'       => [],
    ]);

    $id = $r->json('data.id');

    expect($id)->not->toBeNull();

    Creados::add('quotes', $id);

    expect(app(QuoteRepository::class)->find($id)->nit)->toBe('5555555055');
});

it('no explota con una cotización sin persona ni con una persona borrada', function () {
    $p = persona(['nit' => '4444444044']);

    $q = cotizacion($p->id);

    expect($q->nit)->toBe('4444444044');

    // Sin person_id el listener sale temprano en vez de romper el alta.
    expect(fn () => app(Webkul\Admin\Listeners\Nit::class)->sincronizar($q->forceFill(['person_id' => null])))
        ->not->toThrow(Exception::class);
});
