<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Webkul\Admin\Services\CiudadCliente;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\User\Models\User;

/**
 * La ciudad sincronizada entre contacto, pedido y cotización.
 *
 * Antes solo se completaba la que estaba vacía: si ya había una distinta, no se
 * tocaba. Ahora la última que se cambia manda y se copia a las demás del mismo
 * cliente (contacto, todos sus pedidos y todas sus cotizaciones).
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    Mail::fake();
    Queue::fake();
    $this->actingAs(User::findOrFail(1), 'user');

    $this->persona = Event::fakeFor(fn () => app(PersonRepository::class)->create([
        'entity_type' => 'persons', 'name' => 'Ciudad '.uniqid(),
    ]));

    $this->lead = DB::table('leads')->insertGetId([
        'title'            => 'Ciudad '.uniqid(), 'person_id' => $this->persona->id, 'user_id' => 1, 'status' => 1,
        'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', 1)->orderBy('sort_order')->value('id'),
        'created_at'       => now(), 'updated_at' => now(),
    ]);

    $this->quote = DB::table('quotes')->insertGetId([
        'subject'    => 'Ciudad', 'person_id' => $this->persona->id, 'user_id' => 1, 'sub_total' => 0, 'grand_total' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('lead_quotes')->insert(['lead_id' => $this->lead, 'quote_id' => $this->quote]);
});

function ciudadDe(string $entidad, int $id): ?string
{
    return app(CiudadCliente::class)->de($entidad, $id);
}

function ponerCiudad(string $entidad, int $id, string $ciudad): void
{
    app(CiudadCliente::class)->escribir($entidad, $id, $ciudad);
}

it('cambiar la ciudad del pedido la cambia en el contacto y la cotización', function () {
    ponerCiudad('persons', $this->persona->id, 'Santa Cruz');
    ponerCiudad('quotes', $this->quote, 'Santa Cruz');
    ponerCiudad('leads', $this->lead, 'La Paz');

    Event::dispatch('lead.update.after', app(Webkul\Lead\Repositories\LeadRepository::class)->find($this->lead));

    expect(ciudadDe('persons', $this->persona->id))->toBe('La Paz')
        ->and(ciudadDe('quotes', $this->quote))->toBe('La Paz');
});

it('cambiar la ciudad de la cotización la cambia en el contacto y el pedido', function () {
    ponerCiudad('persons', $this->persona->id, 'Santa Cruz');
    ponerCiudad('leads', $this->lead, 'Santa Cruz');
    ponerCiudad('quotes', $this->quote, 'Cochabamba');

    Event::dispatch('quote.update.after', app(Webkul\Quote\Repositories\QuoteRepository::class)->find($this->quote));

    expect(ciudadDe('persons', $this->persona->id))->toBe('Cochabamba')
        ->and(ciudadDe('leads', $this->lead))->toBe('Cochabamba');
});

it('cambiar la ciudad del contacto la cambia en sus pedidos y cotizaciones', function () {
    ponerCiudad('leads', $this->lead, 'Santa Cruz');
    ponerCiudad('quotes', $this->quote, 'Santa Cruz');
    ponerCiudad('persons', $this->persona->id, 'Oruro');

    Event::dispatch('contacts.person.update.after', app(PersonRepository::class)->find($this->persona->id));

    expect(ciudadDe('leads', $this->lead))->toBe('Oruro')
        ->and(ciudadDe('quotes', $this->quote))->toBe('Oruro');
});

it('un pedido sin ciudad toma la del contacto', function () {
    ponerCiudad('persons', $this->persona->id, 'Sucre');

    Event::dispatch('lead.update.after', app(Webkul\Lead\Repositories\LeadRepository::class)->find($this->lead));

    expect(ciudadDe('leads', $this->lead))->toBe('Sucre')
        ->and(ciudadDe('quotes', $this->quote))->toBe('Sucre');
});

it('Trinidad en el contacto es Beni en la cotización (y Cobija, Pando)', function () {
    ponerCiudad('persons', $this->persona->id, 'Trinidad');

    Event::dispatch('contacts.person.update.after', app(PersonRepository::class)->find($this->persona->id));

    expect(ciudadDe('quotes', $this->quote))->toBe('Beni')
        ->and(ciudadDe('leads', $this->lead))->toBe('Trinidad');
});
