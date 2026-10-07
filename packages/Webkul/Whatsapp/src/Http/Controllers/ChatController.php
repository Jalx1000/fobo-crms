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
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Models\MessageProxy;

/**
 * Standalone messaging page: the conversation list pane. The thread itself is
 * served by InboxController (shared with the person/lead embedded inbox).
 *
 * The list is multi-channel: WhatsApp and Messenger threads sit side by side,
 * each row carrying the channel it belongs to.
 */
class ChatController extends Controller
{
    public function __construct(protected GatewayManager $gateways) {}

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
        $messageModel = MessageProxy::modelClass();

        // Channels that address contacts by phone number. Only these may be
        // collapsed into one another.
        $phoneChannels = $this->gateways->keysInFamily(GatewayManager::DEFAULT_FAMILY);
        $placeholders = implode(',', array_fill(0, max(count($phoneChannels), 1), '?'));

        $query = ConversationProxy::modelClass()::query()
            ->with('person:id,name')
            ->select('whatsapp_conversations.*')
            // One row per real contact, WITHIN a channel family. Old
            // conversations from a decommissioned WhatsApp provider can carry
            // the same number in another format (with/without country code), so
            // identity there is the LAST 8 DIGITS of wa_phone (the local
            // number). Keep only the best: default provider first, linked to a
            // person first, most recent first.
            //
            // Channels outside that family are never merged: a Messenger PSID
            // is not a phone, and collapsing one against a number that happens
            // to share 8 digits made whole conversations vanish from the list.
            ->joinSub(
                DB::table('whatsapp_conversations')->selectRaw(
                    "id as ranked_id, ROW_NUMBER() OVER (
                        PARTITION BY CASE
                            WHEN gateway IN ({$placeholders}) THEN COALESCE(
                                NULLIF(RIGHT(REGEXP_REPLACE(COALESCE(wa_phone, ''), '[^0-9]', ''), 8), ''),
                                CONCAT('p', person_id),
                                CONCAT('c', id)
                            )
                            ELSE CONCAT(gateway, ':', id)
                        END
                        ORDER BY (gateway = ?) DESC, (person_id IS NOT NULL) DESC, last_message_at DESC, id DESC
                    ) as rn",
                    [...($phoneChannels ?: ['']), (string) config('whatsapp.gateway')]
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
            // Stage of the order this conversation is BOUND to. Takes precedence
            // over the guess below, so the row badge and the thread header can
            // never disagree about which order they are talking about.
            ->selectSub(
                DB::table('leads')
                    ->join('lead_pipeline_stages', 'lead_pipeline_stages.id', '=', 'leads.lead_pipeline_stage_id')
                    ->whereColumn('leads.id', 'whatsapp_conversations.lead_id')
                    ->limit(1)
                    ->select('lead_pipeline_stages.name'),
                'stage_name_bound'
            )
            // Fallback for the unbound majority: the contact's active (or latest)
            // lead. A guess — that is exactly why binding exists.
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

        // Narrow to one channel. Unknown keys are ignored rather than returning
        // an empty list, so a stale bookmark still shows the inbox.
        if ($this->gateways->has($channel = (string) $request->input('channel'))) {
            $query->where('whatsapp_conversations.gateway', $channel);
        }

        // Columns stay fully qualified: paginate() wraps this in a count(*) over
        // the joinSub, where a bare column name would be ambiguous.
        $openStates = ConversationProxy::modelClass()::HANDOFF_OPEN_STATES;

        match ($request->input('filter')) {
            'unassigned' => $query->whereNull('person_id')->whereNull('lead_id'),
            'unread'     => $query->where('unread_count', '>', 0),
            // Every conversation waiting on a human, mine or not — this is what
            // lets a supervisor spot one that has been sitting for hours.
            'handoff'    => $query->whereIn('whatsapp_conversations.handoff_state', $openStates),
            // Derived and unclaimed: the team pool.
            'pool'       => $query->where('whatsapp_conversations.handoff_state', ConversationProxy::modelClass()::HANDOFF_REQUESTED)
                ->whereNull('whatsapp_conversations.assigned_user_id'),
            'mine'       => $query->where('whatsapp_conversations.assigned_user_id', auth()->guard('user')->id())
                ->whereIn('whatsapp_conversations.handoff_state', $openStates),
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
            // per_page lo usa el refresco automático de la lista: pide de una
            // vez todo lo ya cargado (páginas × 30) para no achicarla. Antes
            // pedía solo la página 1 y el asesor saltaba al tope. Piso 30,
            // tope 300.
            ->paginate(min(300, max(30, (int) $request->integer('per_page', 30))));

        return response()->json([
            'data' => collect($conversations->items())->map(fn ($c) => [
                'id'           => $c->id,
                'name'         => $c->person->name ?? $c->wa_name ?? $c->wa_phone ?? 'Sin nombre',
                'phone'        => $c->wa_phone,
                'gateway'      => $c->gateway,
                // What the row badge renders. Without it the list cannot say
                // where a conversation came from, which is exactly how a
                // Messenger thread ends up looking like a WhatsApp one.
                'channel'       => $c->gateway,
                'channel_label' => $this->gateways->label($c->gateway),
                'avatar_url'    => $c->avatar_url,
                'person_id'     => $c->person_id,
                'lead_id'       => $c->lead_id,
                'unread_count'  => (int) $c->unread_count,
                'ai_enabled'    => $c->aiEffective(),
                'stage'         => $c->stage_name_bound ?? $c->stage_name,
                // Free: already on the row. Drives the badge and, more
                // importantly, the "Sin asesor" counter — with no notice sent to
                // the customer and no in-app notifications in Krayin, this list
                // is the only signal the team gets in real time.
                'handoff'      => $c->handoff_state,
                'handoff_mine' => $c->assigned_user_id !== null
                    && (int) $c->assigned_user_id === (int) auth()->guard('user')->id(),
                'handoff_requested_at' => optional($c->handoff_requested_at)->toIso8601String(),
                'preview'              => $c->last_direction
                    ? ($c->last_direction === 'outbound' ? '↩ ' : '').Str::limit((string) $c->last_body, 60)
                    : null,
                'last_message_at' => optional($c->last_message_at)->toIso8601String(),
            ]),
            'current_page' => $conversations->currentPage(),
            'last_page'    => $conversations->lastPage(),
            'per_page'     => $conversations->perPage(),
            'total'        => $conversations->total(),
            // Drives the channel filter. Sent with the list so the UI never
            // hardcodes a channel the installation may not have.
            'channels'     => $this->gateways->channels(),
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
            // pipeline.stages feeds the inline stage selector in the drawer: each
            // lead only ever moves within the stages of its OWN pipeline.
            'leads' => fn ($q) => $q->with(['stage', 'pipeline.stages'])->orderByDesc('id')->limit(10),
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

        // Follow-up snapshot: who spoke last and how long ago — that's what
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
                    'id'        => $activeLead->id,
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
                'leads_total'     => number_format((float) $person->leads->sum('lead_value'), 2),
                'leads'           => $person->leads->map(fn ($lead) => [
                    'id'         => $lead->id,
                    'title'      => $lead->title,
                    'stage'      => $lead->stage->name ?? null,
                    'stage_id'   => $lead->lead_pipeline_stage_id,
                    'stages'     => collect($lead->pipeline?->stages ?? [])
                        ->sortBy('sort_order')
                        ->map(fn ($stage) => ['id' => $stage->id, 'name' => $stage->name])
                        ->values(),
                    'value'      => $lead->lead_value ? number_format((float) $lead->lead_value, 2) : null,
                    'created_at' => optional($lead->created_at)->format('d/m/Y'),
                    'url'        => route('admin.leads.view', $lead->id),
                ])->values(),
            ],
        ]);
    }

    /**
     * The contact's orders, for the header picker.
     *
     * Kept separate from person(): that one is a heavy drawer payload (media,
     * links, products) and this is fetched every time the picker opens.
     *
     * Titles collide in practice — several "Cotización WhatsApp - <nombre>" for
     * the same contact on the same day — so value, date and stage travel too:
     * without them the advisor cannot tell the rows apart.
     */
    public function personLeads(string $id): JsonResponse
    {
        $person = PersonProxy::modelClass()::findOrFail($id);

        $leads = $person->leads()
            ->with(['stage', 'pipeline.stages'])
            ->orderByRaw('(status = 1) desc')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json([
            'leads' => $leads->map(fn ($lead) => [
                'id'         => $lead->id,
                'title'      => $lead->title,
                'is_open'    => (int) $lead->status === 1,
                'stage'      => $lead->stage->name ?? null,
                'stage_id'   => $lead->lead_pipeline_stage_id,
                'stages'     => collect($lead->pipeline?->stages ?? [])
                    ->sortBy('sort_order')
                    ->map(fn ($stage) => ['id' => $stage->id, 'name' => $stage->name])
                    ->values(),
                'value'      => $lead->lead_value ? number_format((float) $lead->lead_value, 2) : null,
                'created_at' => optional($lead->created_at)->format('d/m/Y'),
                'url'        => route('admin.leads.view', $lead->id),
            ])->values(),
        ]);
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
     * Move a lead to another stage straight from the WhatsApp profile drawer,
     * so the advisor never has to leave the conversation to update the pipeline.
     *
     * The target stage is resolved inside the lead's OWN pipeline — an id from
     * another pipeline is rejected instead of silently relocating the lead.
     * The repository takes care of the derived fields (closed_at, confirmed_at,
     * first_stage_change_at) and `lead.update.after` keeps automations running.
     */
    public function updateLeadStage(Request $request, string $id, LeadRepository $leads): JsonResponse
    {
        $request->validate([
            'lead_pipeline_stage_id' => 'required|integer',
        ]);

        $lead = $leads->findOrFail($id);

        $stage = $lead->pipeline
            ?->stages()
            ->where('lead_pipeline_stages.id', $request->input('lead_pipeline_stage_id'))
            ->first();

        if (! $stage) {
            return response()->json([
                'message' => 'La etapa seleccionada no pertenece al pipeline del pedido.',
            ], 422);
        }

        Event::dispatch('lead.update.before', $lead->id);

        $lead = $leads->update([
            'entity_type'            => 'leads',
            'lead_pipeline_stage_id' => $stage->id,
        ], $lead->id, ['lead_pipeline_stage_id']);

        Event::dispatch('lead.update.after', $lead);

        return response()->json([
            'message' => 'Etapa actualizada.',
            'stage'   => ['id' => $stage->id, 'name' => $stage->name],
        ]);
    }

    /**
     * Product search for the {{producto}} / {{precio}} quick-reply variables.
     */
    public function products(Request $request): JsonResponse
    {
        $products = ProductProxy::modelClass()::query()
            ->when(trim((string) $request->input('search')), function ($q, $search) {
                $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"));
            })
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'sku', 'price']);

        return response()->json(['products' => $products]);
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
}
