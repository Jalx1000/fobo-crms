@php
    /**
     * The inbox is shared by the lead view and the person view. Build a common
     * context array from whichever entity is in scope ($lead or $person).
     */
    $waPerson = $person ?? ($lead->person ?? null);

    $waPhone = null;
    if ($waPerson && is_array($waPerson->contact_numbers)) {
        $first   = $waPerson->contact_numbers[0] ?? null;
        $waPhone = is_array($first) ? ($first['value'] ?? null) : $first;
    }

    $waContext = [
        'entityType'   => isset($person) ? 'person' : 'lead',
        'personId'     => $waPerson->id ?? null,
        'leadId'       => $lead->id ?? null,
        'name'         => $waPerson->name ?? ($lead->title ?? 'Contacto'),
        'phone'        => $waPhone,
        'conversationId' => null, // resolved by backend in later sprints
    ];
@endphp

<div class="p-2">
    <v-whatsapp-inbox
        :context='@json($waContext)'
        :use-mock="{{ config('whatsapp.ui.use_mock') ? 'true' : 'false' }}"
        thread-url="{{ route('admin.whatsapp.thread') }}"
        send-url="{{ route('admin.whatsapp.send') }}"
        agent-url-base="{{ url(config('app.admin_path').'/whatsapp/conversations') }}"
        quick-replies-url="{{ route('admin.whatsapp.quick-replies.index') }}"
        person-url-base="{{ url(config('app.admin_path').'/whatsapp/persons') }}"
        products-url="{{ route('admin.whatsapp.products') }}"
    ></v-whatsapp-inbox>
</div>

@include('whatsapp::inbox-component')
