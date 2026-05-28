{{--
  Partial: SMD Checker
  Incluir con: @include('admin::contacts.persons.partials.smd-checker', ['searchUrl' => route(...), 'mode' => 'create|edit'])

  Variables disponibles:
    $searchUrl  — URL del endpoint GET search-smd
    $mode       — 'create' (sin datos previos) | 'edit' (puede pre-cargar teléfono/CI)
    $person     — (solo en modo edit) el modelo Person existente
--}}

{{-- Botón Verificar en SMD — se incluye en el header de la sección --}}
<button
    type="button"
    id="smd-verify-btn"
    onclick="smdVerifyManual()"
    class="inline-flex cursor-pointer items-center gap-1.5 rounded-md border border-blue-300 bg-blue-50 px-3 py-1.5 text-xs font-medium text-blue-700 transition-colors duration-150 hover:bg-blue-100 focus:outline-none focus:ring-2 focus:ring-blue-300 dark:border-blue-700 dark:bg-blue-900/30 dark:text-blue-300"
>
    <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
        <path d="M10 12.5a2.5 2.5 0 100-5 2.5 2.5 0 000 5z"/>
        <path fill-rule="evenodd" d="M.664 10.59a1.651 1.651 0 010-1.186A10.004 10.004 0 0110 3c4.257 0 7.893 2.66 9.336 6.41.147.381.146.804 0 1.186A10.004 10.004 0 0110 17c-4.257 0-7.893-2.66-9.336-6.41zM14 10a4 4 0 11-8 0 4 4 0 018 0z"/>
    </svg>
    Verificar en SMD
</button>

@push('scripts')
<script>
(function () {
    const SEARCH_URL = '{{ $searchUrl }}';
    const MODE       = '{{ $mode ?? 'create' }}';
    const DEBOUNCE   = 600;

    let debounceTimer = null;
    let lastQuery     = null;
    let smdData       = null;

    // ── UI refs ──────────────────────────────────────────────────────────────
    const chip         = document.getElementById('smd-status-chip');
    const chipDot      = document.getElementById('smd-status-dot');
    const chipText     = document.getElementById('smd-status-text');
    const banner       = document.getElementById('smd-result-banner');
    const bannerIcon   = document.getElementById('smd-banner-icon');
    const bannerTitle  = document.getElementById('smd-banner-title');
    const bannerDetail = document.getElementById('smd-banner-detail');
    const autofillBtn  = document.getElementById('smd-autofill-btn');

    // ── Chip ─────────────────────────────────────────────────────────────────
    function showChip(state) {
        chip.classList.remove('hidden');
        chip.classList.add('flex');
        const states = {
            searching: {
                dot:    'bg-blue-400 animate-pulse',
                text:   'Buscando en SMD...',
                border: 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-800 dark:bg-blue-900/40 dark:text-blue-300',
            },
            found: {
                dot:    'bg-green-500',
                text:   'Encontrado en SMD',
                border: 'border-green-200 bg-green-50 text-green-700 dark:border-green-800 dark:bg-green-900/40 dark:text-green-300',
            },
            notfound: {
                dot:    'bg-yellow-400',
                text:   'No está en SMD',
                border: 'border-yellow-200 bg-yellow-50 text-yellow-700 dark:border-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300',
            },
        };
        const s = states[state] || states.notfound;
        chipDot.className    = `h-2 w-2 rounded-full ${s.dot}`;
        chipText.textContent = s.text;
        chip.className       = `flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-all duration-200 ${s.border}`;
    }

    // ── Banner ────────────────────────────────────────────────────────────────
    function showBanner(state, patient) {
        banner.classList.remove('hidden');

        if (state === 'found') {
            banner.className = 'rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/30 dark:text-green-300 transition-all duration-300';
            bannerIcon.innerHTML = svgCheck('text-green-500');
            bannerTitle.textContent = MODE === 'edit'
                ? 'Paciente encontrado en SMD — se actualizarán sus datos'
                : 'Paciente encontrado en ShareMeData';

            const parts = [];
            if (patient.name)      parts.push(patient.name);
            if (patient.phone)     parts.push('Tel: ' + patient.phone);
            if (patient.ci)        parts.push('CI: ' + patient.ci);
            if (patient.birthday)  parts.push('Nac: ' + patient.birthday);
            if (patient.insurance) parts.push(patient.insurance);
            bannerDetail.textContent = parts.join('  ·  ');

            autofillBtn.classList.remove('hidden');
            autofillBtn.classList.add('ring-green-300', 'text-green-800', 'hover:bg-green-50');
            autofillBtn.classList.remove('hover:bg-gray-50');
            autofillBtn.textContent = MODE === 'edit' ? 'Actualizar campos' : 'Autocompletar';

        } else if (state === 'notfound') {
            banner.className = 'rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm text-yellow-800 dark:border-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300 transition-all duration-300';
            bannerIcon.innerHTML = svgWarn('text-yellow-500');
            bannerTitle.textContent = 'Paciente no encontrado en ShareMeData';
            bannerDetail.textContent = 'Complete el formulario para crearlo. Se sincronizará automáticamente al guardar.';
            autofillBtn.classList.add('hidden');
            autofillBtn.classList.remove('ring-green-300', 'text-green-800', 'hover:bg-green-50');

        } else {
            banner.className = 'rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 transition-all duration-300';
            bannerIcon.innerHTML = svgInfo('text-gray-400');
            bannerTitle.textContent = 'Ingresa el teléfono o CI para buscar';
            bannerDetail.textContent = 'Escribe al menos 3 caracteres en el campo Teléfono o Carnet de Identidad.';
            autofillBtn.classList.add('hidden');
            autofillBtn.classList.remove('ring-green-300', 'text-green-800', 'hover:bg-green-50');
        }
    }

    // ── Búsqueda ──────────────────────────────────────────────────────────────
    async function search(term) {
        term = term.trim();
        if (term.length < 3 || term === lastQuery) return;
        lastQuery = term;

        showChip('searching');

        try {
            const resp = await fetch(`${SEARCH_URL}?q=${encodeURIComponent(term)}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            });
            const data = await resp.json();

            if (data.found) {
                smdData = data;
                showChip('found');
                showBanner('found', data);

                // Toast de éxito via emitter Vue si está disponible
                if (window.__vueApp?._context?.app?.$emitter) {
                    window.__vueApp._context.app.$emitter.emit('add-flash', {
                        type:    'success',
                        message: 'Paciente encontrado en SMD. Revisá los datos y hacé click en "' + (MODE === 'edit' ? 'Actualizar campos' : 'Autocompletar') + '".',
                    });
                }
            } else {
                smdData = null;
                showChip('notfound');
                showBanner('notfound', null);

                if (window.__vueApp?._context?.app?.$emitter) {
                    window.__vueApp._context.app.$emitter.emit('add-flash', {
                        type:    'warning',
                        message: 'Paciente no encontrado en SMD. Se creará al guardar.',
                    });
                }
            }
        } catch (e) {
            chip.classList.add('hidden');
            chip.classList.remove('flex');
        }
    }

    function scheduleSearch(term) {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => search(term), DEBOUNCE);
    }

    // ── Event delegation: teléfono (dinámico) y CI ────────────────────────────
    document.addEventListener('focusout', function (e) {
        const el  = e.target;
        if (! el || el.tagName !== 'INPUT') return;
        const name = el.getAttribute('name') || '';
        const val  = el.value.trim();

        if (/^contact_numbers\[\d+\]\[value\]$/.test(name) && val.length >= 3) {
            scheduleSearch(val);
        } else if (name === 'ci_paciente' && val.length >= 3) {
            scheduleSearch(val);
        }
    }, true);

    // ── Verificación manual con el botón ──────────────────────────────────────
    window.smdVerifyManual = function () {
        const phoneInput = document.querySelector('[name="contact_numbers[0][value]"]');
        const ciInput    = document.querySelector('[name="ci_paciente"]');
        const term       = (phoneInput?.value || ciInput?.value || '').trim();

        if (term.length < 3) { showBanner('hint', null); return; }
        clearTimeout(debounceTimer);
        search(term);
    };

    // ── Autocompletar / Actualizar campos ─────────────────────────────────────
    window.smdAutofill = function () {
        if (! smdData) return;

        const filled = [];

        if (smdData.name) {
            setInputValue('[name="name"]', smdData.name);
            filled.push('Nombre');
        }
        if (smdData.phone) {
            setInputValue('[name="contact_numbers[0][value]"]', smdData.phone);
            filled.push('Teléfono');
        }
        if (smdData.ci) {
            setInputValue('[name="ci_paciente"]', smdData.ci);
            filled.push('CI');
        }

        autofillBtn.textContent = '✓ ' + (MODE === 'edit' ? 'Actualizado' : 'Aplicado');
        autofillBtn.disabled    = true;

        if (filled.length && window.__vueApp?._context?.app?.$emitter) {
            window.__vueApp._context.app.$emitter.emit('add-flash', {
                type:    'success',
                message: 'Campos actualizados: ' + filled.join(', ') + '.',
            });
        }

        setTimeout(() => {
            autofillBtn.textContent = MODE === 'edit' ? 'Actualizar campos' : 'Autocompletar';
            autofillBtn.disabled    = false;
        }, 2500);
    };

    // ── Dismiss ───────────────────────────────────────────────────────────────
    window.smdDismiss = function () {
        banner.classList.add('hidden');
        chip.classList.add('hidden');
        chip.classList.remove('flex');
        lastQuery = null;
        smdData   = null;
    };

    // ── Helpers ───────────────────────────────────────────────────────────────
    function setInputValue(selector, value) {
        const el = document.querySelector(selector);
        if (! el) return;
        const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
        setter.call(el, value);
        el.dispatchEvent(new Event('input',  { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
        el.dispatchEvent(new Event('blur',   { bubbles: true }));
    }

    function svgCheck(cls) {
        return `<svg class="h-5 w-5 ${cls}" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"/></svg>`;
    }
    function svgWarn(cls) {
        return `<svg class="h-5 w-5 ${cls}" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z"/></svg>`;
    }
    function svgInfo(cls) {
        return `<svg class="h-5 w-5 ${cls}" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a.75.75 0 000 1.5h.253a.25.25 0 01.244.304l-.459 2.066A1.75 1.75 0 0010.747 15H11a.75.75 0 000-1.5h-.253a.25.25 0 01-.244-.304l.459-2.066A1.75 1.75 0 009.253 9H9z"/></svg>`;
    }

    // ── Inicialización según modo ─────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        @if(($mode ?? 'create') === 'edit' && isset($person))
            // Modo edición: auto-buscar con el teléfono ya guardado
            const phone = '{{ $person->contact_numbers[0]['value'] ?? '' }}';
            if (phone && phone !== '0' && phone.length >= 3) {
                setTimeout(() => search(phone), 800);
            } else {
                showBanner('hint', null);
            }
        @else
            // Modo creación: mostrar banner idle para guiar al usuario
            showBanner('hint', null);
        @endif
    });
})();
</script>
@endpush
