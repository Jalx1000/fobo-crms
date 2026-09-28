@php
    use Illuminate\Support\Facades\Storage;
    use Illuminate\Support\Str;

    // La marca elegida en la cotizacion decide el logo y el color del PDF.
    // `marca` es un atributo de tipo select, asi que devuelve el id de la fila
    // en attribute_options; el nombre de esa opcion, en slug, es la clave de
    // configuracion ("Magia Verde" -> magia_verde_logo / magia_verde_color).
    $brandSlug = null;

    if ($brandOptionId = $quote->marca) {
        $brandOption = \Webkul\Attribute\Models\AttributeOptionProxy::modelClass()::find($brandOptionId);

        $brandSlug = $brandOption ? Str::slug($brandOption->name, '_') : null;
    }

    // Cotizaciones viejas (sin marca) y opciones renombradas caen al azul
    // historico y sin logo, en lugar de romper la impresion.
    $brandColor = $brandSlug
        ? core()->getConfigData("general.settings.quote_brands.{$brandSlug}_color")
        : null;

    if (! preg_match('/^#[0-9a-f]{6}$/i', (string) $brandColor)) {
        $brandColor = '#000DBB';
    }

    /*
    | El color de marca se usa como COLOR DE TEXTO (encabezados de tabla, rol
    | de las firmas, titulo de observaciones). Lo edita el negocio desde
    | configuracion, y un color claro deja el PDF ilegible sin que nada avise.
    |
    | Si no llega a 4.5:1 sobre blanco (WCAG AA para texto normal), se oscurece
    | hasta que llegue. La marca sigue reconocible —mismo tono— y el texto se
    | lee. Los dos colores cargados hoy (#002677, #3e6950) pasan sin tocar.
    */
    $legible = function (string $hex): string {
        $lum = function (array $rgb): float {
            [$r, $g, $b] = array_map(function ($c) {
                $c /= 255;

                return $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
            }, $rgb);

            return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
        };

        $rgb = sscanf($hex, '#%02x%02x%02x');

        // 1.05 / (L + 0.05) >= 4.5  ⇔  L <= 0.1833
        for ($i = 0; $i < 12 && $lum($rgb) > 0.1833; $i++) {
            $rgb = array_map(fn ($c) => (int) round($c * 0.85), $rgb);
        }

        return sprintf('#%02X%02X%02X', ...$rgb);
    };

    $brandColor = $legible($brandColor);

    // Tinte claro para fondos de tabla y bordes: el #E9EFFC del diseno original
    // es justamente el azul de marca mezclado con ~92% de blanco.
    [$r, $g, $b] = sscanf($brandColor, '#%02x%02x%02x');

    $brandTint = sprintf(
        '#%02X%02X%02X',
        (int) round($r + (255 - $r) * 0.92),
        (int) round($g + (255 - $g) * 0.92),
        (int) round($b + (255 - $b) * 0.92)
    );

    // El logo va embebido como data URI: dompdf lo resuelve sin depender de que
    // el servidor pueda hacerse una request HTTP a si mismo.
    $brandLogo = $brandSlug
        ? core()->getConfigData("general.settings.quote_brands.{$brandSlug}_logo")
        : null;

    $brandLogoSrc = $brandLogo && Storage::exists($brandLogo)
        ? 'data:'.Storage::mimeType($brandLogo).';base64,'.base64_encode(Storage::get($brandLogo))
        : null;

    /*
    | Bloque de firmas al pie: el asesor asignado a la cotizacion y el cliente.
    |
    | La firma del asesor va embebida como data URI por el mismo motivo que el
    | logo: dompdf no puede hacerse una request HTTP a si mismo. Si el asesor no
    | cargo firma, queda la linea para firmar a mano — el bloque existe igual,
    | porque el cliente firma en su columna aunque el asesor no tenga imagen.
    |
    | Se lee $quote->user (el asesor asignado a ESTA cotizacion) y no el usuario
    | logueado: quien imprime no siempre es quien vende.
    */
    $asesor = $quote->user;

    $firmaAsesorSrc = $asesor?->firma && Storage::exists($asesor->firma)
        ? 'data:'.Storage::mimeType($asesor->firma).';base64,'.base64_encode(Storage::get($asesor->firma))
        : null;

    $cliente = $quote->person;

    $clienteEmpresa = $cliente?->organization?->name;
    $clienteNit = $quote->nit ?: ($cliente?->nit ?? null);

    /*
    | Condiciones comerciales del formato RG-VCO-005.
    |
    | Los selects (`lugar_entrega`, `forma_pago`, `sucursal`) guardan el ID de la
    | opcion, no su nombre: sin resolverlos el PDF imprimiria «24» en vez de
    | «A domicilio». Es el mismo problema que la plantilla ya resolvia a mano
    | para `marca`, ahora en un helper.
    */
    $doc = config('cotizacion.documento');

    $razonSocial = $quote->razon_social ?: $clienteEmpresa;
    $sucursal = \Webkul\Product\Support\Opciones::nombre($quote->sucursal);
    $ordenCompra = $quote->orden_compra;

    $tiempoEntrega = $quote->tiempo_entrega ?: 'Inmediata';
    $formaPago = \Webkul\Product\Support\Opciones::nombre($quote->forma_pago, 'Al contado');
    $lugarEntrega = \Webkul\Product\Support\Opciones::nombre($quote->lugar_entrega, 'Puesto almacén cliente');
    $observaciones = $quote->observaciones;

    // Validez en dias, desde la fecha de la cotizacion: el formato la expresa
    // asi («10 DIAS») y no como fecha de vencimiento.
    $validezDias = $quote->created_at && $quote->expired_at
        ? $quote->created_at->startOfDay()->diffInDays(\Carbon\Carbon::parse($quote->expired_at)->startOfDay())
        : config('cotizacion.validez_dias', 10);
@endphp
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html
    lang="{{ $locale = app()->getLocale() }}"
    dir="{{ in_array($locale, ['fa', 'ar']) ? 'rtl' : 'ltr' }}"
>
    <head>
        <!-- meta tags -->
        <meta
            http-equiv="Cache-control"
            content="no-cache"
        >

        <meta
            http-equiv="Content-Type"
            content="text/html; charset=utf-8"
        />

        @php
            if ($locale == 'en') {
                $fontFamily = [
                    'regular' => 'DejaVu Sans',
                    'bold'    => 'DejaVu Sans',
                ];
            }  else {
                $fontFamily = [
                    'regular' => 'Arial, sans-serif',
                    'bold'    => 'Arial, sans-serif',
                ];
            }

            if (in_array($locale, ['ar', 'fa', 'tr'])) {
                $fontFamily = [
                    'regular' => 'DejaVu Sans',
                    'bold'    => 'DejaVu Sans',
                ];
            }
        @endphp

        <!-- lang supports inclusion -->
        <style type="text/css">
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
                font-family: {{ $fontFamily['regular'] }};
            }

            body {
                font-size: 10px;
                color: #091341;
                font-family: "{{ $fontFamily['regular'] }}";
            }

            b, th {
                font-family: "{{ $fontFamily['bold'] }}";
            }

            .page-content {
                padding: 12px;
            }

            .page-header {
                border-bottom: 1px solid {{ $brandTint }};
                text-align: center;
                font-size: 24px;
                text-transform: uppercase;
                color: {{ $brandColor }};
                padding: 24px 0;
                margin: 0;
                display:flex;
                justify-content: space-between;
            }

            .logo-container {
                position: absolute;
                top: 20px;
                left: 20px;
            }

            .logo-container.rtl {
                left: auto;
                right: 20px;
            }

            .logo-container img {
                max-width: 100%;
                height: auto;
            }

            .page-header b {
                display: inline-block;
                vertical-align: middle;
            }

            .small-text {
                font-size: 7px;
            }

            table {
                width: 100%;
                border-spacing: 1px 0;
                border-collapse: separate;
                margin-bottom: 16px;
            }
            
            table thead th {
                background-color: {{ $brandTint }};
                color: {{ $brandColor }};
                padding: 6px 18px;
                text-align: left;
            }

            table.rtl thead tr th {
                text-align: right;
            }

            table tbody td {
                padding: 9px 18px;
                border-bottom: 1px solid {{ $brandTint }};
                text-align: left;
                vertical-align: top;
            }

            table.rtl tbody tr td {
                text-align: right;
            }

            .summary {
                width: 100%;
                display: inline-block;
            }

            .summary table {
                float: right;
                width: 250px;
                padding-top: 5px;
                padding-bottom: 5px;
                background-color: {{ $brandTint }};
                white-space: nowrap;
            }

            .summary table.rtl {
                width: 280px;
            }

            .summary table.rtl {
                margin-right: 480px;
            }

            .summary table td {
                padding: 5px 10px;
            }

            /*
             | La tabla de items tiene NUEVE columnas. Con el padding general de
             | 18px la suma se pasa del ancho de la hoja y dompdf corta la ultima
             | columna (MONTO) sin avisar: el PDF sale sin el importe de cada
             | linea. Aca lleva su propio espaciado.
             */
            .items table thead th,
            .items table tbody td {
                padding: 5px 4px;
                font-size: 8px;
            }

            .items table tbody td {
                vertical-align: middle;
            }

            .items .item-nombre {
                font-size: 8.5px;
            }

            .condiciones {
                clear: both;
                margin-top: 18px;
            }

            .condiciones table {
                width: 100%;
                border-collapse: collapse;
            }

            .condiciones td {
                border: 1px solid {{ $brandTint }};
                padding: 4px 8px;
                font-size: 9px;
            }

            .condiciones .etiqueta {
                width: 15%;
                background-color: {{ $brandTint }};
                font-family: "{{ $fontFamily['bold'] }}";
                text-transform: uppercase;
            }

            .condiciones .valor {
                width: 35%;
                text-align: center;
            }

            .legal {
                margin-top: 14px;
                font-size: 8px;
                line-height: 1.5;
                font-family: "{{ $fontFamily['bold'] }}";
            }

            .observaciones {
                margin-top: 24px;
                page-break-inside: avoid;
            }

            .observaciones .titulo {
                background-color: {{ $brandTint }};
                color: {{ $brandColor }};
                font-family: "{{ $fontFamily['bold'] }}";
                font-size: 9px;
                text-transform: uppercase;
                padding: 4px 8px;
            }

            .observaciones .cuerpo {
                border: 1px solid {{ $brandTint }};
                border-top: none;
                padding: 8px;
                font-size: 9px;
                min-height: 28px;
            }

            .doc-codigo {
                position: absolute;
                top: 18px;
                right: 12px;
                text-align: right;
                font-size: 8px;
                line-height: 1.5;
            }

            .firmas {
                /*
                 | clear: la tabla de totales flota a la derecha y, sin esto,
                 | dompdf dibuja las firmas al lado y por debajo de ella: la
                 | columna del cliente terminaba encima del Total General.
                 */
                clear: both;
                margin-top: 48px;
                page-break-inside: avoid;
            }

            .firmas table {
                width: 100%;
                border-collapse: collapse;
            }

            .firmas td {
                width: 50%;
                vertical-align: bottom;
                padding: 0 18px;
                text-align: center;
                border: none;
            }

            .firmas .firma-img {
                height: 56px;
                display: block;
                margin: 0 auto 4px auto;
            }

            .firmas .firma-espacio {
                height: 56px;
            }

            .firmas .linea {
                border-top: 1px solid #091341;
                margin: 0 auto 6px auto;
                width: 80%;
            }

            .firmas .nombre {
                font-family: "{{ $fontFamily['bold'] }}";
                font-size: 10px;
            }

            .firmas .detalle {
                font-size: 9px;
                color: #4a5578;
            }

            .firmas .rol {
                font-size: 8px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                color: {{ $brandColor }};
                margin-top: 4px;
            }

            .summary table td:nth-child(2) {
                text-align: center;
            }

            .summary table td:nth-child(3) {
                text-align: right;
            }
        </style>
    </head>

    <body dir="{{ $locale }}">
        <div class="page">
            <!-- Header -->
        <table style="width:100%; border-collapse:collapse; border-bottom:1px solid {{ $brandTint }};">
        <tr>
            <td style="width:33%; text-align:left;">
                @if ($brandLogoSrc)
                    <img src="{{ $brandLogoSrc }}"
                         alt="{{ $brandOption->name }}"
                         style="max-width:140px; max-height:60px;">
                @endif
            </td>

            <td style="width:34%; text-align:center;">
                <b style="font-size:10px; color:{{ $brandColor }};">
                    @lang('admin::app.quotes.index.pdf.registro')
                </b>
                <br>
                <b style="font-size:12px; color:{{ $brandColor }};">
                    {{ $doc['titulo'] }}
                </b>
            </td>

            {{--
                Codigo del documento del sistema de calidad de Imprimir. Sale de
                config y no de la plantilla: cambia de version sin que cambie el
                codigo.
            --}}
            <td style="width:33%; text-align:right; font-size:8px; line-height:1.6;">
                @lang('admin::app.quotes.index.pdf.codigo'): {{ $doc['codigo'] }}<br>
                @lang('admin::app.quotes.index.pdf.version'): {{ $doc['version'] }}<br>
                @lang('admin::app.quotes.index.pdf.edicion'): {{ $doc['edicion'] }}
            </td>
        </tr>
        </table>

            <div class="page-content">
                <!-- Invoice Information -->
                {{--
                    Cabecera del formato RG-VCO-005: fecha, cliente, razon
                    social, NIT, sucursal y orden de compra. El vendedor y el
                    numero de cotizacion se mantienen porque son del CRM y le
                    sirven al asesor.

                    El asunto se quito: repite lo que ya dice el detalle de
                    items —producto y variante— y en las cotizaciones del agente
                    sale como un texto armado ("Bolsas Magia Verde - 75 L ·
                    TRADICIONAL · Interior") que no es lenguaje de propuesta.
                    Sigue guardado en la cotizacion y visible en el CRM.

                    Razon social NO es lo mismo que cliente: se cotiza a una
                    persona y se factura a su empresa.
                --}}
                <table class="{{ app()->getLocale() }}" style="width:100%;">
                    <tbody>
                        <tr>
                            <td style="width: 50%; padding: 2px 18px;border:none;">
                                <b>@lang('admin::app.quotes.index.pdf.date'):</b>
                                <span>{{ core()->formatDate($quote->created_at, 'd-m-Y') }}</span>
                            </td>

                            <td style="width: 50%; padding: 2px 18px;border:none;">
                                <b>@lang('admin::app.quotes.index.pdf.cliente'):</b>
                                <span>{{ $quote->person?->name }}</span>
                            </td>
                        </tr>

                        <tr>
                            <td style="width: 50%; padding: 2px 18px;border:none;">
                                <b>@lang('admin::app.quotes.index.pdf.razon-social'):</b>
                                <span>{{ $razonSocial }}</span>
                            </td>

                            <td style="width: 50%; padding: 2px 18px;border:none;">
                                <b>@lang('admin::app.quotes.index.pdf.nit'):</b>
                                <span>{{ $clienteNit }}</span>
                            </td>
                        </tr>

                        <tr>
                            <td style="width: 50%; padding: 2px 18px;border:none;">
                                <b>@lang('admin::app.quotes.index.pdf.sucursal'):</b>
                                <span>{{ $sucursal }}</span>
                            </td>

                            <td style="width: 50%; padding: 2px 18px;border:none;">
                                <b>@lang('admin::app.quotes.index.pdf.orden-compra'):</b>
                                <span>{{ $ordenCompra }}</span>
                            </td>
                        </tr>

                        <tr>
                            <td style="width: 50%; padding: 2px 18px;border:none;">
                                <b>@lang('admin::app.quotes.index.pdf.sales-person'):</b>
                                <span>{{ $asesor?->name }}</span>
                            </td>

                            <td style="width: 50%; padding: 2px 18px;border:none;">
                                <b>@lang('admin::app.quotes.index.pdf.quote-id'):</b>
                                <span>#{{ $quote->id }}</span>
                            </td>
                        </tr>

                    </tbody>
                </table>

                {{--
                    El bloque de direcciones se quito el 11/09: el formato
                    RG-VCO-005 no lo lleva. Los datos siguen guardados en las
                    cotizaciones que ya los tenian.
                --}}

                <!-- Items: las columnas del formato RG-VCO-005 -->
                <div class="items">
                    <table class="{{ app()->getLocale() }}">
                        <thead>
                            <tr>
                                <th style="width:18px;" class="text-center">N°</th>
                                <th>@lang('admin::app.quotes.index.pdf.product-name')</th>
                                <th style="width:62px;" class="text-center">@lang('admin::app.quotes.index.pdf.medida')</th>
                                <th style="width:64px;" class="text-center">@lang('admin::app.quotes.index.pdf.cod-tr4')</th>
                                <th style="width:76px;" class="text-center">@lang('admin::app.quotes.index.pdf.cod-barra')</th>
                                <th style="width:30px;" class="text-center">@lang('admin::app.quotes.index.pdf.unidad')</th>
                                <th style="width:44px;" class="text-center">@lang('admin::app.quotes.index.pdf.quantity')</th>
                                <th style="width:58px;" class="text-center">@lang('admin::app.quotes.index.pdf.price')</th>
                                <th style="width:66px;" class="text-center">@lang('admin::app.quotes.index.pdf.amount')</th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($quote->items as $i => $item)
                                <tr>
                                    <td class="text-center">{{ $i + 1 }}</td>

                                    <td class="item-nombre">{{ $item->name }}</td>

                                    {{--
                                        Codigos: son la FOTO que el item guardo al
                                        cotizar, no una lectura del catalogo. Si
                                        alguien corrige un TR4 manana, esta
                                        cotizacion sigue diciendo lo que se emitio.
                                    --}}
                                    <td class="text-center">{{ $item->medida }}</td>
                                    <td class="text-center">{{ $item->codigo_tr4 }}</td>
                                    <td class="text-center">{{ $item->codigo_barra }}</td>
                                    <td class="text-center">{{ $item->unidad }}</td>

                                    <td class="text-center">{{ $item->quantity }}</td>
                                    <td class="text-center">{!! core()->formatBasePrice($item->price, true) !!}</td>
                                    <td class="text-center">{!! core()->formatBasePrice($item->total, true) !!}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

               <!-- Summary Table -->
                <div class="summary">
                    <table class="{{ app()->getLocale   () }}">
                        <tbody>
                            <tr>
                                <td>@lang('admin::app.quotes.index.pdf.sub-total')</td>
                                <td>-</td>
                                <td>{!! core()->formatBasePrice($quote->sub_total, true) !!}</td>
                            </tr>
        
                            <tr>
                                <td>@lang('admin::app.quotes.index.pdf.tax')</td>
                                <td>-</td>
                                <td>{!! core()->formatBasePrice($quote->tax_amount, true) !!}</td>
                            </tr>
        
                            <tr>
                                <td>@lang('admin::app.quotes.index.pdf.discount')</td>
                                <td>-</td>
                                <td>{!! core()->formatBasePrice($quote->discount_amount, true) !!}</td>
                            </tr>
        
                            <tr>
                                <td>@lang('admin::app.quotes.index.pdf.adjustment')</td>
                                <td>-</td>
                                <td>{!! core()->formatBasePrice($quote->adjustment_amount, true) !!}</td>
                            </tr>
        
                            <tr>
                                <td><strong>@lang('admin::app.quotes.index.pdf.grand-total')</strong></td>
                                <td><strong>-</strong></td>
                                <td><strong>{!! core()->formatBasePrice($quote->grand_total, true) !!}</strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Condiciones comerciales -->
                <div class="condiciones">
                    <table>
                        <tr>
                            <td class="etiqueta">@lang('admin::app.quotes.index.pdf.tiempo-entrega')</td>
                            <td class="valor">{{ $tiempoEntrega }}</td>
                            <td class="etiqueta">@lang('admin::app.quotes.index.pdf.forma-pago')</td>
                            <td class="valor">{{ $formaPago }}</td>
                        </tr>
                        <tr>
                            <td class="etiqueta">@lang('admin::app.quotes.index.pdf.validez')</td>
                            <td class="valor">{{ $validezDias }} @lang('admin::app.quotes.index.pdf.dias')</td>
                            <td class="etiqueta">@lang('admin::app.quotes.index.pdf.lugar-entrega')</td>
                            <td class="valor">{{ $lugarEntrega }}</td>
                        </tr>
                    </table>
                </div>

                <p class="legal">{{ config('cotizacion.texto_legal') }}</p>

                <!-- Firmas: asesor y cliente, en dos columnas -->
                <div class="firmas">
                    <table>
                        <tr>
                            <td>
                                @if ($firmaAsesorSrc)
                                    <img src="{{ $firmaAsesorSrc }}" class="firma-img" alt="" />
                                @else
                                    <div class="firma-espacio"></div>
                                @endif

                                <div class="linea"></div>

                                <div class="nombre">{{ $asesor?->name ?? '—' }}</div>

                                @if ($asesor?->cargo)
                                    <div class="detalle">{{ $asesor->cargo }}</div>
                                @endif

                                <div class="rol">@lang('admin::app.quotes.index.pdf.firma-asesor')</div>
                            </td>

                            <td>
                                <div class="firma-espacio"></div>

                                <div class="linea"></div>

                                <div class="nombre">{{ $cliente?->name ?? '—' }}</div>

                                @if ($clienteEmpresa)
                                    <div class="detalle">{{ $clienteEmpresa }}</div>
                                @endif

                                @if ($clienteNit)
                                    <div class="detalle">NIT {{ $clienteNit }}</div>
                                @endif

                                <div class="rol">@lang('admin::app.quotes.index.pdf.firma-cliente')</div>
                            </td>
                        </tr>
                    </table>
                </div>

                @if ($observaciones)
                    <div class="observaciones">
                        <div class="titulo">@lang('admin::app.quotes.index.pdf.observaciones')</div>
                        <div class="cuerpo">{{ $observaciones }}</div>
                    </div>
                @endif
            </div>
        </div>
    </body>
</html>
