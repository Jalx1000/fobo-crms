<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Lead\Repositories\PipelineRepository;
use Webkul\Whatsapp\Jobs\IngestInboundPayload;
use Webkul\Whatsapp\Models\ConversationProxy;

/**
 * One phone number must map to exactly one Person. It did not: +59176616013
 * produced person 669 and then 691, and the customer's orders were split across
 * the two, so the agent's get_pedidos never saw a full history.
 *
 * The suite runs against a live database, so everything here is rolled back.
 */
beforeEach(function () {
    Queue::fake(); // never notify the real agent webhook from a test
    DB::beginTransaction();
});

afterEach(fn () => DB::rollBack());

/**
 * An unused number, so the assertions are about what this test creates.
 */
function unusedPhone(): string
{
    do {
        $phone = '5917'.random_int(1000000, 9999999);
    } while (app(PersonRepository::class)->findByPhone($phone));

    return $phone;
}

function inboundPayload(string $phone, string $wamid, array $message): array
{
    return [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'contacts' => [['profile' => ['name' => 'Alejandro']]],
                    'messages' => [array_merge([
                        'id'   => $wamid,
                        'from' => $phone,
                    ], $message)],
                ],
            ]],
        ]],
    ];
}

it('resolves a single person for two messages from the same number', function () {
    $phone = unusedPhone();

    foreach (['wamid.dedupe.1', 'wamid.dedupe.2'] as $wamid) {
        (new IngestInboundPayload('cloud_api', inboundPayload($phone, $wamid, [
            'type' => 'text',
            'text' => ['body' => 'Hola'],
        ])))->handle(app(\Webkul\Whatsapp\Gateways\GatewayManager::class), app(\Webkul\Whatsapp\Services\ConversationResolver::class));
    }

    $conversations = ConversationProxy::modelClass()::where('wa_phone', '+'.$phone)->get();

    expect($conversations)->toHaveCount(1);

    $conversation = $conversations->first();

    expect($conversation->person_id)->not->toBeNull()
        ->and($conversation->messages()->count())->toBe(2);

    // And exactly one person owns that number.
    expect(app(PersonRepository::class)->findByPhone($phone)->id)->toBe($conversation->person_id);
});

it('reuses the existing person when the agent posts a lead with the contact inline', function () {
    $phone = unusedPhone();

    $pipeline = app(PipelineRepository::class)->getDefaultPipeline();

    $lead = fn (string $title, string $number) => app(LeadRepository::class)->create([
        'title'                  => $title,
        'entity_type'            => 'leads',
        'lead_pipeline_id'       => $pipeline->id,
        'lead_pipeline_stage_id' => $pipeline->stages()->first()->id,
        'person'                 => [
            'name'            => 'Alejandro',
            'emails'          => [],
            'contact_numbers' => [['label' => 'work', 'value' => $number]],
        ],
    ]);

    $first = $lead('Pedido 1', $phone);

    // Second order, same line — written the way a human or the agent might:
    // with a "+" and a space. Normalization has to see through both.
    $second = $lead('Pedido 2', '+'.substr($phone, 0, 3).' '.substr($phone, 3));

    expect($second->person_id)->toBe($first->person_id);

    expect(app(PersonRepository::class)->findByPhone($phone)->leads()->count())
        ->toBeGreaterThanOrEqual(2);
});

it('still creates a person when the number is genuinely new', function () {
    $phone = unusedPhone();

    $person = app(PersonRepository::class)->create([
        'entity_type'     => 'persons',
        'name'            => 'Contacto nuevo',
        'emails'          => [],
        'contact_numbers' => [['label' => 'work', 'value' => $phone]],
    ]);

    expect($person->id)->not->toBeNull()
        ->and(app(PersonRepository::class)->findByPhone($phone)->id)->toBe($person->id);
});

it('does not merge two different people on a short or empty number', function () {
    expect(app(PersonRepository::class)->findByPhone('123'))->toBeNull()
        ->and(app(PersonRepository::class)->findByPhone(''))->toBeNull()
        ->and(app(PersonRepository::class)->findByPhone(null))->toBeNull();
});
