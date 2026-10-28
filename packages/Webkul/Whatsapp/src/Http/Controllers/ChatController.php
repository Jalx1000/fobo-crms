<?php

namespace Webkul\Whatsapp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Webkul\Contact\Models\PersonProxy;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Lead\Models\PipelineProxy;
use Webkul\Lead\Models\SourceProxy;
use Webkul\Lead\Models\TypeProxy;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Product\Models\ProductProxy;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\PhoneNumber;

/**
 * Standalone WhatsApp page: the conversation list pane. The thread itself is
 * served by InboxController (shared with the person/lead embedded inbox).
 */
class ChatController extends Controller
{
    /**
     * The two-pane chat page.
     */
    public function index()
    {
        return view('whatsapp::chat');
    }

    /**
     * Conversation list: searchable, filterable, ordered by recency.
     */
    public function conversations(Request $request): JsonResponse
    {
        // Doctors are not patients: they have no Person row and no lead, so they
        // can't be reached by filtering the conversation list. This tab lists
        // them from the doctors table instead, attaching the WhatsApp
        // conversation only when one already exists.
        if ($request->input('filter') === 'doctors') {
            return $this->doctorList($request);
        }

        $messageModel = MessageProxy::modelClass();

        $query = ConversationProxy::modelClass()::query()
            ->with('person:id,name')
            ->select('whatsapp_conversations.*')
            // One row per real contact. Old conversations from a decommissioned
            // gateway can carry the same number in another format (with/without
            // country code), so identity is the LAST 8 DIGITS of wa_phone (the
            // local number). Keep only the best: active gateway first, linked to
            // a person first, most recent first.
            ->joinSub(
                DB::table('whatsapp_conversations')->selectRaw(
                    "id as ranked_id, ROW_NUMBER() OVER (
                        PARTITION BY COALESCE(
                            NULLIF(RIGHT(REGEXP_REPLACE(COALESCE(wa_phone, ''), '[^0-9]', ''), 8), ''),
                            CONCAT('p', person_id),
                            CONCAT('c', id)
                        )
                        ORDER BY (gateway = ?) DESC, (person_id IS NOT NULL) DESC, last_message_at DESC, id DESC
                    ) as rn",
                    [(string) config('whatsapp.gateway')]
                ),
                'ranked',
                fn ($join) => $join->on('ranked.ranked_id', '=', 'whatsapp_conversations.id')
            )
            ->where('ranked.rn', 1)
            ->selectSub(
                $messageModel::query()->select('body')
                    ->whereColumn('conversation_id', 'whatsapp_conversations.id')
                    ->orderByDesc('id')->limit(1),
                'last_body'
            )
            ->selectSub(
                $messageModel::query()->select('direction')
                    ->whereColumn('conversation_id', 'whatsapp_conversations.id')
                    ->orderByDesc('id')->limit(1),
                'last_direction'
            )
            // Cuándo se le mandó el último recordatorio de cita. Alimenta la
            // etiqueta de la fila y el filtro, para que recepción sepa a quién
            // ya se le avisó sin abrir la conversación.
            ->selectSub(
                DB::table('whatsapp_appointment_reminders')
                    ->select('sent_at')
                    ->whereColumn('conversation_id', 'whatsapp_conversations.id')
                    ->orderByDesc('id')
                    ->limit(1),
                'last_reminder_at'
            )
            // Stage of the contact's active (or latest) lead, for the row badge.
            ->selectSub(
                DB::table('leads')
                    ->join('lead_pipeline_stages', 'lead_pipeline_stages.id', '=', 'leads.lead_pipeline_stage_id')
                    ->whereColumn('leads.person_id', 'whatsapp_conversations.person_id')
                    // Raw fragments don't get the table prefix automatically.
                    ->orderByRaw('('.DB::getTablePrefix().'leads.status = 1) desc')
                    ->orderByDesc('leads.id')
                    ->limit(1)
                    ->select('lead_pipeline_stages.name'),
                'stage_name'
            );

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('wa_name', 'like', "%{$search}%")
                    ->orWhere('wa_phone', 'like', "%{$search}%")
                    ->orWhereHas('person', fn ($p) => $p->where('name', 'like', "%{$search}%"));
            });
        }

        match ($request->input('filter')) {
            'unassigned' => $query->whereNull('person_id')->whereNull('lead_id'),
            'unread'     => $query->where('unread_count', '>', 0),
            // Sólo los que ya recibieron un recordatorio de cita, para revisar
            // la cobertura del envío del día.
            'reminded'   => $query->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('whatsapp_appointment_reminders')
                    ->whereColumn('whatsapp_appointment_reminders.conversation_id', 'whatsapp_conversations.id');
            }),
            default      => null,
        };

        // Filter by lead stage (by NAME: stages repeat across pipelines).
        if ($stage = trim((string) $request->input('stage'))) {
            $query->whereExists(function ($q) use ($stage) {
                $q->select(DB::raw(1))
                    ->from('leads')
                    ->join('lead_pipeline_stages', 'lead_pipeline_stages.id', '=', 'leads.lead_pipeline_stage_id')
                    ->whereColumn('leads.person_id', 'whatsapp_conversations.person_id')
                    ->where('lead_pipeline_stages.name', $stage);
            });
        }

        $conversations = $query
            ->orderByRaw('last_message_at is null')
            ->orderByDesc('last_message_at')
            ->paginate(30);

        return response()->json([
            'data' => collect($conversations->items())->map(fn ($c) => [
                'id'           => $c->id,
                'name'         => $c->person->name ?? $c->wa_name ?? $c->wa_phone ?? 'Sin nombre',
                'phone'        => $c->wa_phone,
                'gateway'      => $c->gateway,
                'person_id'    => $c->person_id,
                'lead_id'      => $c->lead_id,
                'unread_count' => (int) $c->unread_count,
                'ai_enabled'   => $c->aiEffective(),
                'stage'        => $c->stage_name,
                'preview'      => $c->last_direction
                    ? ($c->last_direction === 'outbound' ? '↩ ' : '').Str::limit((string) $c->last_body, 60)
                    : null,
                'last_message_at' => optional($c->last_message_at)->toIso8601String(),
                'reminded_at'     => $c->last_reminder_at,
            ]),
            'current_page' => $conversations->currentPage(),
            'last_page'    => $conversations->lastPage(),
        ]);
    }

    /**
     * Stage names of the default pipeline, for the list's stage filter.
     */
    public function stages(): JsonResponse
    {
        $pipeline = PipelineProxy::modelClass()::where('is_default', 1)->first()
            ?? PipelineProxy::modelClass()::firstOrFail();

        return response()->json([
            'stages' => $pipeline->stages()->orderBy('sort_order')->pluck('name')->unique()->values(),
        ]);
    }

    /**
     * Full client detail for the profile drawer: contact data, organization
     * and their leads, plus deep links into the CRM.
     */
    public function person(string $id): JsonResponse
    {
        $person = PersonProxy::modelClass()::with([
            'organization',
            'user',
            'tags',
            'leads' => fn ($q) => $q->with('stage')->orderByDesc('id')->limit(10),
        ])->findOrFail($id);

        // WhatsApp footprint of this contact (across all their conversations).
        $conversationIds = ConversationProxy::modelClass()::where('person_id', $person->id)->pluck('id');
        $messageModel = MessageProxy::modelClass();

        $firstMessageAt = $conversationIds->isNotEmpty()
            ? $messageModel::whereIn('conversation_id', $conversationIds)->min('created_at')
            : null;

        $lastInboundAt = $conversationIds->isNotEmpty()
            ? ConversationProxy::modelClass()::whereIn('id', $conversationIds)->max('last_inbound_at')
            : null;

        // Follow-up snapshot: who spoke last and how long ago - that's what
        // tells the advisor whether this client is waiting on them.
        $lastMessage = $conversationIds->isNotEmpty()
            ? $messageModel::whereIn('conversation_id', $conversationIds)
                ->orderByDesc('id')
                ->first(['direction', 'sender', 'created_at'])
            : null;

        // Latest open lead: the thing being followed up.
        $activeLead = $person->leads->first(fn ($lead) => (int) $lead->status === 1)
            ?? $person->leads->first();

        // Next scheduled activity (call/meeting) for this person, if any.
        $nextActivity = DB::table('activities')
            ->join('person_activities', 'person_activities.activity_id', '=', 'activities.id')
            ->where('person_activities.person_id', $person->id)
            ->where('activities.is_done', 0)
            ->whereNotNull('activities.schedule_from')
            ->where('activities.schedule_from', '>=', now())
            ->orderBy('activities.schedule_from')
            ->first(['activities.title', 'activities.type', 'activities.schedule_from']);

        // Shared media (images/documents) and links, WhatsApp-Web style.
        $media = $conversationIds->isNotEmpty()
            ? $messageModel::whereIn('conversation_id', $conversationIds)
                ->whereIn('type', ['image', 'document'])
                ->whereNotNull('media_path')
                ->orderByDesc('id')->limit(60)
                ->get(['id', 'type', 'media_path', 'media_name', 'created_at'])
            : collect();

        $links = $conversationIds->isNotEmpty()
            ? $messageModel::whereIn('conversation_id', $conversationIds)
                ->where('type', 'text')
                ->where('body', 'like', '%http%')
                ->orderByDesc('id')->limit(30)
                ->get(['id', 'body', 'direction', 'created_at'])
                ->flatMap(function ($message) {
                    preg_match_all('/https?:\/\/[^\s<>"\']+/i', (string) $message->body, $matches);

                    return collect($matches[0])->map(fn ($url) => [
                        'url'  => $url,
                        'date' => optional($message->created_at)->format('d/m/Y'),
                    ]);
                })->unique('url')->take(30)->values()
            : collect();

        // Products attached to this client's leads (the "Productos" tab).
        $products = DB::table('lead_products')
            ->join('leads', 'leads.id', '=', 'lead_products.lead_id')
            ->join('products', 'products.id', '=', 'lead_products.product_id')
            ->where('leads.person_id', $person->id)
            ->orderByDesc('lead_products.id')
            ->limit(30)
            ->get([
                'products.name',
                'lead_products.quantity',
                'lead_products.price',
                'lead_products.amount',
                'leads.title as lead_title',
            ]);

        /**
         * Cobertura del paciente para el panel de perfil.
         *
         * El atributo `seguro_paciente` es multiselect: puede haber varias aseguradoras
         * y cada una tiene su propio estado en `person_insurance_statuses`. El asesor
         * necesita verlas todas antes de cotizar por chat.
         */
        $attributeRepository = app(\Webkul\Attribute\Repositories\AttributeRepository::class);

        $seguroAttribute = $attributeRepository->findOneByField('code', 'seguro_paciente');
        $ciAttribute = $attributeRepository->findOneByField('code', 'ci_paciente');

        $persistedStatuses = $person->insuranceStatuses->keyBy('attribute_option_id');

        $insurances = collect(
            app(\Webkul\Admin\Services\InsuranceService::class)
                ->getPersonInsurances($person, $seguroAttribute)
        )->map(function ($insurance) use ($persistedStatuses) {
            $persisted = $persistedStatuses->get($insurance['id']);

            return $insurance + [
                'status'     => $persisted?->status,
                'message'    => $persisted?->message,
                'payload'    => $persisted?->payload,
                'checked_at' => $persisted?->checked_at?->locale('es')->diffForHumans(),
            ];
        })->values();

        return response()->json([
            'person' => [
                'id'              => $person->id,
                'name'            => $person->name,
                'job_title'       => $person->job_title,
                'organization'    => $person->organization->name ?? null,
                'owner'           => $person->user->name ?? null,
                'tags'            => $person->tags->map(fn ($tag) => [
                    'name'  => $tag->name,
                    'color' => $tag->color ?? null,
                ])->values(),
                'emails'          => collect($person->emails ?? [])->pluck('value')->filter()->values(),
                'contact_numbers' => collect($person->contact_numbers ?? [])->pluck('value')->filter()->values(),
                'created_at'      => optional($person->created_at)->format('d/m/Y'),
                'view_url'        => route('admin.contacts.persons.view', $person->id),
                'whatsapp'        => [
                    'messages'      => $conversationIds->isNotEmpty()
                        ? $messageModel::whereIn('conversation_id', $conversationIds)->count()
                        : 0,
                    'first_contact' => $firstMessageAt ? \Illuminate\Support\Carbon::parse($firstMessageAt)->format('d/m/Y') : null,
                    'last_inbound'  => $lastInboundAt ? \Illuminate\Support\Carbon::parse($lastInboundAt)->format('d/m/Y H:i') : null,
                ],
                'followup'        => [
                    'awaiting_reply'     => $lastMessage?->direction === 'inbound',
                    'last_message_human' => $lastMessage
                        ? \Illuminate\Support\Carbon::parse($lastMessage->created_at)->locale('es')->diffForHumans()
                        : null,
                    'last_sender'        => $lastMessage
                        ? ($lastMessage->direction === 'inbound' ? 'el cliente' : ($lastMessage->sender === 'ia' ? 'la IA' : 'El asesor'))
                        : null,
                ],
                'active_lead'     => $activeLead ? [
                    'title'     => $activeLead->title,
                    'stage'     => $activeLead->stage->name ?? null,
                    'value'     => $activeLead->lead_value ? number_format((float) $activeLead->lead_value, 2) : null,
                    'days_open' => (int) $activeLead->created_at?->diffInDays(now()),
                    'url'       => route('admin.leads.view', $activeLead->id),
                ] : null,
                'next_activity'   => $nextActivity ? [
                    'title' => $nextActivity->title,
                    'type'  => $nextActivity->type,
                    'date'  => \Illuminate\Support\Carbon::parse($nextActivity->schedule_from)->format('d/m/Y H:i'),
                ] : null,
                'images'          => $media->where('type', 'image')->map(fn ($m) => [
                    'url'  => $m->media_path,
                    'date' => optional($m->created_at)->format('d/m/Y'),
                ])->values(),
                'documents'       => $media->where('type', 'document')->map(fn ($m) => [
                    'url'  => $m->media_path,
                    'name' => $m->media_name ?: 'Documento',
                    'date' => optional($m->created_at)->format('d/m/Y'),
                ])->values(),
                'links'           => $links,
                'products'        => $products->map(fn ($p) => [
                    'name'       => $p->name,
                    'quantity'   => (int) $p->quantity,
                    'price'      => $p->price !== null ? number_format((float) $p->price, 2) : null,
                    'amount'     => $p->amount !== null ? number_format((float) $p->amount, 2) : null,
                    'lead_title' => $p->lead_title,
                ])->values(),
                // Detalle por aseguradora + veredicto agregado del paciente.
                'insurances'           => $insurances,
                'insurance_status'     => $person->insurance_status,
                'insurance_checked_at' => $person->insurance_checked_at?->locale('es')->diffForHumans(),

                /**
                 * CI y catálogo de aseguradoras: el asesor edita la cobertura sin
                 * salir del inbox, así que el panel necesita con qué poblar el form.
                 */
                'insurance_ci'      => $ciAttribute ? $person->getCustomAttributeValue($ciAttribute) : null,
                'insurance_options' => $seguroAttribute
                    ? DB::table('attribute_options')
                        ->where('attribute_id', $seguroAttribute->id)
                        ->orderBy('sort_order')
                        ->get(['id', 'name'])
                    : [],

                'leads_total'     => number_format((float) $person->leads->sum('lead_value'), 2),
                'leads'           => $person->leads->map(fn ($lead) => [
                    'id'         => $lead->id,
                    'title'      => $lead->title,
                    'stage'      => $lead->stage->name ?? null,
                    'value'      => $lead->lead_value ? number_format((float) $lead->lead_value, 2) : null,
                    'created_at' => optional($lead->created_at)->format('d/m/Y'),
                    'url'        => route('admin.leads.view', $lead->id),
                ])->values(),
            ],
        ]);
    }

    /**
     * Guarda los seguros del paciente y los verifica, desde el panel del inbox.
     *
     * Existe acá (y no reusando la ruta de contactos) para que el front del chat
     * arme la URL sobre el mismo `personUrlBase` que ya recibe, sin encadenar una
     * prop nueva a través de chat.blade + inbox-component. La lógica es la misma:
     * delega en PersonRepository + InsuranceService.
     */
    public function updateInsurance(
        Request $request,
        string $personId,
        PersonRepository $persons,
        \Webkul\Admin\Services\InsuranceService $insuranceService
    ): JsonResponse {
        // Acepta un id suelto o una lista: `seguro_paciente` es multiselect.
        $request->merge([
            'seguro_paciente' => array_values(array_filter(
                \Illuminate\Support\Arr::wrap($request->input('seguro_paciente')),
                fn ($value) => $value !== null && $value !== ''
            )),
        ]);

        $data = $request->validate([
            'ci_paciente'       => 'required|string',
            'seguro_paciente'   => 'required|array|min:1',
            'seguro_paciente.*' => 'exists:attribute_options,id',
        ]);

        $person = $persons->find($personId);

        if (! $person) {
            return response()->json(['message' => 'Paciente no encontrado.'], 404);
        }

        $persons->update([
            'entity_type'     => 'persons',
            'ci_paciente'     => $data['ci_paciente'],
            'seguro_paciente' => $data['seguro_paciente'],
        ], $personId);

        $result = $insuranceService->verify((int) $personId);

        // Una nota por aseguradora con datos reales, igual que en la ficha.
        foreach ($result['results'] ?? [$result] as $single) {
            if (in_array($single['status'], ['VIGENTE', 'EN_MORA'])) {
                $insuranceService->createNoteActivity((int) $personId, $single);
            }
        }

        return response()->json($result);
    }

    /**
     * Quick lead creation from the client profile drawer. Sensible defaults:
     * default pipeline + its first stage, "WhatsApp" as the source, first type.
     */
    public function storeLead(Request $request, string $personId, LeadRepository $leads): JsonResponse
    {
        $data = $request->validate([
            'title'      => 'required|string|max:191',
            'lead_value' => 'nullable|numeric|min:0',
        ]);

        $person = PersonProxy::modelClass()::findOrFail($personId);

        $pipeline = PipelineProxy::modelClass()::where('is_default', 1)->first()
            ?? PipelineProxy::modelClass()::firstOrFail();

        $stage = $pipeline->stages()->orderBy('sort_order')->firstOrFail();

        $source = SourceProxy::modelClass()::firstOrCreate(['name' => 'WhatsApp']);
        $type = TypeProxy::modelClass()::firstOrFail();

        Event::dispatch('lead.create.before');

        $lead = $leads->create([
            'entity_type'            => 'leads',
            'title'                  => $data['title'],
            'lead_value'             => $data['lead_value'] ?? 0,
            'person'                 => ['id' => $person->id],
            'lead_pipeline_id'       => $pipeline->id,
            'lead_pipeline_stage_id' => $stage->id,
            'lead_source_id'         => $source->id,
            'lead_type_id'           => $type->id,
            'status'                 => 1,
        ]);

        Event::dispatch('lead.create.after', $lead);

        return response()->json([
            'message' => 'Lead creado y vinculado al cliente.',
            'lead'    => ['id' => $lead->id, 'title' => $lead->title],
        ]);
    }

    /**
     * Product search for the {{producto}} / {{precio}} quick-reply variables.
     */
    public function products(Request $request): JsonResponse
    {
        // branch_id opcional: aplica la promo de esa sucursal al precio vigente.
        $branchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;

        $products = ProductProxy::modelClass()::query()
            ->with('specialty')
            ->when(trim((string) $request->input('search')), function ($q, $search) {
                $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"));
            })
            ->orderBy('name')
            ->limit(20)
            ->get();

        // current_price = precio vigente (promo de sucursal > global > base).
        // Se mantiene `price` (base) para no romper consumidores existentes.
        $data = $products->map(fn ($p) => [
            'id'               => $p->id,
            'name'             => $p->name,
            'sku'              => $p->sku,
            'price'            => $p->price,
            'current_price'    => $p->currentPrice($branchId),
            'duration_minutes' => $p->duration_minutes,
            'specialty'        => $p->specialty?->name,
        ]);

        return response()->json(['products' => $data]);
    }

    /**
     * Create a Person from an unassigned conversation and link it, so the
     * "Sin asignar" tray can convert unknown numbers into CRM contacts.
     */
    public function linkPerson(Request $request, string $id, PersonRepository $persons): JsonResponse
    {
        $conversation = ConversationProxy::modelClass()::findOrFail($id);

        if ($conversation->person_id) {
            return response()->json(['message' => 'La conversación ya tiene un contacto asignado.'], 422);
        }

        $data = $request->validate(['name' => 'nullable|string|max:120']);

        $name = $data['name'] ?? $conversation->wa_name ?? $conversation->wa_phone;
        $digits = preg_replace('/\D+/', '', (string) $conversation->wa_phone);

        $person = $persons->create([
            'entity_type'     => 'persons',
            'name'            => $name,
            'emails'          => [],
            'contact_numbers' => $digits ? [['label' => 'work', 'value' => $digits]] : [],
        ]);

        $conversation->update(['person_id' => $person->id]);

        return response()->json([
            'message'   => 'Contacto creado y vinculado.',
            'person_id' => $person->id,
        ]);
    }

    /**
     * The doctors tab: every doctor with a WhatsApp number, in the same shape
     * the conversation list uses so the front renders it with one template.
     *
     * A doctor may not have written yet, so the conversation is optional: when
     * none exists the row carries only the phone and the inbox opens an empty
     * thread, creating the conversation on the first send.
     */
    protected function doctorList(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('search'));

        $doctors = DB::table('doctors')
            ->whereNotNull('phone')
            ->where('phone', '<>', '')
            ->when($search, fn ($query) => $query->where(function ($where) use ($search) {
                $where->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            }))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'phone', 'title', 'is_active']);

        // Doctors are stored as the receptionist typed them (often 8 local
        // digits) while conversations key on the canonical wa_id, so they are
        // matched on the trailing digits - the same rule the rest of the
        // pipeline uses.
        $byTail = ConversationProxy::modelClass()::query()
            ->whereNotNull('wa_phone')
            ->get()
            ->keyBy(fn ($conversation) => PhoneNumber::tail($conversation->wa_phone));

        $data = $doctors->map(function ($doctor) use ($byTail) {
            $conversation = $byTail->get(PhoneNumber::tail($doctor->phone));

            return [
                'id'           => $conversation?->id,
                'doctor_id'    => $doctor->id,
                'name'         => $doctor->name,
                'phone'        => $doctor->phone,
                'gateway'      => $conversation?->gateway,
                'person_id'    => $conversation?->person_id,
                'lead_id'      => $conversation?->lead_id,
                'unread_count' => (int) ($conversation?->unread_count ?? 0),
                'ai_enabled'   => false, // the agent answers patients, not staff
                'stage'        => $doctor->title ?: 'Doctor',
                'preview'      => $conversation
                    ? Str::limit((string) optional($conversation->latestMessage)->body, 60)
                    : 'Sin mensajes',
                'last_message_at' => optional($conversation?->last_message_at)->toIso8601String(),
                'inactive'        => ! $doctor->is_active,
            ];
        });

        // Small, fixed roster: paginating it would only add a page control that
        // never has a second page.
        return response()->json([
            'data'         => $data->values(),
            'current_page' => 1,
            'last_page'    => 1,
        ]);
    }
}
