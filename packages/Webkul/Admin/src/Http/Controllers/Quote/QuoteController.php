<?php

namespace Webkul\Admin\Http\Controllers\Quote;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Prettus\Repository\Criteria\RequestCriteria;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\Admin\DataGrids\Quote\QuoteDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Http\Requests\AttributeForm;
use Webkul\Admin\Http\Requests\MassDestroyRequest;
use Webkul\Admin\Http\Resources\ProductResource;
use Webkul\Admin\Http\Resources\QuoteResource;
use Webkul\Core\Traits\PDFHandler;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Product\Models\ProductProxy;
use Webkul\Quote\Repositories\QuoteRepository;
use Webkul\Whatsapp\Exceptions\AttachmentStorageException;
use Webkul\Whatsapp\Gateways\Dto\OutboundTemplate;
use Webkul\Whatsapp\Services\DocumentDispatcher;
use Webkul\Whatsapp\Services\PhoneNumber;

class QuoteController extends Controller
{
    use PDFHandler;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected QuoteRepository $quoteRepository,
        protected LeadRepository $leadRepository
    ) {
        request()->request->add(['entity_type' => 'quotes']);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(DocumentDispatcher $dispatcher): View|JsonResponse
    {
        if (request()->ajax()) {
            return datagrid(QuoteDataGrid::class)->process();
        }

        // Null for everyone else, and the view renders nothing: a template send
        // is billable, so the number is only meaningful to whoever answers for
        // the bill. Salespeople get the per-send warning in their flash instead.
        return view('admin::quotes.index', [
            'templatesSentThisMonth' => $this->isAdministrator()
                ? $dispatcher->templatesSentSince(now()->startOfMonth())
                : null,
        ]);
    }

    /**
     * Whether the logged-in user holds the unrestricted role.
     *
     * Same test Bouncer makes: `permission_type = 'all'` is what "Administrator"
     * means in Krayin — the role name is free text and can be renamed or
     * translated, so matching on it would break silently.
     */
    protected function isAdministrator(): bool
    {
        return auth()->guard('user')->user()?->role?->permission_type === 'all';
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        $lead = $this->leadRepository->find(request('id'));

        /*
        | Recotizar: el formulario arranca con los datos de una cotización
        | anterior del mismo pedido.
        |
        | Casi toda recotización repite el cliente y las condiciones —forma de
        | pago, entrega, NIT, marca, ciudad, sucursal— y cambia solo lo que se
        | pide. Copiar eso a mano era transcribir ocho campos para cambiar uno.
        |
        | Los productos NO se copian a propósito: se recotiza justamente porque
        | lo pedido cambió, y arrastrar las líneas viejas invita a mandar una
        | cotización con cantidades que ya nadie pidió.
        */
        $copia = request('copiar_de')
            ? $this->quoteRepository->find(request('copiar_de'))
            : null;

        return view('admin::quotes.create', compact('lead', 'copia'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(AttributeForm $request): RedirectResponse
    {
        $this->razonSocialPorDefecto($request);

        Event::dispatch('quote.create.before');

        $quote = $this->quoteRepository->create($request->all());

        $leadId = request('lead_id');

        if ($leadId) {
            $lead = $this->leadRepository->find($leadId);

            $lead->quotes()->attach($quote->id);
        }

        Event::dispatch('quote.create.after', $quote);

        session()->flash('success', trans('admin::app.quotes.index.create-success'));

        return request()->query('from') === 'lead' && $leadId
            ? redirect()->route('admin.leads.view', ['id' => $leadId, 'from' => 'quotes'])
            : redirect()->route('admin.quotes.index');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(int $id): View
    {
        $quote = $this->quoteRepository->findOrFail($id);

        return view('admin::quotes.edit', [
            'quote' => $quote,
            'items' => $this->itemsConVariantes($quote),
        ]);
    }

    /**
     * Los ítems con las variantes y la entrega de su producto.
     *
     * Al crear, la fila recibe eso del buscador de productos. Al editar no hay
     * búsqueda: si no viene de acá, el select de variante aparece vacío y el
     * asesor no puede cambiar una tapa por otra sin borrar la fila.
     *
     * Un producto borrado deja la fila igual —los códigos ya están copiados en
     * el ítem— pero sin variantes para elegir.
     */
    protected function itemsConVariantes($quote): array
    {
        $productos = ProductProxy::modelClass()::with('variantes')
            ->whereIn('id', $quote->items->pluck('product_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        return $quote->items->map(function ($item) use ($productos) {
            $producto = $productos->get($item->product_id);

            $extra = $producto
                ? Arr::only((new ProductResource($producto))->resolve(), ['variantes', 'entrega'])
                : ['variantes' => [], 'entrega' => null];

            return $item->toArray() + $extra;
        })->values()->all();
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(AttributeForm $request, int $id): RedirectResponse
    {
        $this->razonSocialPorDefecto($request);

        Event::dispatch('quote.update.before', $id);

        $quote = $this->quoteRepository->update($request->all(), $id);

        $quote->leads()->detach();

        $leadId = request('lead_id');

        if ($leadId) {
            $lead = $this->leadRepository->find($leadId);

            $lead->quotes()->attach($quote->id);
        }

        Event::dispatch('quote.update.after', $quote);

        session()->flash('success', trans('admin::app.quotes.index.update-success'));

        return request()->query('from') === 'lead' && $leadId
            ? redirect()->route('admin.leads.view', ['id' => $leadId, 'from' => 'quotes'])
            : redirect()->route('admin.quotes.index');
    }

    /**
     * Search the quotes.
     */
    public function search(): AnonymousResourceCollection
    {
        $quotes = $this->quoteRepository
            ->pushCriteria(app(RequestCriteria::class))
            ->all();

        return QuoteResource::collection($quotes);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        $this->quoteRepository->findOrFail($id);

        try {
            Event::dispatch('quote.delete.before', $id);

            $this->quoteRepository->delete($id);

            Event::dispatch('quote.delete.after', $id);

            return response()->json([
                'message' => trans('admin::app.quotes.index.delete-success'),
            ], 200);
        } catch (\Exception $exception) {
            return response()->json([
                'message' => trans('admin::app.quotes.index.delete-failed'),
            ], 400);
        }
    }

    /**
     * Mass Delete the specified resources.
     */
    public function massDestroy(MassDestroyRequest $massDestroyRequest): JsonResponse
    {
        $quotes = $this->quoteRepository->findWhereIn('id', $massDestroyRequest->input('indices'));

        try {
            foreach ($quotes as $quotes) {
                Event::dispatch('quote.delete.before', $quotes->id);

                $this->quoteRepository->delete($quotes->id);

                Event::dispatch('quote.delete.after', $quotes->id);
            }

            return response()->json([
                'message' => trans('admin::app.quotes.index.delete-success'),
            ]);
        } catch (\Exception $exception) {
            return response()->json([
                'message' => trans('admin::app.quotes.index.delete-failed'),
            ], 400);
        }
    }

    /**
     * El único botón «Enviar cotización»: elige el canal y delega.
     *
     * Sale por donde el cliente escribió último, porque ahí es donde está
     * mirando. Messenger solo si su ventana de 24 h sigue abierta: no tiene
     * plantillas, y WhatsApp sí puede llegarle fuera de la ventana.
     */
    public function send(int $id, DocumentDispatcher $dispatcher): JsonResponse
    {
        $person = $this->quoteRepository->findOrFail($id)->person;

        return $person && $this->prefiereMessenger($person, $dispatcher)
            ? $this->sendMessenger($id, $dispatcher)
            : $this->sendWhatsapp($id, $dispatcher);
    }

    protected function prefiereMessenger($person, DocumentDispatcher $dispatcher): bool
    {
        $messenger = $dispatcher->familyConversationFor($person->id, 'messenger');

        if (! $messenger || $dispatcher->requiresTemplate($messenger)) {
            return false;
        }

        // Sin teléfono: solo buscar, no abrir una conversación de WhatsApp nueva.
        $whatsapp = $dispatcher->conversationFor($person->id, null);

        return ! $whatsapp?->last_inbound_at
            || $messenger->last_inbound_at->gt($whatsapp->last_inbound_at);
    }

    /**
     * Send the quote to the contact over WhatsApp, as an attached PDF.
     *
     * The PDF is the very same document print() hands to the browser — rendered
     * here instead of downloaded, staged on the public disk and queued as an
     * outbound `document` message. It therefore lands in the contact's existing
     * thread, shows in the inbox and in the profile drawer's "Docs" tab, and
     * gets delivery status from the provider like any other message.
     *
     * Everything that can go wrong is answered as a 422 with a sentence the
     * salesperson can act on, because the datagrid action surfaces the response
     * message straight into a flash.
     */
    public function sendWhatsapp(int $id, DocumentDispatcher $dispatcher): JsonResponse
    {
        $quote = $this->quoteRepository->findOrFail($id);

        if (! $person = $quote->person) {
            return response()->json([
                'message' => trans('admin::app.quotes.index.whatsapp.no-person'),
            ], 422);
        }

        $conversation = $dispatcher->conversationFor($person->id, $this->contactPhone($person));

        if (! $conversation) {
            return response()->json([
                'message' => trans('admin::app.quotes.index.whatsapp.no-phone', ['person' => $person->name]),
            ], 422);
        }

        return $this->sinReenvio($quote, $conversation, $dispatcher,
            fn () => $this->despacharCotizacion($quote, $person, $conversation, $dispatcher));
    }

    /**
     * Send the quote PDF over the contact's Messenger thread.
     *
     * Same PDF, resend guard, status column and activity as sendWhatsapp(). The
     * difference: Messenger has no templates, so outside the 24h window there
     * is no way to deliver it and the salesperson is told so up front.
     */
    public function sendMessenger(int $id, DocumentDispatcher $dispatcher): JsonResponse
    {
        $quote = $this->quoteRepository->findOrFail($id);

        if (! $person = $quote->person) {
            return response()->json([
                'message' => trans('admin::app.quotes.index.whatsapp.no-person'),
            ], 422);
        }

        if (! $conversation = $dispatcher->familyConversationFor($person->id, 'messenger')) {
            return response()->json([
                'message' => trans('admin::app.quotes.index.messenger.no-conversation', ['person' => $person->name]),
            ], 422);
        }

        if ($dispatcher->requiresTemplate($conversation)) {
            return response()->json([
                'message' => trans('admin::app.quotes.index.messenger.window-closed', ['person' => $person->name]),
            ], 422);
        }

        return $this->sinReenvio($quote, $conversation, $dispatcher,
            fn () => $this->despacharPorMessenger($quote, $conversation, $dispatcher));
    }

    /**
     * El envío por Messenger, ya verificado que no es un reenvío. Sin plantillas.
     */
    protected function despacharPorMessenger($quote, $conversation, DocumentDispatcher $dispatcher): JsonResponse
    {
        try {
            $message = $dispatcher->queue(
                $conversation,
                $this->generatePDF(view('admin::quotes.pdf', compact('quote'))->render()),
                $this->quoteFileName($quote),
                'application/pdf',
                // El caption de WhatsApp usa *negrita*, que Messenger muestra tal cual.
                str_replace('*', '', $this->quoteCaption($quote)),
                null,
                ['quote_id' => $quote->id],
            );
        } catch (AttachmentStorageException $e) {
            Log::error('Quotes: could not stage the PDF for a Messenger send.', [
                'quote_id'     => $quote->id,
                'conversation' => $conversation->id,
                'error'        => $e->getMessage(),
            ]);

            return response()->json([
                'message' => trans('admin::app.quotes.index.whatsapp.storage-failed'),
            ], 422);
        }

        Event::dispatch('quote.messenger.after', $quote);

        return response()->json([
            'message'    => trans('admin::app.quotes.index.messenger.success', [
                'person' => $conversation->wa_name ?: $quote->person->name,
            ]),
            'message_id' => $message->id,
        ]);
    }

    /**
     * Guardas comunes a todo envío de la cotización, sea cual sea el canal.
     *
     * @param  callable(): JsonResponse  $enviar
     */
    protected function sinReenvio($quote, $conversation, DocumentDispatcher $dispatcher, callable $enviar): JsonResponse
    {
        /*
        | Misma cotización, misma conversación, hace un momento: no se reenvía.
        | Caso real (cotización #2198, P.S.A): el botón se confirmó 5 veces en
        | 38 segundos y el cliente recibió el PDF 5 veces. Un candado corto
        | además frena dos clics casi simultáneos, antes de que exista la fila.
        */
        $minutos = (int) config('whatsapp.quote_resend_minutes', 10);

        if ($previo = $dispatcher->recentQuoteSend($conversation, $quote->id, $minutos)) {
            return response()->json([
                'message' => trans('admin::app.quotes.index.whatsapp.already-sent', [
                    'id'      => $quote->id,
                    'phone'   => $conversation->wa_phone ?: $conversation->wa_name,
                    'ago'     => $previo->created_at->diffForHumans(),
                    'minutes' => $minutos,
                ]),
            ], 422);
        }

        /*
        | Un candado mientras dura el envío: frena dos clics casi simultáneos,
        | antes de que exista la fila del primero. lock()->get() lo libera
        | siempre al terminar, salga como salga.
        */
        $respuesta = Cache::lock("quote-whatsapp:{$quote->id}:{$conversation->id}", 60)
            ->get($enviar);

        return $respuesta ?: response()->json([
            'message' => trans('admin::app.quotes.index.whatsapp.in-progress', ['id' => $quote->id]),
        ], 422);
    }

    /**
     * El envío en sí de sendWhatsapp(), ya verificado que no es un reenvío.
     */
    protected function despacharCotizacion($quote, $person, $conversation, DocumentDispatcher $dispatcher): JsonResponse
    {

        // Outside the 24h service window WhatsApp only accepts approved
        // templates. Inside it we deliberately keep sending free-form: the text
        // stays editable and the send does not depend on Meta keeping the
        // template approved.
        $usesTemplate = $dispatcher->requiresTemplate($conversation);

        $template = $usesTemplate ? $this->quoteTemplate($quote, $person) : null;

        if ($usesTemplate && ! $template) {
            return response()->json([
                'message' => trans('admin::app.quotes.index.whatsapp.template-missing'),
            ], 422);
        }

        if ($usesTemplate && ! $conversation->last_inbound_at) {
            // Templates exist to open a conversation, so this is allowed — but
            // it is also the pattern Meta penalises when done in volume, and if
            // the number's quality rating drops we need to be able to audit it.
            Log::info('Quotes: quote template sent to a contact with no inbound history.', [
                'quote_id'     => $quote->id,
                'conversation' => $conversation->id,
                'person_id'    => $person->id,
            ]);
        }

        try {
            $message = $dispatcher->queue(
                $conversation,
                $this->generatePDF(view('admin::quotes.pdf', compact('quote'))->render()),
                $this->quoteFileName($quote),
                'application/pdf',
                $this->quoteCaption($quote),
                $template,
                ['quote_id' => $quote->id],
            );
        } catch (AttachmentStorageException $e) {
            // The transaction rolled back, so no orphan message row survives.
            Log::error('Quotes: could not stage the PDF for a WhatsApp send.', [
                'quote_id'     => $quote->id,
                'conversation' => $conversation->id,
                'error'        => $e->getMessage(),
            ]);

            return response()->json([
                'message' => trans('admin::app.quotes.index.whatsapp.storage-failed'),
            ], 422);
        }

        Event::dispatch('quote.whatsapp.after', $quote);

        return response()->json([
            // Two different sentences on purpose: sending a template costs money
            // and uses fixed text, so it must never look like an ordinary send.
            'message'    => trans(
                $usesTemplate
                    ? 'admin::app.quotes.index.whatsapp.success-template'
                    : 'admin::app.quotes.index.whatsapp.success',
                ['phone' => $conversation->wa_phone ?: $conversation->wa_name],
            ),
            'message_id' => $message->id,
        ]);
    }

    /**
     * The approved template used to reach a contact outside the 24h window.
     *
     * Returns null when it is not configured, so the caller can say what is
     * missing instead of failing deep inside the driver.
     *
     * Every parameter has a fallback and none may resolve to an empty string:
     * Meta rejects the whole message with 132000 over a single blank one.
     */
    protected function quoteTemplate($quote, $person): ?OutboundTemplate
    {
        $config = (array) config('whatsapp.templates.quote');

        if (empty($config['name']) || empty($config['language'])) {
            return null;
        }

        return new OutboundTemplate(
            name: $config['name'],
            language: $config['language'],
            bodyParams: $this->templateBodyParams(
                $quote->subject,
                $quote->grand_total,
                $quote->id,
            ),
        );
    }

    /**
     * The positional values of the approved body.
     *
     * The template reads:
     *
     *     Te enviamos la cotización {{1}}.
     *     Total: {{2}}
     *
     * Two placeholders, so exactly two values — Meta counts them and rejects the
     * whole message with 132000 over a mismatch, without saying how many it
     * wanted.
     *
     * Kept free of Eloquent on purpose: the rule being enforced is "no parameter
     * may ever be blank", and that deserves to be verifiable without a database.
     *
     * @return array{0: string, 1: string}
     */
    protected function templateBodyParams(
        ?string $subject,
        float|int|null $grandTotal,
        int|string $quoteId,
    ): array {
        return [
            // In this install `subject` holds the customer's company — the grid
            // labels it "Empresa" — which is what the approved template's own
            // sample value ("Farmacorp") was written against.
            $subject ?: '#'.$quoteId,
            core()->formatBasePrice($grandTotal),
        ];
    }

    /**
     * The number to reach a contact on: their first usable phone.
     *
     * Only ever a fallback — conversationFor() prefers a thread the contact
     * already has, so this matters exclusively for a first contact.
     */
    protected function contactPhone($person): ?string
    {
        foreach ((array) $person->contact_numbers as $number) {
            $value = is_array($number) ? ($number['value'] ?? '') : $number;

            if (strlen(PhoneNumber::digits((string) $value)) >= 7) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * What the handset shows under the document icon. Slugged: the subject is
     * free text and a filename full of slashes or accents travels badly.
     */
    protected function quoteFileName($quote): string
    {
        return 'Cotizacion-'.$quote->id
            .($quote->subject ? '-'.Str::slug($quote->subject) : '')
            .'.pdf';
    }

    /**
     * The text that rides along with the PDF.
     *
     * WhatsApp accepts a caption on documents, and a bare file with no words is
     * read as spam — so it names what it is and what it is worth.
     */
    protected function quoteCaption($quote): string
    {
        return trans('admin::app.quotes.index.whatsapp.caption', [
            'subject' => $quote->subject,
            'total'   => core()->formatBasePrice($quote->grand_total),
        ]);
    }

    /**
     * Print and download the for the specified resource.
     */
    public function print($id): Response|StreamedResponse
    {
        $quote = $this->quoteRepository->findOrFail($id);

        return $this->downloadPDF(
            view('admin::quotes.pdf', compact('quote'))->render(),
            'Quote_'.$quote->subject.'_'.$quote->created_at->format('d-m-Y')
        );
    }

    /**
     * Razón social por defecto: el nombre del cliente.
     *
     * El formato RG-VCO-005 separa CLIENTE de RAZÓN SOCIAL, pero en la mayoría
     * de los casos se factura a nombre de quien compra. Dejarla vacía saca el
     * PDF con un campo en blanco justo donde va el dato de facturación.
     *
     * Solo se completa si vino vacía: lo que el asesor escriba manda, porque
     * es el caso que justifica que el campo exista — se cotiza a una persona y
     * se factura a la empresa de su grupo.
     *
     * Se resuelve en el servidor y no solo en el formulario para que valga
     * también en la cotización que pre-elabora el agente.
     */
    protected function razonSocialPorDefecto(AttributeForm $request): void
    {
        if (trim((string) $request->input('razon_social')) !== '') {
            return;
        }

        $person = $request->input('person_id')
            ? app(\Webkul\Contact\Repositories\PersonRepository::class)->find($request->input('person_id'))
            : null;

        if ($person?->name) {
            $request->merge(['razon_social' => $person->name]);
        }
    }
}
