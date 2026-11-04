<x-admin::layouts>
    <x-slot:title>
        Editar Especialidad
    </x-slot>

    <x-admin::form
        :action="route('admin.specialties.update', $specialty->id)"
        method="PUT"
        encType="multipart/form-data"
    >
        <div class="flex flex-col gap-4">
            <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <div class="flex flex-col gap-2">
                    <div class="text-xl font-bold dark:text-white">
                        Editar Especialidad
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
                                :value="old('name', $specialty->name)"
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
                                :value="old('code', $specialty->code)"
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
                                :value="old('area_of_knowledge', $specialty->area_of_knowledge)"
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
                                @php $dl = old('difficulty_level', $specialty->difficulty_level); @endphp
                                <option value="basica" {{ $dl === 'basica' ? 'selected' : '' }}>Básica</option>
                                <option value="intermedia" {{ $dl === 'intermedia' ? 'selected' : '' }}>Intermedia</option>
                                <option value="avanzada" {{ $dl === 'avanzada' ? 'selected' : '' }}>Avanzada</option>
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
                                :value="old('estimated_duration_minutes', $specialty->estimated_duration_minutes)"
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
                                :value="old('description', $specialty->description)"
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
                                :value="old('prerequisites', $specialty->prerequisites)"
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
                                @php $inst = old('instructor_id', $specialty->instructor_id); @endphp
                                @foreach ($instructors as $u)
                                    <option value="{{ $u->id }}" {{ $inst == $u->id ? 'selected' : '' }}>{{ $u->name }}</option>
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
                                @php $ps = old('publication_status', $specialty->publication_status); @endphp
                                <option value="draft" {{ $ps === 'draft' ? 'selected' : '' }}>Borrador</option>
                                <option value="review" {{ $ps === 'review' ? 'selected' : '' }}>Revisión</option>
                                <option value="published" {{ $ps === 'published' ? 'selected' : '' }}>Publicado</option>
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
                                    value="{{ old('deadline_start', $specialty->deadline_start?->format('Y-m-d')) }}"
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
                                    value="{{ old('deadline_end', $specialty->deadline_end?->format('Y-m-d')) }}"
                                />
                            </x-admin::flat-picker.date>
                            <x-admin::form.control-group.error control-name="deadline_end" />
                        </x-admin::form.control-group>

                        <x-admin::form.control-group class="col-span-2">
                            <x-admin::form.control-group.label>
                                Imagen representativa
                            </x-admin::form.control-group.label>

                            @if ($specialty->image_path)
                                <div class="mb-2">
                                    <img src="{{ \Illuminate\Support\Facades\Storage::url($specialty->image_path) }}" alt="Imagen" class="h-24 rounded object-cover">
                                </div>
                                <x-admin::form.control-group class="!mb-2 flex items-center gap-2">
                                    <x-admin::form.control-group.control
                                        type="checkbox"
                                        id="image_delete"
                                        name="image_delete"
                                        value="1"
                                        :for="'image_delete'"
                                    />
                                    <label for="image_delete" class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                                        Eliminar imagen
                                    </label>
                                </x-admin::form.control-group>
                            @endif

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
                                :value="old('learning_objectives', $specialty->learning_objectives ?? [])"
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
                                :value="old('evaluation_criteria', $specialty->evaluation_criteria ?? [])"
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
                                :value="old('approval_requirements', $specialty->approval_requirements ?? [])"
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
                                :value="old('certification_rules', $specialty->certification_rules ?? [])"
                            />
                        </x-admin::form.control-group>
                    </div>

                    <div class="mt-4">
                        <v-content-structure-edit :initial="JSON.stringify({!! json_encode($specialty->content_structure ?? []) !!})"></v-content-structure-edit>
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
        <script type="text/x-template" id="v-content-structure-edit-template">
            <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <div class="mb-2">
                    <p class="text-base font-semibold text-gray-800 dark:text-white">
                        Contenidos (Módulos y Lecciones)
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

        <script type="module">
            app.component('v-content-structure-edit', {
                template: '#v-content-structure-edit-template',
                props: {
                    initial: {
                        type: String,
                        default: '[]'
                    }
                },
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
                mounted() {
                    try {
                        this.modules = JSON.parse(this.initial || '[]');
                    } catch (e) {
                        this.modules = [];
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
        </script>
    @endPushOnce
</x-admin::layouts>
