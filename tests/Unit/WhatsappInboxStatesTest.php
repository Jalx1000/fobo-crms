<?php

/*
| Chat de WhatsApp (lead, persona, calendario y página de WhatsApp). Antes un
| envío fallido no decía por qué ni se podía reintentar, un error al cargar se
| veía como «Sin mensajes todavía.», el hilo se consultaba cada 4 s aunque
| estuviera oculto (y las consultas lentas se encimaban), el borrador no volvía
| fuera de la página de WhatsApp, sin número se podía escribir igual y la lista
| de conversaciones mostraba «no hay conversaciones» cuando en realidad falló.
*/

function waInboxView(): string
{
    return file_get_contents(__DIR__.'/../../packages/Webkul/Whatsapp/src/Resources/views/inbox-component.blade.php');
}

function waChatView(): string
{
    return file_get_contents(__DIR__.'/../../packages/Webkul/Whatsapp/src/Resources/views/chat.blade.php');
}

it('un envío fallido muestra el motivo y se puede reintentar sin reescribir', function () {
    $view = waInboxView();

    expect($view)->toContain('<div v-if="msg.status === \'failed\'"')
        ->toContain("@{{ msg.error || 'el servidor no confirmó el envío.' }}")
        ->toContain('<button v-if="canRetry(msg)" type="button" @click="retrySend(msg)"')
        ->toContain('this.messages[i].error = error.response?.data?.message')
        // El provisorio se reemplaza por el reintento, no queda duplicado.
        ->toContain("if (String(msg.id).startsWith('tmp-')) {")
        ->toContain('this.sendReal(msg.text);');
});

it('solo se reintenta texto, con un destinatario y sin el agente al mando', function () {
    preg_match('/canRetry\(msg\) \{(.*?)\n                \},/s', waInboxView(), $m);

    expect($m[1])->toContain("msg.type === 'text'")
        ->toContain('! this.agentLocked')
        ->toContain('this.hasRecipient');
});

it('un error en la carga inicial se distingue de una conversación vacía', function () {
    $view = waInboxView();

    $error = strpos($view, '<div v-else-if="loadError"');
    $empty = strpos($view, '<div v-else-if="!messages.length"');

    expect($error)->not->toBeFalse()
        ->and($error)->toBeLessThan($empty)
        ->and($view)->toContain('No se pudo cargar la conversación.')
        ->toContain('@click="retryLoad"')
        // Solo la carga inicial marca error: un poll fallido se reintenta solo.
        ->toMatch('/\.catch\(error => \{\s*\/\/ Un poll que falla se reintenta solo; la carga inicial no\.\s*if \(initial\) \{/');
});

it('el hilo se consulta encadenado y solo mientras se ve', function () {
    $view = waInboxView();

    expect($view)->not->toContain('setInterval(() => this.fetchThread(false)')
        ->toContain('this.pollTimer = setTimeout(() => this.pollTick(), 4000);')
        ->toContain('if (document.hidden || ! this.isVisible) {')
        ->toContain('this.fetchThread(false).finally(() => this.schedulePoll());')
        ->toContain("document.addEventListener('visibilitychange', this.pageVisibilityHandler);")
        // Se limpia todo al desmontar.
        ->toContain('if (this.pollTimer) clearTimeout(this.pollTimer);')
        ->toContain("document.removeEventListener('visibilitychange', this.pageVisibilityHandler);");

    // Al volver a verse se retoma en el acto.
    preg_match('/this\.visibilityObserver = new IntersectionObserver\(entries => \{(.*?)\n                \}\);/s', $view, $m);
    expect($m[1])->toContain('this.isVisible = visible;')
        ->toContain('this.resumePoll();');
});

it('el borrador vuelve cuando la conversación se conoce recién al cargar', function () {
    expect(waInboxView())
        ->toContain('const known = this.conversationId;')
        ->toContain('if (! known && ! this.draft && waDrafts[this.conversationId]) {');
});

it('sin número ni conversación no se muestra la caja de escribir', function () {
    $view = waInboxView();

    expect($view)->toContain('return !! (this.context.phone || this.conversationId || this.context.conversationId);')
        ->toContain('<template v-if="! showTemplates && hasRecipient">')
        ->toContain('<div v-if="!hasRecipient" class="rounded-md bg-amber-50')
        ->toContain("hasRecipient ? 'Sin mensajes todavía.' : 'Este contacto todavía no tiene WhatsApp.'");
});

it('la lista de conversaciones avisa si no cargó y ofrece reintentar', function () {
    $view = waChatView();

    $error = strpos($view, '<div v-else-if="listError"');
    $empty = strpos($view, '<div v-else-if="!conversations.length"');

    expect($error)->not->toBeFalse()
        ->and($error)->toBeLessThan($empty)
        ->and($view)->toContain('No se pudieron cargar las conversaciones.')
        ->toContain('listError: false,')
        // Un poll de fondo que falla no tapa la lista ya visible.
        ->toContain('if (seq === this.loadSeq && ! background) this.listError = true;')
        ->toContain('this.listError = false;');
});

it('los estados de error se anuncian y sus botones son usables con teclado y en el celular', function () {
    foreach ([waInboxView(), waChatView()] as $view) {
        preg_match_all('/<div v-else-if="(?:loadError|listError)"[^>]*>/', $view, $m);

        expect($m[0])->not->toBeEmpty()
            ->each->toContain('role="alert"');
    }

    expect(substr_count(waInboxView().waChatView(), 'focus-visible:ring-2 focus-visible:ring-brandColor dark:bg-gray-800 max-lg:h-10'))->toBe(2);
});

it('el switch del agente funciona aunque el paciente nunca haya escrito', function () {
    preg_match('/toggleAgent\(enabled\) \{(.*?)\n                \},/s', waInboxView(), $m);

    // Antes cortaba en seco sin conversación y la caja quedaba bloqueada.
    expect($m[1])->not->toContain('if (!this.conversationId || this.agentToggling) return;')
        ->toContain('if (this.agentToggling || ! this.hasRecipient) return;')
        ->toContain('this.$axios.patch(`${this.agentUrlBase}/agent`, {')
        ->toContain('if (res.data?.conversation?.id) this.conversationId = res.data.conversation.id;');
});

it('un poll que salió antes del cambio no vuelve a bloquear la caja', function () {
    $view = waInboxView();

    expect($view)->toContain('const agentVersion = this.agentVersion;')
        ->toContain('if (res.data?.agent && agentVersion === this.agentVersion && ! this.agentToggling) {')
        ->and(substr_count($view, 'this.agentVersion++;'))->toBe(2);
});

it('el checkbox del agente vuelve al estado real si el cambio no se aplicó', function () {
    expect(waInboxView())->toContain('@change="toggleAgent($event.target.checked); $event.target.checked = aiEnabled"');
});

it('el recordatorio de reactivar el agente no convive con el agente activo', function () {
    expect(waInboxView())->toContain('<div v-if="agentNote && ! aiEnabled"');
});

it('con el agente activo hay una sola barra con «Tomar la conversación» y sin caja de escribir', function () {
    $view = waInboxView();

    expect($view)->toContain('<div v-if="! agentLocked" class="relative flex items-end gap-2">')
        ->toContain('Tomar la conversación')
        ->not->toContain('<button class="font-semibold underline" @click="toggleAgent(false)">Desactivalo</button>');
});

it('la ventana de 24 h se explica y avisa cuando se está cerrando', function () {
    $view = waInboxView();

    expect($view)->toContain('Ventana 24 h · quedan @{{ windowLabel }}')
        ->toContain('return s != null && s < 2 * 3600;')
        ->not->toContain('@{{ windowLabel }} restantes');
});

it('el panel del paciente usa el vocabulario de la clínica y se actualiza con mensajes nuevos', function () {
    $view = waInboxView();

    expect($view)->toContain('>Cita en curso</p>')
        ->toContain('>Próxima cita</p>')
        ->toContain('Paciente desde @{{ profile.created_at }}')
        ->toContain('Último mensaje del paciente')
        ->toContain("cloud_api: 'WhatsApp Business'")
        ->toContain('Number(profile.active_lead.value) > 0')
        ->toContain('if (inbound && this.profileOpen) this.refreshProfile();')
        ->not->toContain('>Pedido en curso</p>')
        ->not->toContain('Cliente desde')
        ->not->toContain('>Valor</p>');
});

it('la lista separa pestañas con contador de los desplegables de etapa y filtros', function () {
    $view = waChatView();

    expect($view)->toContain("{ value: 'attention', label: 'Atender', count: 'attention' },")
        ->toContain('<select :value="stageFilter || \'\'" @change="setStage($event.target.value)"')
        ->toContain('<select :value="moreFilter" @change="setFilter($event.target.value || \'all\')"')
        ->toContain('if (res.data?.counts) this.counts = res.data.counts;')
        // Ya no hay filas de chips que se cortan.
        ->not->toContain('wa-scroll-x')
        ->not->toContain('dragScroll');
});

it('cada fila dice con texto quién tiene que responder', function () {
    $view = waChatView();

    foreach (["label: 'IA'", "label: 'Humano'", "label: 'Derivada'", "label: 'Pide recepcionista'"] as $label) {
        expect($view)->toContain($label);
    }

    // El chat abierto no muestra no leídos.
    expect($view)->toContain('<span v-if="c.unread_count && ! isSelected(c)"');
});
