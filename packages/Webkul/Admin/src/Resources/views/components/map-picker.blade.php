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

            return { cargar, montar };
        })();
        @endverbatim
    </script>
    @endpush
@endonce
