<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Webkul\Admin\Jobs\NotifyAdvisorConfirmedLead;
use Webkul\Admin\Mail\ConfirmedLeadForAdvisor;
use Webkul\Lead\Models\Lead;
use Webkul\Lead\Models\Product as LeadProduct;

/*
 * Aviso al asesor cuando su pedido pasa a "Cliente confirmado".
 *
 * Lo que había antes colgaba de `lead.update.after` y sólo preguntaba si la
 * etapa ERA confirmado, así que cada edición posterior de un pedido ya
 * confirmado repetía el correo. Acá se fija que el disparo sea el CAMBIO.
 *
 * La suite corre contra la base de PRODUCCIÓN: todo dentro de una transacción.
 */
beforeEach(function () {
    DB::beginTransaction();
    Mail::fake();
    actingAsSanctumAuthenticatedAdmin();
    test()->actingAs(getDefaultAdmin(), 'user');
});

/**
 * El interruptor vive en `core_config`, no en los archivos de config, y
 * core()->getConfigData() lo cachea por request. Se escribe la fila y se limpia
 * la caché; la transacción del test lo revierte al terminar.
 */
function interruptorDeAviso(bool $activo): void
{
    DB::table('core_config')
        ->where('code', 'leads.notifications.confirmed_order.enabled')
        ->update(['value' => $activo ? '1' : '0']);

    app()->forgetInstance('core');
}

afterEach(fn () => DB::rollBack());

function etapaPorCodigo(string $code, int $pipelineId): int
{
    return (int) DB::table('lead_pipeline_stages')
        ->where('lead_pipeline_id', $pipelineId)
        ->where('code', $code)
        ->value('id');
}

function pedidoDeUnAsesor(string $codeEtapa = 'no-atendido'): Lead
{
    $pipelineId = (int) DB::table('lead_pipelines')->where('name', 'Tarija')->value('id');

    $asesor = DB::table('users')
        ->join('roles', 'roles.id', '=', 'users.role_id')
        ->where('roles.name', 'Encargado')
        ->where('users.status', 1)
        ->value('users.id');

    $person = DB::table('persons')->insertGetId([
        'name'            => 'Cliente aviso '.uniqid(),
        'contact_numbers' => json_encode([['label' => 'work', 'value' => '59170000000']]),
        'created_at'      => now(), 'updated_at' => now(),
    ]);

    return Lead::create([
        'title'                  => 'Pedido aviso '.uniqid(),
        'status'                 => 1,
        'person_id'              => $person,
        'user_id'                => $asesor,
        'lead_pipeline_id'       => $pipelineId,
        'lead_pipeline_stage_id' => etapaPorCodigo($codeEtapa, $pipelineId),
    ]);
}

// ---------------------------------------------------------------------------
// El disparo: el CAMBIO de etapa, no el estado
// ---------------------------------------------------------------------------

it('avisa cuando el pedido pasa a Cliente confirmado', function () {
    Queue::fake();

    $lead = pedidoDeUnAsesor();

    $lead->update(['lead_pipeline_stage_id' => etapaPorCodigo('cliente-confirmado', $lead->lead_pipeline_id)]);

    Queue::assertPushed(NotifyAdvisorConfirmedLead::class);
});

it('NO vuelve a avisar al editar un pedido que ya estaba confirmado', function () {
    Queue::fake();

    $lead = pedidoDeUnAsesor('cliente-confirmado');

    /*
     * El bug que esto fija: antes alcanzaba con que la etapa FUERA confirmado,
     * así que tocar el monto reenviaba el correo. El asesor recibía el mismo
     * aviso una vez por cada edición.
     */
    $lead->update(['lead_value' => 500]);
    $lead->update(['title' => 'Otro título']);

    Queue::assertNotPushed(NotifyAdvisorConfirmedLead::class);
});

it('no avisa al pasar a cualquier otra etapa', function () {
    Queue::fake();

    $lead = pedidoDeUnAsesor();

    $lead->update(['lead_pipeline_stage_id' => etapaPorCodigo('cliente-contactado', $lead->lead_pipeline_id)]);

    Queue::assertNotPushed(NotifyAdvisorConfirmedLead::class);
});

it('avisa también si vuelve a confirmado después de salir', function () {
    Queue::fake();

    $lead = pedidoDeUnAsesor('cliente-confirmado');
    $lead->update(['lead_pipeline_stage_id' => etapaPorCodigo('cliente-contactado', $lead->lead_pipeline_id)]);
    $lead->update(['lead_pipeline_stage_id' => etapaPorCodigo('cliente-confirmado', $lead->lead_pipeline_id)]);

    Queue::assertPushed(NotifyAdvisorConfirmedLead::class, 1);
});

it('respeta el interruptor de configuración', function () {
    Queue::fake();

    interruptorDeAviso(false);

    $lead = pedidoDeUnAsesor();
    $lead->update(['lead_pipeline_stage_id' => etapaPorCodigo('cliente-confirmado', $lead->lead_pipeline_id)]);

    Queue::assertNotPushed(NotifyAdvisorConfirmedLead::class);
});

// ---------------------------------------------------------------------------
// A quién le llega y qué dice
// ---------------------------------------------------------------------------

it('le llega al asesor dueño del pedido y a las copias configuradas', function () {
    config(['leads.confirmed_order_copies' => ['copia@ejemplo.com']]);

    $lead = pedidoDeUnAsesor();
    $lead->update(['lead_pipeline_stage_id' => etapaPorCodigo('cliente-confirmado', $lead->lead_pipeline_id)]);

    Mail::assertSent(ConfirmedLeadForAdvisor::class, function ($mail) use ($lead) {
        return $mail->hasTo($lead->user->email)
            && $mail->hasTo('copia@ejemplo.com');
    });
});

it('ignora una copia mal escrita en vez de romper el envío', function () {
    config(['leads.confirmed_order_copies' => ['no-es-un-email', 'buena@ejemplo.com']]);

    $lead = pedidoDeUnAsesor();
    $lead->update(['lead_pipeline_stage_id' => etapaPorCodigo('cliente-confirmado', $lead->lead_pipeline_id)]);

    Mail::assertSent(ConfirmedLeadForAdvisor::class, fn ($mail) => $mail->hasTo('buena@ejemplo.com'));
});

it('el correo trae nombre, teléfono, id, ciudad y productos', function () {
    $lead = pedidoDeUnAsesor();

    $producto = DB::table('products')->orderBy('id')->first();

    LeadProduct::create([
        'lead_id'  => $lead->id, 'product_id' => $producto->id,
        'quantity' => 2, 'price' => 135,
    ]);

    $lead->update(['lead_pipeline_stage_id' => etapaPorCodigo('cliente-confirmado', $lead->lead_pipeline_id)]);

    Mail::assertSent(ConfirmedLeadForAdvisor::class, function ($mail) use ($lead, $producto) {
        $html = $mail->render();

        return str_contains($html, (string) $lead->id)
            && str_contains($html, '59170000000')
            && str_contains($html, 'Tarija')
            && str_contains($html, $producto->name)
            && str_contains($html, 'Bs 270')          // 2 × 135, el amount derivado
            && str_contains($html, 'Cliente confirmado');
    });
});

it('no se cae si el pedido no tiene productos', function () {
    $lead = pedidoDeUnAsesor();

    $lead->update(['lead_pipeline_stage_id' => etapaPorCodigo('cliente-confirmado', $lead->lead_pipeline_id)]);

    Mail::assertSent(ConfirmedLeadForAdvisor::class, fn ($mail) => str_contains($mail->render(), 'sin productos cargados'));
});
