<?php

namespace Webkul\Admin\Http\Controllers\Quote;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
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
use Webkul\Admin\Http\Resources\QuoteResource;
use Webkul\Core\Traits\PDFHandler;
use Webkul\Lead\Repositories\LeadRepository;
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

        return view('admin::quotes.create', compact('lead'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(AttributeForm $request): RedirectResponse
    {
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

        return view('admin::quotes.edit', compact('quote'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(AttributeForm $request, int $id): RedirectResponse
    {
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
}
