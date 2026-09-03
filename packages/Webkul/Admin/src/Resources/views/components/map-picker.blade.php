{{--
    Mapa para marcar un punto, compartido por el CRM y el inbox de WhatsApp.

    Expone `window.crmMapa` con dos funciones y ningún framework de por medio:
    lo usa un formulario Blade común y también el componente Vue del inbox, que
    antes traía su propia copia de esto.

        crmMapa.cargar()                    → promete Leaflet listo
        crmMapa.montar(nodo, opciones)      → devuelve el control del mapa

    Se carga desde un CDN a propósito: son 40 KB que solo bajan cuando alguien
    abre un mapa, y no hay build de assets propio en este proyecto donde
    empaquetarlo.
--}}
@once
    @push('scripts')
    <script>
        @verbatim
        window.crmMapa = (function () {
            const VERSION = '1.9.4';
            const CENTRO_POR_DEFECTO = [-17.78333, -63.18214];   // Santa Cruz de la Sierra

            let promesa = null;

            function cargar() {
                if (window.L) return Promise.resolve();
                if (promesa) return promesa;

                promesa = new Promise((resolve, reject) => {
                    const css = document.createElement('link');
                    css.rel = 'stylesheet';
                    css.href = 'https://unpkg.com/leaflet@' + VERSION + '/dist/leaflet.css';
                    document.head.appendChild(css);

                    const script = document.createElement('script');
                    script.src = 'https://unpkg.com/leaflet@' + VERSION + '/dist/leaflet.js';
                    script.onload = resolve;
                    script.onerror = () => reject(new Error('No se pudo cargar el mapa.'));
                    document.head.appendChild(script);
                });

                return promesa;
            }

            /**
             * Monta un mapa con un pin arrastrable.
             *
             * @param {HTMLElement} nodo
             * @param {{lat:?number, lng:?number, alMover:function(number,number)}} opciones
             */
            function montar(nodo, opciones = {}) {
                const { lat, lng, alMover = () => {} } = opciones;
                const tienePin = lat !== null && lat !== undefined && lat !== '' &&
                                 lng !== null && lng !== undefined && lng !== '';

                const mapa = window.L.map(nodo).setView(
                    tienePin ? [Number(lat), Number(lng)] : CENTRO_POR_DEFECTO,
                    tienePin ? 16 : 12,
                );

                window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '© OpenStreetMap',
                    maxZoom: 19,
                }).addTo(mapa);

                let pin = null;

                function ponerPin(y, x, { recentrar = true } = {}) {
                    if (! pin) {
                        pin = window.L.marker([y, x], { draggable: true }).addTo(mapa);
                        pin.on('dragend', () => {
                            const p = pin.getLatLng();
                            alMover(p.lat, p.lng);
                        });
                    } else {
                        pin.setLatLng([y, x]);
                    }

                    if (recentrar) mapa.setView([y, x], Math.max(mapa.getZoom(), 16));

                    alMover(y, x);
                }

                // Tocar el mapa mueve el pin sin recentrar: recentrar mientras
                // alguien elige un punto le mueve el mapa bajo el dedo.
                mapa.on('click', e => ponerPin(e.latlng.lat, e.latlng.lng, { recentrar: false }));

                if (tienePin) ponerPin(Number(lat), Number(lng), { recentrar: false });

                return {
                    mapa,
                    ponerPin,

                    /*
                     * Leaflet mide el contenedor al crearse. Dentro de un modal o
                     * de una pestaña que acaba de abrirse el nodo puede medir 0px
                     * de alto, y el mapa queda montado pero invisible, sin error.
                     */
                    remedir: () => mapa.invalidateSize(),
                };
            }

            /**
             * Monta el mapa en un nodo que Vue todavía puede reemplazar.
             *
             * En el admin casi todo el HTML vive dentro de #app y Vue compila
             * ese DOM como plantilla: un mapa dibujado antes de que Vue monte
             * desaparece sin error, porque Vue recrea los nodos y tira lo que
             * Leaflet había puesto adentro. Por eso se espera al `load` (los
             * módulos ya corrieron) y se vuelve a plantar si el nodo cambia.
             *
             * Devuelve un control estable: quien lo guarda no se queda con un
             * mapa muerto después de una replantada.
             */
            function montarEn(selector, opciones = {}) {
                let actual = null;
                let ultimo = { lat: opciones.lat, lng: opciones.lng };
                let nodo = null;

                const avisar = opciones.alMover || (() => {});

                function plantar() {
                    const encontrado = document.querySelector(selector);

                    if (! encontrado || encontrado === nodo) return;

                    nodo = encontrado;
                    actual = montar(nodo, {
                        ...opciones,
                        ...ultimo,
                        alMover: (y, x) => { ultimo = { lat: y, lng: x }; avisar(y, x); },
                    });
                }

                function vigilar() {
                    // Replantar solo cuando el nodo se fue del documento: un
                    // observador que reacciona a cualquier cambio remonta el
                    // mapa en cada tecla del formulario.
                    new MutationObserver(() => {
                        if (nodo && ! nodo.isConnected) plantar();
                    }).observe(document.body, { childList: true, subtree: true });
                }

                return cargar().then(() => {
                    const empezar = () => { plantar(); vigilar(); };

                    if (document.readyState === 'complete') empezar();
                    else window.addEventListener('load', empezar, { once: true });

                    return {
                        ponerPin: (y, x, o) => actual && actual.ponerPin(y, x, o),
                        remedir: () => actual && actual.remedir(),
                        get mapa() { return actual ? actual.mapa : null; },
                    };
                });
            }

            return { cargar, montar, montarEn };
        })();
        @endverbatim
    </script>
    @endpush
@endonce
