<x-admin::layouts>
    <x-slot:title>
        Crear Especialidad
    </x-slot>

    <x-admin::form
        :action="route('admin.specialties.store')"
        method="POST"
        encType="multipart/form-data"
    >
        <div class="flex flex-col gap-4">
            <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <div class="flex flex-col gap-2">
                    <div class="text-xl font-bold dark:text-white">
                        Crear Especialidad
                    </div>
                </div>

                <div class="flex items-center gap-x-2.5">
                    <a href="{{ route('admin.specialties.index') }}" class="secondary-button">
                        Volver al listado
                    </a>
                    <button type="submit" class="primary-button">
                        Guardar
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-4 max-xl:grid-cols-1">
                <div class="box-shadow col-span-2 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                    <div class="grid grid-cols-2 gap-4 max-lg:grid-cols-1">
                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label class="required">
                                Nombre
                            </x-admin::form.control-group.label>
                            <x-admin::form.control-group.control
                                type="text"
                                id="name"
                                name="name"
                                rules="required|min:2|max:150"
                                :label="trans('Nombre')"
                                :placeholder="trans('Nombre de la especialidad')"
                                v-debounce="500"
                            />
                            <x-admin::form.control-group.error control-name="name" />
                        </x-admin::form.control-group>

                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label class="required">
                                Código único
                            </x-admin::form.control-group.label>
                            <x-admin::form.control-group.control
                                type="text"
                                id="code"
                                name="code"
                                rules="required|max:50"
                                :label="trans('Código único')"
                                :placeholder="trans('Ej.: ORTO101')"
                                v-debounce="500"
                            />
                            <x-admin::form.control-group.error control-name="code" />
                        </x-admin::form.control-group>

                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label class="required">
                                Área de conocimiento
                            </x-admin::form.control-group.label>
                            <x-admin::form.control-group.control
                                type="text"
                                id="area_of_knowledge"
                                name="area_of_knowledge"
                                rules="required|max:100"
                                :label="trans('Área de conocimiento')"
                                :placeholder="trans('Ej.: Odontología')"
                                v-debounce="500"
                            />
                            <x-admin::form.control-group.error control-name="area_of_knowledge" />
                        </x-admin::form.control-group>

                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label>
                                Nivel de dificultad
                            </x-admin::form.control-group.label>
                            <x-admin::form.control-group.control
                                type="select"
                                id="difficulty_level"
                                name="difficulty_level"
                                :label="trans('Dificultad')"
                                :placeholder="trans('Selecciona nivel')"
                            >
                                <option value="basica">Básica</option>
                                <option value="intermedia">Intermedia</option>
                                <option value="avanzada">Avanzada</option>
                            </x-admin::form.control-group.control>
                            <x-admin::form.control-group.error control-name="difficulty_level" />
                        </x-admin::form.control-group>

                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label>
                                Duración estimada (minutos)
                            </x-admin::form.control-group.label>
                            <x-admin::form.control-group.control
                                type="number"
                                id="estimated_duration_minutes"
                                name="estimated_duration_minutes"
                                rules="numeric|min:1|max:999999"
                                :label="trans('Duración estimada')"
                                :placeholder="trans('Ej.: 180')"
                            />
                            <x-admin::form.control-group.error control-name="estimated_duration_minutes" />
                        </x-admin::form.control-group>

                        <x-admin::form.control-group class="col-span-2">
                            <x-admin::form.control-group.label class="required">
                                Descripción
                            </x-admin::form.control-group.label>
                            <x-admin::form.control-group.control
                                type="textarea"
                                id="description"
                                name="description"
                                rules="required"
                                tinymce="true"
                                :label="trans('Descripción')"
                                :placeholder="trans('Describe la especialidad')"
                            />
                            <x-admin::form.control-group.error control-name="description" />
                        </x-admin::form.control-group>

                        <x-admin::form.control-group class="col-span-2">
                            <x-admin::form.control-group.label>
                                Requisitos previos
                            </x-admin::form.control-group.label>
                            <x-admin::form.control-group.control
                                type="textarea"
                                id="prerequisites"
                                name="prerequisites"
                                :label="trans('Requisitos previos')"
                                :placeholder="trans('Lista de requisitos')"
                            />
                        </x-admin::form.control-group>

                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label>
                                Instructor
                            </x-admin::form.control-group.label>
                            <x-admin::form.control-group.control
                                type="select"
                                id="instructor_id"
                                name="instructor_id"
                                :label="trans('Instructor asignado')"
                                :placeholder="trans('Selecciona instructor')"
                            >
                                <option value="">-</option>
                                @foreach ($instructors as $u)
                                    <option value="{{ $u->id }}">{{ $u->name }}</option>
                                @endforeach
                            </x-admin::form.control-group.control>
                            <x-admin::form.control-group.error control-name="instructor_id" />
                        </x-admin::form.control-group>

                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label>
                                Estado de publicación
                            </x-admin::form.control-group.label>
                            <x-admin::form.control-group.control
                                type="select"
                                id="publication_status"
                                name="publication_status"
                                :label="trans('Estado de publicación')"
                            >
                                <option value="draft">Borrador</option>
                                <option value="review">Revisión</option>
                                <option value="published">Publicado</option>
                            </x-admin::form.control-group.control>
                            <x-admin::form.control-group.error control-name="publication_status" />
                        </x-admin::form.control-group>

                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label>
                                Fecha inicio
                            </x-admin::form.control-group.label>
                            <x-admin::flat-picker.date ::allow-input="false">
                                <input
                                    type="date"
                                    id="deadline_start"
                                    name="deadline_start"
                                    class="flex min-h-[39px] w-full rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 ltr:pr-8 rtl:pl-8"
                                    :placeholder="trans('Fecha inicio')"
                                />
                            </x-admin::flat-picker.date>
                            <x-admin::form.control-group.error control-name="deadline_start" />
                        </x-admin::form.control-group>

                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label>
                                Fecha fin
                            </x-admin::form.control-group.label>
                            <x-admin::flat-picker.date ::allow-input="false">
                                <input
                                    type="date"
                                    id="deadline_end"
                                    name="deadline_end"
                                    class="flex min-h-[39px] w-full rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 ltr:pr-8 rtl:pl-8"
                                    :placeholder="trans('Fecha fin')"
                                />
                            </x-admin::flat-picker.date>
                            <x-admin::form.control-group.error control-name="deadline_end" />
                        </x-admin::form.control-group>

                        <x-admin::form.control-group class="col-span-2">
                            <x-admin::form.control-group.label>
                                Imagen representativa
                            </x-admin::form.control-group.label>
                            <x-admin::form.control-group.control
                                type="file"
                                id="image"
                                name="image"
                                rules="mimes:jpeg,jpg,png,gif"
                                :label="trans('Imagen representativa')"
                            />
                            <x-admin::form.control-group.error control-name="image" />
                        </x-admin::form.control-group>

                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label>
                                Objetivos de aprendizaje
                            </x-admin::form.control-group.label>
                            <x-admin::form.control-group.control
                                type="tags"
                                id="learning_objectives"
                                name="learning_objectives"
                                :label="trans('Objetivos de aprendizaje')"
                                :placeholder="trans('Añade objetivos')"
                            />
                        </x-admin::form.control-group>

                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label>
                                Criterios de evaluación
                            </x-admin::form.control-group.label>
                            <x-admin::form.control-group.control
                                type="tags"
                                id="evaluation_criteria"
                                name="evaluation_criteria"
                                :label="trans('Criterios de evaluación')"
                                :placeholder="trans('Añade criterios')"
                            />
                        </x-admin::form.control-group>

                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label>
                                Requisitos de aprobación
                            </x-admin::form.control-group.label>
                            <x-admin::form.control-group.control
                                type="tags"
                                id="approval_requirements"
                                name="approval_requirements"
                                :label="trans('Requisitos de aprobación')"
                                :placeholder="trans('Añade requisitos')"
                            />
                        </x-admin::form.control-group>

                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label>
                                Reglas de certificación
                            </x-admin::form.control-group.label>
                            <x-admin::form.control-group.control
                                type="tags"
                                id="certification_rules"
                                name="certification_rules"
                                :label="trans('Reglas de certificación')"
                                :placeholder="trans('Añade reglas')"
                            />
                        </x-admin::form.control-group>
                    </div>

                    <div class="mt-4">
                        <v-content-structure></v-content-structure>
                    </div>
                </div>

                <div class="flex flex-col gap-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                    <div class="text-base font-semibold text-gray-800 dark:text-white">
                        Vista previa
                    </div>

                    <v-specialty-preview></v-specialty-preview>
                </div>
            </div>
        </div>
    </x-admin::form>

    @pushOnce('scripts')
        <script type="text/x-template" id="v-content-structure-template">
            <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <div class="mb-2">
                    <p class="text-base font-semibold text-gray-800 dark:text-white">
                        Contenidos (Módulos y Lecciones)
                    </p>
                    <p class="text-sm text-gray-600 dark:text-gray-300">
                        Organiza módulos y lecciones; usa editor enriquecido para el contenido.
                    </p>
                </div>

                <div class="flex flex-col gap-4">
                    <div class="flex items-end gap-2">
                        <x-admin::form.control-group class="!mb-0 w-full">
                            <x-admin::form.control-group.label>
                                Nuevo módulo
                            </x-admin::form.control-group.label>
                            <x-admin::form.control-group.control
                                type="text"
                                v-model="newModuleTitle"
                                :placeholder="trans('Ej.: Introducción')"
                            />
                        </x-admin::form.control-group>
                        <button type="button" class="primary-button" @click="addModule">
                            Agregar módulo
                        </button>
                    </div>

                    <div class="flex flex-col gap-3">
                        <div
                            class="rounded border border-gray-200 dark:border-gray-800"
                            v-for="(module, mIndex) in modules"
                        >
                            <div class="flex items-center justify-between bg-gray-50 p-2 dark:bg-gray-800">
                                <p class="font-semibold dark:text-white">@{{ module.title }}</p>
                                <div class="flex items-center gap-2">
                                    <button type="button" class="secondary-button" @click="removeModule(mIndex)">Eliminar</button>
                                </div>
                            </div>

                            <div class="p-3">
                                <div class="flex items-end gap-2">
                                    <x-admin::form.control-group class="!mb-0 w-full">
                                        <x-admin::form.control-group.label>
                                            Nueva lección
                                        </x-admin::form.control-group.label>
                                        <x-admin::form.control-group.control
                                            type="text"
                                            v-model="newLessonTitle"
                                            :placeholder="trans('Ej.: Conceptos básicos')"
                                        />
                                    </x-admin::form.control-group>
                                    <button type="button" class="secondary-button" @click="addLesson(mIndex)">
                                        Agregar lección
                                    </button>
                                </div>

                                <div class="mt-3 flex flex-col gap-3">
                                    <div class="rounded border border-gray-200 p-3 dark:border-gray-800" v-for="(lesson, lIndex) in module.lessons">
                                        <div class="flex items-center justify-between">
                                            <p class="font-medium dark:text-white">@{{ lesson.title }}</p>
                                            <button type="button" class="secondary-button" @click="removeLesson(mIndex, lIndex)">Eliminar</button>
                                        </div>

                                        <div class="mt-2">
                                            <x-admin::form.control-group.control
                                                type="textarea"
                                                tinymce="true"
                                                :label="trans('Contenido')"
                                                :placeholder="trans('Escribe el contenido')"
                                                v-model="lesson.content"
                                            />
                                        </div>

                                        <div class="mt-2">
                                            <x-admin::form.control-group.label>
                                                Recursos multimedia (URLs)
                                            </x-admin::form.control-group.label>
                                            <x-admin::form.control-group.control
                                                type="tags"
                                                :label="trans('Recursos multimedia')"
                                                :placeholder="trans('Añade URLs de videos/PDFs/presentaciones')"
                                                v-model="lesson.resources"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <input type="hidden" name="content_structure" :value="jsonValue">
                </div>
            </div>
        </script>

        <script type="text/x-template" id="v-specialty-preview-template">
            <div class="rounded border border-gray-200 p-3 dark:border-gray-800">
                <div class="mb-2">
                    <p class="text-lg font-bold text-gray-800 dark:text-white">@{{ form.name || '-' }}</p>
                    <p class="text-sm text-gray-600 dark:text-gray-300">@{{ form.code ? ('Código: ' + form.code) : '' }}</p>
                    <p class="text-sm text-gray-600 dark:text-gray-300">@{{ form.area_of_knowledge ? ('Área: ' + form.area_of_knowledge) : '' }}</p>
                    <p class="text-sm text-gray-600 dark:text-gray-300">@{{ difficultyLabel }}</p>
                </div>

                <div v-if="form.description" class="prose max-w-none dark:prose-invert" v-html="form.description"></div>

                <div class="mt-3">
                    <p class="font-semibold dark:text-white">Módulos</p>
                    <ul class="list-disc px-5">
                        <li v-for="m in modules" class="dark:text-gray-300">@{{ m.title }} ( @{{ m.lessons.length }} lecciones )</li>
                    </ul>
                </div>
            </div>
        </script>

        <script type="module">
            app.component('v-content-structure', {
                template: '#v-content-structure-template',

                data() {
                    return {
                        newModuleTitle: '',
                        newLessonTitle: '',
                        modules: [],
                    };
                },

                computed: {
                    jsonValue() {
                        return JSON.stringify(this.modules.map((m, idx) => ({
                            order: idx + 1,
                            title: m.title,
                            lessons: (m.lessons || []).map((l, j) => ({
                                order: j + 1,
                                title: l.title,
                                content: l.content || '',
                                resources: l.resources || [],
                            })),
                        })));
                    }
                },

                methods: {
                    addModule() {
                        const title = (this.newModuleTitle || '').trim();
                        if (! title) return;
                        this.modules.push({ title, lessons: [] });
                        this.newModuleTitle = '';
                    },
                    removeModule(index) {
                        this.modules.splice(index, 1);
                    },
                    addLesson(moduleIndex) {
                        const title = (this.newLessonTitle || '').trim();
                        if (! title) return;
                        const module = this.modules[moduleIndex];
                        if (! module.lessons) module.lessons = [];
                        module.lessons.push({ title, content: '', resources: [] });
                        this.newLessonTitle = '';
                    },
                    removeLesson(moduleIndex, lessonIndex) {
                        const module = this.modules[moduleIndex];
                        module.lessons.splice(lessonIndex, 1);
                    }
                }
            });

            app.component('v-specialty-preview', {
                template: '#v-specialty-preview-template',

                data() {
                    return {
                        form: {
                            name: '',
                            code: '',
                            area_of_knowledge: '',
                            description: '',
                            difficulty_level: '',
                        },
                        modules: [],
                    };
                },

                computed: {
                    difficultyLabel() {
                        switch (this.form.difficulty_level) {
                            case 'basica': return 'Dificultad: Básica';
                            case 'intermedia': return 'Dificultad: Intermedia';
                            case 'avanzada': return 'Dificultad: Avanzada';
                        }
                        return '';
                    }
                },

                mounted() {
                    const fields = ['name', 'code', 'area_of_knowledge', 'difficulty_level'];
                    fields.forEach(f => {
                        const el = document.getElementById(f);
                        if (el) {
                            el.addEventListener('input', () => this.form[f] = el.value);
                            this.form[f] = el.value;
                        }
                    });

                    const desc = document.getElementById('description');
                    if (desc) {
                        const updateDesc = () => this.form.description = desc.value;
                        desc.addEventListener('input', updateDesc);
                        this.form.description = desc.value;
                    }

                    const contentComp = app._context.components['v-content-structure']?.instances?.[0];
                    const observer = new MutationObserver(() => {
                        try {
                            const input = document.querySelector('input[name="content_structure"]');
                            this.modules = input?.value ? JSON.parse(input.value) : [];
                        } catch (e) {}
                    });
                    const hidden = document.querySelector('input[name="content_structure"]');
                    if (hidden) {
                        observer.observe(hidden, { attributes: true, attributeFilter: ['value'] });
                    }
                }
            });
        </script>
    @endPushOnce
</x-admin::layouts>
