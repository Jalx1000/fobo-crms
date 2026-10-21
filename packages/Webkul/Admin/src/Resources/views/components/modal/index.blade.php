@props([
    'isActive' => false,
    'position' => 'center',
    'size'     => 'normal',
])

<v-modal
    is-active="{{ $isActive }}"
    position="{{ $position }}"
    size="{{ $size }}"
    {{ $attributes }}
>
    @isset($toggle)
        <template v-slot:toggle>
            {{ $toggle }}
        </template>
    @endisset

    @isset($header)
        <template v-slot:header="{ toggle, isOpen }">
            <div {{ $header->attributes->merge(['class' => 'flex items-center justify-between gap-2.5 border-b px-4 py-3 dark:border-gray-800']) }}>
                {{ $header }}

                <span
                    class="icon-cross-large cursor-pointer text-3xl hover:rounded-md hover:bg-gray-100 dark:hover:bg-gray-950"
                    @click="toggle"
                >
                </span>
            </div>
        </template>
    @endisset

    @isset($content)
        <template v-slot:content>
            <div {{ $content->attributes->merge(['class' => 'border-b px-4 py-2.5 dark:border-gray-800']) }}>
                {{ $content }}
            </div>
        </template>
    @endisset

    @isset($footer)
        <template v-slot:footer>
            <div {{ $content->attributes->merge(['class' => 'flex justify-end px-4 py-2.5']) }}>
                {{ $footer }}
            </div>
        </template>
    @endisset
</v-modal>

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-modal-template"
    >
        <div>
            <div @click="toggle">
                <slot name="toggle">
                </slot>
            </div>

            <transition
                tag="div"
                name="modal-overlay"
                enter-class="duration-300 ease-[cubic-bezier(.4,0,.2,1)]"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-class="duration-200 ease-[cubic-bezier(.4,0,.2,1)]"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div
                    class="fixed inset-0 z-[10002] bg-gray-500 bg-opacity-50 transition-opacity"
                    v-show="isOpen"
                ></div>
            </transition>

            <transition
                tag="div"
                name="modal-content"
                enter-class="duration-300 ease-[cubic-bezier(.4,0,.2,1)]"
                :enter-from-class="enterFromLeaveToClasses"
                enter-to-class="translate-y-0 opacity-100"
                leave-class="duration-300 ease-[cubic-bezier(.4,0,.2,1)]"
                leave-from-class="translate-y-0 opacity-100"
                :leave-to-class="enterFromLeaveToClasses"
            >
                <div
                    class="fixed inset-0 z-[10003] transform overflow-y-auto transition"
                    v-if="isOpen"
                >
                    <div class="flex min-h-full items-center justify-center max-md:p-4">
                        <div
                            class="box-shadow z-[999] w-full overflow-hidden rounded-lg bg-white dark:bg-gray-900 sm:absolute"
                            :class="[finalPositionClass, sizeClass]"
                        >
                            <!-- Header Slot -->
                            <slot
                                name="header"
                                :toggle="toggle"
                                :isOpen="isOpen"
                            >
                            </slot>

                            <!-- Content Slot -->
                            <slot name="content"></slot>
                            
                            <!-- Footer Slot -->
                            <slot name="footer"></slot>
                        </div>
                    </div>
                </div>
            </transition>
        </div>
    </script>

    <script type="module">
        /*
        | Pila de modales abiertos, compartida por todas las instancias.
        |
        | Hace falta una pila y no un booleano por modal porque Escape tiene que
        | cerrar SOLO el de arriba: con un selector de inmuebles abierto sobre un
        | modal de edición, Escape cierra el selector y deja la edición intacta.
        | Sin pila se cerrarían los dos y el asesor perdería lo que estaba
        | escribiendo.
        |
        | El listener se registra una sola vez, no uno por modal: el CRM tiene
        | decenas de modales en una página y cada uno con su listener es
        | trabajo repetido en cada pulsación de tecla.
        */
        const modalesAbiertos = [];

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape' || ! modalesAbiertos.length) return;

            // Un campo de texto puede estar usando Escape para lo suyo —cerrar
            // un autocompletado, por ejemplo—. Si alguien ya lo atendió, no se
            // cierra el modal encima.
            if (event.defaultPrevented) return;

            modalesAbiertos[modalesAbiertos.length - 1].close();
        });

        app.component('v-modal', {
            template: '#v-modal-template',

            props: [
                'isActive',
                'position',
                'size'
            ],

            emits: [
                'toggle',
                'open',
                'close',
            ],

            data() {
                return {
                    isOpen: this.isActive,

                    isMobile: window.innerWidth < 640,
                };
            },

            created() {
                window.addEventListener('resize', this.checkScreenSize);
            },

            mounted() {
                // Un modal que nace abierto (`is-active`) también va a la pila:
                // si no, Escape no lo cierra hasta que alguien lo reabra.
                if (this.isOpen) this.registrar(true);
            },

            beforeUnmount() {
                window.removeEventListener('resize', this.checkScreenSize);

                // Sin esto, un modal destruido por un cambio de ruta queda en la
                // pila y Escape intenta cerrar un componente que ya no existe.
                this.registrar(false);
            },

            computed: {
                positionClass() {
                    return {
                        'center': 'items-center justify-center',
                        'top-center': 'top-4',
                        'bottom-center': 'bottom-4',
                        'bottom-right': 'bottom-4 right-4',
                        'bottom-left': 'bottom-4 left-4',
                        'top-right': 'top-4 right-4',
                        'top-left': 'top-4 left-4',
                    }[this.position];
                },

                finalPositionClass() {
                    return this.isMobile 
                        ? 'items-center justify-center' 
                        : this.positionClass;
                },

                sizeClass() {
                    return {
                        'normal': 'max-w-[525px]',
                        'medium': 'max-w-[768px]',
                        'large': 'max-w-[950px]',
                    }[this.size] || 'max-w-[525px]';
                },

                enterFromLeaveToClasses() {
                    const effectivePosition = this.isMobile ? 'center' : this.position;
                    
                    return {
                        'center': '-translate-y-4 opacity-0',
                        'top-center': '-translate-y-4 opacity-0',
                        'bottom-center': 'translate-y-4 opacity-0',
                        'bottom-right': 'translate-y-4 opacity-0',
                        'bottom-left': 'translate-y-4 opacity-0',
                        'top-right': '-translate-y-4 opacity-0',
                        'top-left': '-translate-y-4 opacity-0',
                    }[effectivePosition];
                }
            },

            methods: {
                checkScreenSize() {
                    this.isMobile = window.innerWidth < 640;
                },
                
                /**
                 * Entra o sale de la pila y ajusta el scroll del documento.
                 *
                 * El scroll se devuelve solo cuando NO queda ningún modal
                 * abierto. Antes, cerrar el de arriba de dos lo devolvía con el
                 * de abajo todavía abierto, y la página de atrás se movía por
                 * debajo del modal.
                 */
                registrar(abierto) {
                    const i = modalesAbiertos.indexOf(this);

                    if (abierto && i === -1) {
                        modalesAbiertos.push(this);
                    } else if (! abierto && i !== -1) {
                        modalesAbiertos.splice(i, 1);
                    }

                    document.body.style.overflow = modalesAbiertos.length ? 'hidden' : 'auto';
                },

                toggle() {
                    this.isOpen = ! this.isOpen;

                    this.registrar(this.isOpen);

                    this.$emit('toggle', { isActive: this.isOpen });
                },

                open() {
                    this.isOpen = true;

                    this.registrar(true);

                    this.$emit('open', { isActive: this.isOpen });
                },

                close() {
                    this.isOpen = false;

                    this.registrar(false);

                    this.$emit('close', { isActive: this.isOpen });
                }
            }
        });
    </script>
@endPushOnce