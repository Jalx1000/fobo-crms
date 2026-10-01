<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Webkul\Admin\Jobs\AvisarContactoNuevo;
use Webkul\Admin\Listeners\ContactoNuevo;
use Webkul\Admin\Mail\AvisoContactoNuevo;
use Webkul\Admin\Mail\BienvenidaContacto;
use Webkul\Admin\Services\DestinatariosContactoNuevo;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Product\Models\ProductoResponsable;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Whatsapp\Gateways\Dto\ContactIdentity;
use Webkul\Whatsapp\Services\ConversationResolver;

/**
 * Correo al registrarse un contacto nuevo.
 *
 * Corre contra la base del contenedor (decisión 2026-10-01) con transacciones:
 * todo se deshace. Nada sale: Mail, Http y la cola están falseados, y la caché
 * es `array` para no dejar claves en el Redis real.
 *
 * Como la base ya tiene asesores reales, se afirma que los de prueba ESTÉN (o
 * no estén) en la lista, nunca una lista exacta. La ciudad de prueba es Cobija:
 * no es una de las ciudades con personal, así que ningún asesor real la atiende.
 *
 * Spec: docs/specs/correo-contacto-nuevo.md
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    config(['cache.default' => 'array', 'mail.contacto_nuevo' => true]);

    Http::fake();
    Mail::fake();

    $this->producto = app(ProductRepository::class)->create([
        'entity_type' => 'products',
        'name'        => 'CN test '.uniqid(),
        'sku'         => 'TEST-CN-'.uniqid(),
        'price'       => 10,
        'quantity'    => 0,
    ]);
});

function cnAsesor(string $nombre): object
{
    $id = DB::table('users')->insertGetId([
        'name'       => $nombre,
        'email'      => 'cn'.uniqid().'@test.local',
        'password'   => bcrypt('x'),
        'role_id'    => DB::table('roles')->value('id'),
        'status'     => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return DB::table('users')->find($id);
}

function cnAtiende($producto, string $ciudad, object $asesor): void
{
    ProductoResponsable::create(['product_id' => $producto->id, 'ciudad' => $ciudad, 'user_id' => $asesor->id]);
}

function cnContacto(?string $ciudad = null, ?object $asesor = null, ?string $email = 'cliente@test.local')
{
    $data = [
        'entity_type' => 'persons',
        'name'        => 'Cliente CN '.uniqid(),
        'emails'      => $email ? [['value' => $email, 'label' => 'work']] : [],
        'user_id'     => $asesor?->id,
    ];

    if ($ciudad) {
        $data['ciudad'] = DB::table('attribute_options')
            ->join('attributes', 'attributes.id', '=', 'attribute_options.attribute_id')
            ->where('attributes.entity_type', 'persons')
            ->where('attributes.code', 'ciudad')
            ->where('attribute_options.name', $ciudad)
            ->value('attribute_options.id');
    }

    // Event::fake solo para el alta: el test decide cuándo corre el aviso.
    return Event::fakeFor(fn () => app(PersonRepository::class)->create($data));
}

function cnEmails($destinatarios): array
{
    return collect($destinatarios['equipo'])->pluck('email')->all();
}

it('con asesor asignado avisa a ese asesor y da la bienvenida al cliente', function () {
    $asesor = cnAsesor('Asignado');
    $contacto = cnContacto('Cobija', $asesor);

    $d = app(DestinatariosContactoNuevo::class)->para($contacto);

    expect($d['cliente'])->toBe(['cliente@test.local'])
        ->and(cnEmails($d))->toBe([$asesor->email]);
});

it('con ciudad y sin asesor avisa a quienes atienden esa ciudad, no a los de Bolivia', function () {
    $local = cnAsesor('Local Cobija');
    $nacional = cnAsesor('Nacional');
    cnAtiende($this->producto, 'Cobija', $local);
    cnAtiende($this->producto, 'Bolivia', $nacional);

    $d = app(DestinatariosContactoNuevo::class)->para(cnContacto('Cobija'));

    expect(cnEmails($d))->toContain($local->email)
        ->not->toContain($nacional->email);
});

it('con ciudad sin nadie que la atienda cae en los de Bolivia', function () {
    $nacional = cnAsesor('Nacional');
    cnAtiende($this->producto, 'Bolivia', $nacional);

    $d = app(DestinatariosContactoNuevo::class)->para(cnContacto('Cobija'));

    expect(cnEmails($d))->toContain($nacional->email);
});

it('sin ciudad avisa a los de Bolivia además del asesor asignado, sin repetir', function () {
    $nacional = cnAsesor('Nacional y dueño');
    cnAtiende($this->producto, 'Bolivia', $nacional);

    $d = app(DestinatariosContactoNuevo::class)->para(cnContacto(null, $nacional));

    expect(array_count_values(cnEmails($d))[$nacional->email])->toBe(1);
});

it('un asesor inactivo no recibe avisos', function () {
    $inactivo = cnAsesor('Inactivo');
    DB::table('users')->where('id', $inactivo->id)->update(['status' => 0]);
    cnAtiende($this->producto, 'Bolivia', $inactivo);

    $d = app(DestinatariosContactoNuevo::class)->para(cnContacto());

    expect(cnEmails($d))->not->toContain($inactivo->email);
});

it('sin email del cliente no hay bienvenida, y no es un error', function () {
    $d = app(DestinatariosContactoNuevo::class)->para(cnContacto('Cobija', cnAsesor('A'), null));

    expect($d['cliente'])->toBe([]);
});

it('el job manda la bienvenida al cliente y el aviso al equipo', function () {
    $asesor = cnAsesor('Asignado');
    $contacto = cnContacto('Cobija', $asesor);

    app()->call([new AvisarContactoNuevo($contacto->id), 'handle']);

    Mail::assertSent(BienvenidaContacto::class, fn ($m) => $m->hasTo('cliente@test.local'));
    Mail::assertSent(AvisoContactoNuevo::class, fn ($m) => $m->hasTo($asesor->email));
});

it('un contacto avisa una sola vez aunque el evento llegue dos veces', function () {
    $contacto = cnContacto('Cobija', cnAsesor('Asignado'));

    app()->call([new AvisarContactoNuevo($contacto->id), 'handle']);
    app()->call([new AvisarContactoNuevo($contacto->id), 'handle']);

    Mail::assertSent(BienvenidaContacto::class, 1);
    Mail::assertSent(AvisoContactoNuevo::class, 1);
});

it('el listener encola el aviso en la cola whatsapp, sin demora', function () {
    Queue::fake();

    $contacto = cnContacto();

    (new ContactoNuevo)->handle($contacto);

    Queue::assertPushedOn(config('whatsapp.queue'), AvisarContactoNuevo::class,
        fn ($job) => $job->personId === $contacto->id && empty($job->delay));
});

it('con MAIL_CONTACTO_NUEVO apagado no se encola nada', function () {
    Queue::fake();
    config(['mail.contacto_nuevo' => false]);

    (new ContactoNuevo)->handle(cnContacto());

    Queue::assertNothingPushed();
});

it('el listener está registrado en el alta de contactos', function () {
    expect(Event::hasListeners('contacts.person.create.after'))->toBeTrue()
        ->and(collect(Event::getRawListeners()['contacts.person.create.after'] ?? [])->map(fn ($l) => is_string($l) ? $l : '')->implode(' '))
        ->toContain(ContactoNuevo::class);
});

it('un contacto creado por WhatsApp también dispara el alta', function () {
    Queue::fake();
    Event::fake(['contacts.person.create.after']);

    app(ConversationResolver::class)->resolve(
        new ContactIdentity(phone: '+5917'.random_int(1000000, 9999999), name: 'Cliente WA'),
        null,
        'cloud_api',
    );

    Event::assertDispatched('contacts.person.create.after');
});
