<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Webkul\Admin\Jobs\AvisarCotizacionNueva;
use Webkul\Admin\Listeners\CotizacionNueva;
use Webkul\Admin\Mail\CotizacionNueva as CorreoCotizacion;
use Webkul\Admin\Services\DestinatariosAviso;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Product\Models\ProductoResponsable;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Quote\Repositories\QuoteRepository;

/**
 * Correo al crearse una cotización: detalles de la cotización, el cliente y la
 * oportunidad, para el asesor y la copia fija.
 *
 * Contra la base del contenedor (decisión 2026-10-01) con transacciones: todo se
 * deshace. Mail, Http y la cola falseados; caché `array` para no tocar Redis.
 * Hay asesores reales en la base, así que se afirma que los de prueba estén (o
 * no) en la lista, nunca una lista exacta. Cobija: ningún asesor real la atiende.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    config(['cache.default' => 'array', 'mail.cotizacion_nueva' => true, 'mail.cotizacion_nueva_copia' => '']);

    Http::fake();
    Mail::fake();

    $this->producto = app(ProductRepository::class)->create([
        'entity_type' => 'products',
        'name'        => 'CQ test '.uniqid(),
        'sku'         => 'TEST-CQ-'.uniqid(),
        'price'       => 10,
        'quantity'    => 0,
    ]);
});

function cqAsesor(string $nombre): object
{
    $id = DB::table('users')->insertGetId([
        'name'       => $nombre,
        'email'      => 'cq'.uniqid().'@test.local',
        'password'   => bcrypt('x'),
        'role_id'    => DB::table('roles')->value('id'),
        'status'     => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return DB::table('users')->find($id);
}

function cqAtiende($producto, string $ciudad, object $asesor): void
{
    ProductoResponsable::create(['product_id' => $producto->id, 'ciudad' => $ciudad, 'user_id' => $asesor->id]);
}

function cqCotizacion($producto, ?object $asesor = null, ?int $leadId = null)
{
    return Event::fakeFor(function () use ($producto, $asesor, $leadId) {
        $person = app(PersonRepository::class)->create([
            'entity_type'     => 'persons',
            'name'            => 'Cliente CQ '.uniqid(),
            'emails'          => [['value' => 'cliente@test.local', 'label' => 'work']],
            'contact_numbers' => [['value' => '59177655508', 'label' => 'work']],
        ]);

        $quote = app(QuoteRepository::class)->create([
            'entity_type'   => 'quotes',
            'subject'       => 'Cotización CQ '.uniqid(),
            'person_id'     => $person->id,
            'user_id'       => $asesor?->id,
            'expired_at'    => now()->addDays(10)->toDateString(),
            'sub_total'     => 200,
            'grand_total'   => 200,
            'items'         => [[
                'product_id' => $producto->id,
                'sku'        => $producto->sku,
                'name'       => 'Tarjetas personales',
                'quantity'   => 100,
                'price'      => 2,
                'total'      => 200,
            ]],
        ]);

        if ($leadId) {
            $quote->leads()->syncWithoutDetaching([$leadId]);
        }

        return $quote->fresh();
    });
}

function cqEmails($equipo): array
{
    return collect($equipo)->pluck('email')->all();
}

it('avisa al asesor de la cotización', function () {
    $asesor = cqAsesor('Dueño');

    expect(cqEmails(app(DestinatariosAviso::class)->equipo($asesor->id, 'Cobija')))->toBe([$asesor->email]);
});

it('sin asesor avisa a quienes atienden la ciudad, no a los de Bolivia', function () {
    $local = cqAsesor('Local');
    $nacional = cqAsesor('Nacional');
    cqAtiende($this->producto, 'Cobija', $local);
    cqAtiende($this->producto, 'Bolivia', $nacional);

    expect(cqEmails(app(DestinatariosAviso::class)->equipo(null, 'Cobija')))
        ->toContain($local->email)->not->toContain($nacional->email);
});

it('sin asesor y sin nadie en la ciudad cae en los de Bolivia', function () {
    $nacional = cqAsesor('Nacional');
    cqAtiende($this->producto, 'Bolivia', $nacional);

    expect(cqEmails(app(DestinatariosAviso::class)->equipo(null, 'Cobija')))->toContain($nacional->email)
        ->and(cqEmails(app(DestinatariosAviso::class)->equipo(null, null)))->toContain($nacional->email);
});

// quotes.user_id es NOT NULL: toda cotización tiene asesor. Si está inactivo,
// el aviso cae en la ciudad o en Bolivia (los casos de arriba).
it('un asesor inactivo no recibe avisos', function () {
    $inactivo = cqAsesor('Inactivo');
    DB::table('users')->where('id', $inactivo->id)->update(['status' => 0]);

    expect(app(DestinatariosAviso::class)->equipo($inactivo->id, 'Cobija')->pluck('id'))->not->toContain($inactivo->id);
});

it('el job manda el detalle al asesor y la copia, una sola vez', function () {
    config(['mail.cotizacion_nueva_copia' => 'copia@test.local']);

    $asesor = cqAsesor('Dueño');
    $quote = cqCotizacion($this->producto, $asesor);

    app()->call([new AvisarCotizacionNueva($quote->id), 'handle']);
    app()->call([new AvisarCotizacionNueva($quote->id), 'handle']);

    Mail::assertSent(CorreoCotizacion::class, fn ($m) => $m->hasTo($asesor->email) && $m->asesor !== null);
    Mail::assertSent(CorreoCotizacion::class, fn ($m) => $m->hasTo('copia@test.local') && $m->designados === ['Dueño']);
    Mail::assertSent(CorreoCotizacion::class, 2);
});

it('el cliente no recibe este correo: lleva datos internos', function () {
    $quote = cqCotizacion($this->producto, cqAsesor('Dueño'));

    app()->call([new AvisarCotizacionNueva($quote->id), 'handle']);

    Mail::assertNotSent(CorreoCotizacion::class, fn ($m) => $m->hasTo('cliente@test.local'));
});

it('el listener encola el aviso en la cola whatsapp, después del commit', function () {
    Queue::fake();

    $quote = cqCotizacion($this->producto, cqAsesor('Dueño'));

    (new CotizacionNueva)->handle($quote);

    Queue::assertPushedOn(config('whatsapp.queue'), AvisarCotizacionNueva::class,
        fn ($job) => $job->quoteId === $quote->id && $job->afterCommit === true);
});

it('con MAIL_COTIZACION_NUEVA apagado no se encola nada', function () {
    Queue::fake();
    config(['mail.cotizacion_nueva' => false]);

    (new CotizacionNueva)->handle(cqCotizacion($this->producto, cqAsesor('Dueño')));

    Queue::assertNothingPushed();
});

it('está registrado en la creación de cotizaciones', function () {
    $listeners = collect(Event::getRawListeners()['quote.create.after'] ?? [])
        ->map(fn ($l) => is_string($l) ? $l : '')->implode(' ');

    expect($listeners)->toContain(CotizacionNueva::class);
});

it('el correo muestra cotización, cliente, oportunidad y productos con el formato de Imprimir', function () {
    $asesor = cqAsesor('Dueño');
    $quote = cqCotizacion($this->producto, $asesor);

    $html = (new CorreoCotizacion($quote, null, ['Dueño']))->render();

    expect($html)
        ->toContain('logo-con-texto-imprimir.png')
        ->toContain('#D41E35')
        ->toContain('#'.$quote->id)
        ->toContain(e($quote->subject))
        ->toContain(e($quote->person->name))
        ->toContain('59177655508')
        ->toContain('Tarjetas personales')
        ->toContain('Avisado a:')
        ->toContain(route('admin.quotes.edit', $quote->id));

    expect((new CorreoCotizacion($quote, $asesor))->render())->toContain('Hola Dueño')->not->toContain('Avisado a:');
});
