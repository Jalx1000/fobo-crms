<?php

namespace Webkul\Admin\DataGrids\Quote;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;
use Webkul\Whatsapp\Support\ContactChannel;

class QuoteDataGrid extends DataGrid
{
    /**
     * Prepare query builder.
     */
    public function prepareQueryBuilder(): Builder
    {
        $tablePrefix = DB::getTablePrefix();

        $queryBuilder = DB::table('quotes')
            ->addSelect(
                'quotes.id',
                'quotes.subject',
                'quotes.expired_at',
                'quotes.sub_total',
                'quotes.discount_amount',
                'quotes.tax_amount',
                'quotes.adjustment_amount',
                'quotes.grand_total',
                'quotes.created_at',
                'users.id as user_id',
                'users.name as sales_person',
                'persons.id as person_id',
                'persons.name as person_name',
                'persons.contact_numbers',
                'quotes.expired_at as expired_quotes'
            )
            ->leftJoin('users', 'quotes.user_id', '=', 'users.id')
            ->leftJoin('persons', 'quotes.person_id', '=', 'persons.id');
        // Which messaging channel this contact is reachable on. One row per
        // contact by construction, so the listing cannot duplicate.
        $queryBuilder->addSelect(ContactChannel::selectColumn());

        ContactChannel::joinTo($queryBuilder, 'persons.id');

        /*
        | El envío por WhatsApp de cada cotización: estado y hora del último, y
        | cuántas veces se mandó. Subconsultas por fila: el listado pagina de a
        | pocas, y un join repetiría la cotización por cada mensaje.
        |
        | Un mensaje es de la cotización por `quote_id` en el payload (desde
        | 2026-10-07) o, los anteriores, por el nombre del PDF «Cotizacion-<id>-».
        */
        $deEstaCotizacion = fn ($q) => $q->from('whatsapp_messages')
            ->where('whatsapp_messages.direction', 'outbound')
            ->where(fn ($w) => $w
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT({$tablePrefix}whatsapp_messages.payload, '$.quote_id')) = {$tablePrefix}quotes.id")
                ->orWhereRaw("{$tablePrefix}whatsapp_messages.media_name LIKE CONCAT('Cotizacion-', {$tablePrefix}quotes.id, '-%')"));

        $queryBuilder
            ->selectSub(fn ($q) => $deEstaCotizacion($q)->select('status')->orderByDesc('whatsapp_messages.id')->limit(1), 'whatsapp_status')
            ->selectSub(fn ($q) => $deEstaCotizacion($q)->select('created_at')->orderByDesc('whatsapp_messages.id')->limit(1), 'whatsapp_sent_at')
            ->selectSub(fn ($q) => $deEstaCotizacion($q)->where('whatsapp_messages.status', '!=', 'failed')->selectRaw('COUNT(*)'), 'whatsapp_envios');

        if ($userIds = bouncer()->getAuthorizedUserIds()) {
            $queryBuilder->whereIn('quotes.user_id', $userIds);
        }

        $this->addFilter('id', 'quotes.id');
        $this->addFilter('user', 'quotes.user_id');
        $this->addFilter('sales_person', 'users.name');
        $this->addFilter('person_name', 'persons.name');
        $this->addFilter('contact_numbers', 'persons.contact_numbers');
        $this->addFilter('platform', ContactChannel::column());
        $this->addFilter('expired_at', 'quotes.expired_at');
        $this->addFilter('created_at', 'quotes.created_at');

        if (request()->input('expired_quotes.in') == 1) {
            $this->addFilter('expired_quotes', DB::raw('DATEDIFF(NOW(), '.$tablePrefix.'quotes.expired_at) >= '.$tablePrefix.'NOW()'));
        } else {
            $this->addFilter('expired_quotes', DB::raw('DATEDIFF(NOW(), '.$tablePrefix.'quotes.expired_at) < '.$tablePrefix.'NOW()'));
        }

        return $queryBuilder;
    }

    /**
     * Prepare columns.
     */
    public function prepareColumns(): void
    {
        $this->addColumn([
            'index'      => 'subject',
            'label'      => trans('admin::app.quotes.index.datagrid.subject'),
            'type'       => 'string',
            'filterable' => true,
            'sortable'   => true,
            'searchable' => true,
        ]);

        $this->addColumn([
            'index'              => 'sales_person',
            'label'              => trans('admin::app.quotes.index.datagrid.sales-person'),
            'type'               => 'string',
            'sortable'           => true,
            'filterable'         => true,
            'searchable'         => true,
            'filterable_type'    => 'searchable_dropdown',
            'filterable_options' => [
                'repository' => \Webkul\User\Repositories\UserRepository::class,
                'column'     => [
                    'label' => 'name',
                    'value' => 'name',
                ],
            ],
        ]);

        $this->addColumn([
            'index'              => 'person_name',
            'label'              => trans('admin::app.quotes.index.datagrid.person'),
            'type'               => 'string',
            'sortable'           => true,
            'filterable'         => true,
            // El desplegable exige elegir un contacto exacto de una lista; la
            // caja de busqueda de arriba es la que sirve para tipear medio
            // nombre, y por eso esta columna tambien es searchable.
            'searchable'         => true,
            'filterable_type'    => 'searchable_dropdown',
            'filterable_options' => [
                'repository' => \Webkul\Contact\Repositories\PersonRepository::class,
                'column'     => [
                    'label' => 'name',
                    'value' => 'name',
                ],
            ],
            'closure'    => function ($row) {
                $route = route('admin.contacts.persons.view', $row->person_id);

                return "<a class=\"text-brandColor transition-all hover:underline\" href='".$route."'>".$row->person_name.'</a>';
            },
        ]);

        $this->addColumn([
            'index'      => 'sub_total',
            'label'      => trans('admin::app.quotes.index.datagrid.subtotal'),
            'type'       => 'string',
            'sortable'   => true,
            'filterable' => true,
            'closure'    => fn ($row) => core()->formatBasePrice($row->sub_total, 2),
        ]);

        $this->addColumn([
            'index'      => 'discount_amount',
            'label'      => trans('admin::app.quotes.index.datagrid.discount'),
            'type'       => 'string',
            'sortable'   => true,
            'filterable' => true,
            'closure'    => fn ($row) => core()->formatBasePrice($row->discount_amount, 2),
        ]);

        $this->addColumn([
            'index'      => 'tax_amount',
            'label'      => trans('admin::app.quotes.index.datagrid.tax'),
            'type'       => 'string',
            'filterable' => true,
            'sortable'   => true,
            'closure'    => fn ($row) => core()->formatBasePrice($row->tax_amount, 2),
        ]);

        $this->addColumn([
            'index'      => 'adjustment_amount',
            'label'      => trans('admin::app.quotes.index.datagrid.adjustment'),
            'type'       => 'string',
            'sortable'   => true,
            'filterable' => false,
            'closure'    => fn ($row) => core()->formatBasePrice($row->adjustment_amount, 2),
        ]);

        $this->addColumn([
            'index'      => 'grand_total',
            'label'      => trans('admin::app.quotes.index.datagrid.grand-total'),
            'type'       => 'string',
            'sortable'   => true,
            'filterable' => true,
            'closure'    => fn ($row) => core()->formatBasePrice($row->grand_total, 2),
        ]);

        // Si ya se mandó por WhatsApp, cómo está y cuántas veces: evita que se
        // reenvíe "por las dudas" (caso P.S.A, cotización #2198, 5 envíos).
        $this->addColumn([
            'index'      => 'whatsapp',
            'label'      => trans('admin::app.quotes.index.datagrid.whatsapp-status'),
            'type'       => 'string',
            'sortable'   => false,
            'filterable' => false,
            'closure'    => fn ($row) => self::estadoWhatsapp($row),
        ]);

        $this->addColumn([
            'index'      => 'contact_numbers',
            'label'      => trans('admin::app.quotes.index.datagrid.phone'),
            'type'       => 'string',
            'sortable'   => false,
            'filterable' => true,
            'searchable' => true,
            'closure'    => fn ($row) => collect(json_decode($row->contact_numbers, true) ?? [])->pluck('value')->join(', '),
        ]);

        $this->addColumn([
            'index'              => 'platform',
            'label'              => trans('admin::app.common.channel'),
            'type'               => 'string',
            'searchable'         => false,
            'sortable'           => true,
            'filterable'         => true,
            'filterable_type'    => 'dropdown',
            'filterable_options' => ContactChannel::options(),
            'closure'            => fn ($row) => ContactChannel::label($row->platform ?? null),
        ]);

        $this->addColumn([
            'index'      => 'expired_at',
            'label'      => trans('admin::app.quotes.index.datagrid.expired-at'),
            'type'       => 'date',
            'searchable' => false,
            'sortable'   => true,
            'filterable' => true,
            'closure'    => fn ($row) => core()->formatDate($row->expired_at, 'd M Y'),
        ]);

        $this->addColumn([
            'index'      => 'created_at',
            'label'      => trans('admin::app.quotes.index.datagrid.created-at'),
            'type'       => 'date',
            'searchable' => false,
            'sortable'   => true,
            'filterable' => true,
            'closure'    => fn ($row) => core()->formatDate($row->created_at),
        ]);
    }

    /**
     * Prepare actions.
     */
    public function prepareActions(): void
    {
        if (bouncer()->hasPermission('quotes.edit')) {
            $this->addAction([
                'index'  => 'edit',
                'icon'   => 'icon-edit',
                'title'  => trans('admin::app.quotes.index.datagrid.edit'),
                'method' => 'GET',
                'url'    => fn ($row) => route('admin.quotes.edit', $row->id),
            ]);
        }

        if (bouncer()->hasPermission('quotes.print')) {
            $this->addAction([
                'index'  => 'print',
                'icon'   => 'icon-print',
                'title'  => trans('admin::app.quotes.index.datagrid.print'),
                'method' => 'GET',
                'url'    => fn ($row) => route('admin.quotes.print', $row->id),
            ]);
        }

        if (bouncer()->hasPermission('quotes.whatsapp')) {
            // POST, so the datagrid routes it through its confirm modal and then
            // flashes whatever the controller answers — which is exactly what we
            // need: the send can be refused for several reasons the salesperson
            // has to read (no number, 24h window closed).
            // Un solo botón: el canal (WhatsApp o Messenger) lo elige QuoteController::send().
            $this->addAction([
                'index'  => 'send',
                'icon'   => 'icon-sent',
                'title'  => trans('admin::app.quotes.index.datagrid.send'),
                'method' => 'POST',
                'url'    => fn ($row) => route('admin.quotes.send', $row->id),
            ]);
        }

        if (bouncer()->hasPermission('quotes.delete')) {
            $this->addAction([
                'index'  => 'delete',
                'icon'   => 'icon-delete',
                'title'  => trans('admin::app.quotes.index.datagrid.delete'),
                'method' => 'DELETE',
                'url'    => fn ($row) => route('admin.quotes.delete', $row->id),
            ]);
        }
    }

    /**
     * Prepare mass actions.
     */
    public function prepareMassActions(): void
    {
        $this->addMassAction([
            'icon'   => 'icon-delete',
            'title'  => trans('admin::app.quotes.index.datagrid.delete'),
            'method' => 'POST',
            'url'    => route('admin.quotes.mass_delete'),
        ]);

        $this->addMassAction([
            'icon'   => 'icon-delete',
            'title'  => trans('admin::app.quotes.index.datagrid.delete'),
            'method' => 'POST',
            'url'    => route('admin.quotes.mass_delete'),
        ]);
    }

    /**
     * La etiqueta de la columna WhatsApp: estado del último envío, hace cuánto
     * y, si se mandó más de una vez, cuántas.
     *
     * Estilos en línea: el CSS compilado del admin no trae clases de color
     * para etiquetas (solo label-active / label-inactive).
     */
    public static function estadoWhatsapp($row): string
    {
        if (! $row->whatsapp_status) {
            return '<span style="color:#9ca3af;font-size:12px;">No enviada</span>';
        }

        [$texto, $fondo, $color] = match ($row->whatsapp_status) {
            'queued'    => ['Enviando…', '#f3f4f6', '#4b5563'],
            'sent'      => ['Enviada', '#dbeafe', '#1d4ed8'],
            'delivered' => ['Entregada', '#dcfce7', '#15803d'],
            'read'      => ['Leída', '#dcfce7', '#15803d'],
            'failed'    => ['Falló', '#fee2e2', '#b91c1c'],
            default     => [ucfirst((string) $row->whatsapp_status), '#f3f4f6', '#4b5563'],
        };

        $veces = (int) $row->whatsapp_envios > 1 ? ' ×'.(int) $row->whatsapp_envios : '';

        $hace = $row->whatsapp_sent_at
            ? \Carbon\Carbon::parse($row->whatsapp_sent_at)->diffForHumans()
            : '';

        return '<span style="display:inline-block;padding:2px 8px;border-radius:9999px;font-size:12px;font-weight:600;background:'.$fondo.';color:'.$color.';">'
            .e($texto.$veces).'</span>'
            .($hace ? '<div style="color:#6b7280;font-size:11px;margin-top:2px;">'.e($hace).'</div>' : '');
    }
}
