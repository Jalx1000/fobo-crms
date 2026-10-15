@php
    /**
     * Estado de cobertura POR aseguradora (`person_insurance_statuses`).
     *
     * El paciente puede tener varias (atributo multiselect `seguro_paciente`) y cada
     * una se verifica contra su propio driver, así que acá se listan todas en vez de
     * mostrar el agregado `persons.insurance_status`, que no distingue cuál es cuál.
     *
     * Se renderiza con estado inicial del servidor (correcto al cargar la página) y
     * se refresca solo cuando el modal de verificación emite `insurance-verified`.
     */
    $seguroAttribute = app(\Webkul\Attribute\Repositories\AttributeRepository::class)
        ->findOneByField('code', 'seguro_paciente');

    $insurances = app(\Webkul\Admin\Services\InsuranceService::class)
        ->getPersonInsurances($person, $seguroAttribute);

    $statuses = $person->insuranceStatuses->keyBy('attribute_option_id');

    $initialSeguros = array_map(function ($insurance) use ($statuses) {
        $persisted = $statuses->get($insurance['id']);

        return $insurance + [
            'status'     => $persisted?->status,
            'message'    => $persisted?->message,
            'checked_at' => $persisted?->checked_at?->diffForHumans(),
        ];
    }, $insurances);
@endphp

<v-person-insurance-statuses :initial='@json($initialSeguros)' />

@pushOnce('scripts')
    <script type="text/x-template" id="v-person-insurance-statuses-template">
        <div
            v-if="seguros.length"
            class="flex flex-col gap-2 rounded-lg border border-gray-200 p-4 dark:border-gray-800"
        >
            <div class="flex items-center justify-between">
                <p class="text-sm font-semibold text-gray-800 dark:text-white">Seguros del paciente</p>

                <span class="text-xs text-gray-400">
                    @{{ seguros.length }} @{{ seguros.length === 1 ? 'registrado' : 'registrados' }}
                </span>
            </div>

            <div class="flex flex-col divide-y divide-gray-200 dark:divide-gray-800">
                <div
                    v-for="seguro in seguros"
                    :key="seguro.id"
                    class="flex items-start justify-between gap-3 py-2 first:pt-0 last:pb-0"
                >
                    <div class="flex min-w-0 flex-col gap-0.5">
                        <span class="truncate text-sm text-gray-700 dark:text-gray-300">@{{ seguro.name }}</span>

                        <span v-if="seguro.message" class="text-xs text-gray-500 dark:text-gray-400">
                            @{{ seguro.message }}
                        </span>

                        <span v-if="seguro.checked_at" class="text-xs text-gray-400">
                            Verificado @{{ seguro.checked_at }}
                        </span>
                    </div>

                    <span
                        class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                        :class="badgeClass(seguro.status)"
                    >
                        @{{ seguro.status || 'SIN VERIFICAR' }}
                    </span>
                </div>
            </div>
        </div>
    </script>

    <script type="module">
        app.component('v-person-insurance-statuses', {
            template: '#v-person-insurance-statuses-template',

            props: {
                initial: { type: Array, default: () => [] },
            },

            data() {
                return { seguros: this.initial };
            },

            mounted() {
                /**
                 * El panel se renderizó en el servidor; cuando el modal de verificación
                 * termina, emite el detalle fresco y lo repintamos sin recargar la página.
                 */
                this.$emitter.on('insurance-verified', (payload) => {
                    const results = Array.isArray(payload?.results) ? payload.results : [];

                    if (! results.length) return;

                    this.seguros = results.map((r) => ({
                        id:         r.seguro_id,
                        name:       r.seguro_name,
                        status:     r.status,
                        message:    r.message,
                        checked_at: 'hace instantes',
                    }));
                });
            },

            methods: {
                /**
                 * Clases literales, no concatenadas: Tailwind purga lo que no puede "ver"
                 * en el template, así que armar el nombre en runtime dejaría el color
                 * fuera del CSS de producción.
                 */
                badgeClass(status) {
                    const map = {
                        VIGENTE:       'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
                        EN_MORA:       'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300',
                        VENCIDO:       'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
                        NO_REGISTRADO: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
                    };

                    return map[status] || 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300';
                },
            },
        });
    </script>
@endPushOnce
