{{--
    Tarjeta de un registro en el celular (debajo de lg), compartida por todas
    las tablas de datos: el título en negrita, cuatro datos con contenido en
    dos columnas y el resto detrás de «Ver más». Las páginas que arman su
    propia tabla (Contactos, Actividades, Configuración…) usan la misma.

    Uso: <v-datagrid-mobile-card v-for="record in available.records"
         :record="record" :available="available" :applied="applied"
         @action="performAction"></v-datagrid-mobile-card>
--}}
@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-datagrid-mobile-card-template"
    >
        <div class="hidden min-w-0 max-w-full border-b px-4 py-3 text-black dark:border-gray-800 dark:text-gray-300 max-lg:block">
            <div class="flex items-start gap-2">
                <label
                    v-if="available.massActions.length"
                    class="-ml-1 flex h-11 w-9 shrink-0 cursor-pointer items-center justify-center"
                    :for="`mass_action_select_record_${record[available.meta.primary_column]}`"
                >
                    <input
                        type="checkbox"
                        :name="`mass_action_select_record_${record[available.meta.primary_column]}`"
                        :value="record[available.meta.primary_column]"
                        :id="`mass_action_select_record_${record[available.meta.primary_column]}`"
                        class="peer hidden"
                        v-model="applied.massActions.indices"
                    >

                    <span class="icon-checkbox-outline peer-checked:icon-checkbox-select rounded-md text-2xl text-gray-500 peer-checked:text-brandColor"></span>
                </label>

                <component
                    :is="accionPrincipal(record) ? 'button' : 'div'"
                    :type="accionPrincipal(record) ? 'button' : null"
                    class="min-w-0 flex-1 py-2.5 text-left"
                    @click="accionPrincipal(record) && $emit('action', accionPrincipal(record))"
                >
                    <span
                        class="line-clamp-2 block text-base font-semibold leading-snug text-gray-900 [overflow-wrap:anywhere] dark:text-white"
                        v-html="tarjeta(record).titulo"
                    ></span>
                </component>

                <div
                    class="flex shrink-0 items-center"
                    v-if="available.actions.length"
                >
                    <button
                        type="button"
                        class="flex h-11 w-11 items-center justify-center rounded-md text-2xl text-gray-600 transition-all hover:bg-gray-200 dark:text-gray-300 dark:hover:bg-gray-800"
                        :class="action.icon"
                        :aria-label="action.title"
                        v-text="! action.icon ? action.title : ''"
                        v-for="action in record.actions"
                        @click="$emit('action', action)"
                    ></button>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-x-3 gap-y-2">
                <div
                    class="min-w-0"
                    v-for="dato in tarjeta(record).principales"
                >
                    <p class="truncate text-xs text-gray-500 dark:text-gray-400" v-html="dato.label"></p>
                    <p class="break-words [overflow-wrap:anywhere] text-sm font-medium text-gray-900 dark:text-white" v-html="dato.valor"></p>
                </div>
            </div>

            <template v-if="tarjeta(record).resto.length">
                <div
                    class="mt-2 grid grid-cols-2 gap-x-3 gap-y-2 border-t border-dashed pt-2 dark:border-gray-800"
                    v-if="abierta"
                >
                    <div
                        class="min-w-0"
                        v-for="dato in tarjeta(record).resto"
                    >
                        <p class="truncate text-xs text-gray-500 dark:text-gray-400" v-html="dato.label"></p>
                        <p class="break-words [overflow-wrap:anywhere] text-sm text-gray-900 dark:text-white" v-html="dato.valor"></p>
                    </div>
                </div>

                <button
                    type="button"
                    class="mt-1 h-9 text-sm font-medium text-brandColor"
                    @click="abierta = ! abierta"
                >
                    @{{ abierta ? '@lang('admin::app.components.datagrid.table.ver-menos')' : '@lang('admin::app.components.datagrid.table.ver-mas') (' + tarjeta(record).resto.length + ')' }}
                </button>
            </template>
        </div>
    </script>

    <script type="module">
        app.component('v-datagrid-mobile-card', {
            template: '#v-datagrid-mobile-card-template',

            props: ['record', 'available', 'applied'],

            emits: ['action'],

            data() {
                return {
                    abierta: false,
                };
            },

            methods: {
                /**
                 * Lo que muestra la tarjeta del celular: el título (asunto o
                 * nombre; si no, la primera columna que no sea el id), los
                 * cuatro primeros datos con contenido y el resto.
                 */
                tarjeta(record) {
                    const vacio = (html) => {
                        const texto = String(html ?? '').replace(/<[^>]*>/g, '').trim();

                        return texto === '' || texto === '--' || texto === '-';
                    };

                    const columnas = this.available.columns.filter((column) => column.visibility !== false);

                    const preferidas = ['title', 'subject', 'name', 'person_name'];

                    const titulo = preferidas.map((index) => columnas.find((column) => column.index === index)).find(Boolean)
                        ?? columnas.find((column) => ! /(^|\.)id$/.test(column.index))
                        ?? columnas[0];

                    const datos = columnas
                        .filter((column) => column !== titulo && ! vacio(record[column.index]))
                        .map((column) => ({ label: column.label, valor: record[column.index] }));

                    return {
                        titulo: titulo ? record[titulo.index] : '',
                        principales: datos.slice(0, 4),
                        resto: datos.slice(4),
                    };
                },

                /**
                 * La acción que abre el registro al tocar el título: «ver» o, si
                 * no hay, «editar».
                 */
                accionPrincipal(record) {
                    const acciones = (record.actions ?? []).filter((action) => action.method?.toLowerCase() === 'get');

                    return acciones.find((action) => /eye|view/.test(action.icon ?? ''))
                        ?? acciones.find((action) => /edit/.test(action.icon ?? ''))
                        ?? null;
                },
            },
        });
    </script>
@endPushOnce
