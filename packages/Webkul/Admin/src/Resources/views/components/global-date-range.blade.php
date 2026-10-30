{{--
    Helper compartido de rango de fechas global.

    Sincroniza el filtro de fechas entre Tablero, Leads y Pacientes usando UNA
    sola cookie (`global_date_range`) con formato "desde|hasta|quick", donde:
      - desde / hasta: fechas resueltas 'YYYY-MM-DD' (fuente de verdad).
      - quick: botón rápido activo ('7' | '30' | '90' | 'month' | '' = personalizado).

    Al pulsar p. ej. "90 días" en cualquier módulo, los tres resuelven el mismo
    rango y lo persisten aquí, así que al abrir otro módulo se refleja igual.

    Se expone como `window.OdontoDateRange` desde un <script> normal (no module)
    para que esté disponible antes de que corran los componentes (module = deferred).
--}}
@once
    @push('scripts')
        <script>
            window.OdontoDateRange = window.OdontoDateRange || {
                COOKIE: 'global_date_range',

                fmt(date) {
                    const y = date.getFullYear();
                    const m = String(date.getMonth() + 1).padStart(2, '0');
                    const d = String(date.getDate()).padStart(2, '0');

                    return `${y}-${m}-${d}`;
                },

                // Lee la cookie compartida. Compatible con el formato antiguo "desde|hasta".
                read() {
                    const match = document.cookie.match(/(?:^|;\s*)global_date_range=([^;]*)/);

                    if (! match) {
                        return { from: '', to: '', quick: '' };
                    }

                    const parts = decodeURIComponent(match[1]).split('|');

                    return {
                        from: parts[0] || '',
                        to: parts[1] || '',
                        quick: parts[2] || '',
                    };
                },

                write(from, to, quick) {
                    const maxAge = 60 * 60 * 24 * 365; // 1 año.
                    const value = `${from || ''}|${to || ''}|${quick || ''}`;

                    document.cookie = `global_date_range=${encodeURIComponent(value)}; path=/; max-age=${maxAge}; SameSite=Lax`;
                },

                // Resuelve un botón rápido ('7'|'30'|'90'|'month') a { from, to }.
                resolve(quick) {
                    const end = new Date();

                    if (quick === 'month') {
                        return {
                            from: this.fmt(new Date(end.getFullYear(), end.getMonth(), 1)),
                            to: this.fmt(end),
                        };
                    }

                    const days = Number(quick);

                    if ([7, 30, 90].includes(days)) {
                        const start = new Date();
                        start.setDate(end.getDate() - (days - 1));

                        return { from: this.fmt(start), to: this.fmt(end) };
                    }

                    return { from: '', to: '' };
                },

                // Normaliza 'quick' leído de cookie al tipo correcto (número o 'month' o '').
                normalizeQuick(quick) {
                    return quick === 'month' ? 'month' : (quick ? Number(quick) : '');
                },
            };
        </script>
    @endpush
@endonce
