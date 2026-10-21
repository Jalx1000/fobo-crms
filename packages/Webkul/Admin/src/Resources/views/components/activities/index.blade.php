@props([
    'endpoint',
    'emailDetachEndpoint' => null,
    'activeType'          => 'all',
    'types'               => null,
    'extraTypes'          => null,
])

{!! view_render_event('admin.components.activities.before') !!}

<!-- Lead Activities Vue Component -->
<v-activities
    endpoint="{{ $endpoint }}"
    email-detach-endpoint="{{ $emailDetachEndpoint }}"
    active-type="{{ $activeType }}"
    @if($types):types='@json($types)'@endif
    @if($extraTypes):extra-types='@json($extraTypes)'@endif
    ref="activities"
>
    <!-- Shimmer -->
    <x-admin::shimmer.activities />

    @foreach ($extraTypes ?? [] as $type)
        <template v-slot:{{ $type['name'] }}>
            {!! ${$type['name']} ?? '' !!}
        </template>
    @endforeach
</v-activities>

{!! view_render_event('admin.components.activities.after') !!}

@pushOnce('scripts')
    <script type="text/x-template" id="v-activities-template">
        <template v-if="isLoading">
            <!-- Shimmer -->
            <x-admin::shimmer.activities />
        </template>

        <template v-else>
            {!! view_render_event('admin.components.activities.content.before') !!}

            <div class="w-full rounded-md border border-gray-200 bg-white max-lg:rounded-none max-lg:border-x-0 dark:border-gray-800 dark:bg-gray-900">
                {{-- Celular: pestañas en una línea que se desliza, con altura táctil. --}}
                <div
                    ref="pestanas"
                    class="flex gap-2 overflow-x-auto border-b border-gray-200 max-lg:gap-0 max-lg:px-1 dark:border-gray-800"
                >
                    {!! view_render_event('admin.components.activities.content.types.before') !!}

                    <div
                        v-for="type in types"
                        class="shrink-0 cursor-pointer whitespace-nowrap px-3 py-2.5 text-sm font-medium max-lg:py-3 dark:text-white"
                        :class="{'border-brandColor border-b-2 !text-brandColor transition': selectedType == type.name }"
                        :data-activa="selectedType == type.name ? 'si' : null"
                        @click="selectedType = type.name"
                    >
                        @{{ type.label }}
                    </div>

                    {!! view_render_event('admin.components.activities.content.types.after') !!}
                </div>

                {{-- <template v-if="selectedType === 'calendar'">
                    <div class="p-4">
                        <div class="rounded-lg border border-gray-200 dark:border-gray-800">
                            <v-doctor-week-calendar></v-doctor-week-calendar>
                        </div>
                    </div>
                </template> --}}

                <!-- Show Default Activities only when not calendar -->
                <template v-if="selectedType !== 'calendar' && ! extraTypes.find(type => type.name == selectedType)">
                    <div class="animate-[on-fade_0.5s_ease-in-out] p-4">
                        {!! view_render_event('admin.components.activities.content.activity.list.before') !!}

                        <!-- Activity List -->
                        <div class="flex flex-col gap-4">
                            {!! view_render_event('admin.components.activities.content.activity.item.before') !!}

                            <!-- Activity Item -->
                            <div
                                class="flex gap-2"
                                v-for="(activity, index) in filteredActivities"
                            >
                                {!! view_render_event('admin.components.activities.content.activity.item.icon.before') !!}

                                <!-- Activity Icon -->
                                <div
                                    class="mt-2 flex h-9 min-h-9 w-9 min-w-9 items-center justify-center rounded-full text-xl"
                                    :class="typeClasses[activity.type] ?? typeClasses['default']"
                                >
                                </div>

                                {!! view_render_event('admin.components.activities.content.activity.item.icon.after') !!}

                                {!! view_render_event('admin.components.activities.content.activity.item.details.before') !!}

                                <!-- Activity Details -->
                                {{-- min-w-0 y corte en cualquier punto: un valor largo sin espacios (JSON de características) estiraba la ficha en el celular. --}}
                                <div
                                    class="flex w-full min-w-0 justify-between gap-4 rounded-md p-4"
                                    :class="{'bg-gray-100 dark:bg-gray-950': index % 2 != 0 }"
                                >
                                    <div class="flex min-w-0 flex-col gap-2 [overflow-wrap:anywhere]">
                                        {!! view_render_event('admin.components.activities.content.activity.item.title.before') !!}

                                        <!-- Activity Title -->
                                        <div
                                            class="flex flex-col gap-1"
                                            v-if="activity.title"
                                        >
                                            <p class="flex flex-wrap items-center gap-1 font-medium dark:text-white">
                                                @{{ activity.title }}

                                                <template v-if="activity.type == 'system' && activity.additional">
                                                    {{-- flex-wrap: un valor largo baja de renglón en vez de aplastar al otro («Vac/ío»). --}}
                                                    <p class="flex flex-wrap items-center gap-1">
                                                        <span>:</span>

                                                        <span class="[overflow-wrap:anywhere]">
                                                            @{{ (activity.additional.old.label ? String(activity.additional.old.label).replaceAll('<br>', ' ') : "@lang('admin::app.components.activities.index.empty')") }}
                                                        </span>

                                                        <span class="icon-stats-up rotate-90 text-xl"></span>

                                                        <span class="[overflow-wrap:anywhere]">
                                                            @{{ (activity.additional.new.label ? String(activity.additional.new.label).replaceAll('<br>', ' ') : "@lang('admin::app.components.activities.index.empty')") }}
                                                        </span>
                                                    </p>
                                                </template>
                                            </p>

                                            <template v-if="activity.type == 'email'">
                                                <p class="dark:text-white">
                                                    @lang('admin::app.components.activities.index.from'):

                                                    @{{ activity.additional.from }}
                                                </p>

                                                <p class="dark:text-white">
                                                    @lang('admin::app.components.activities.index.to'):

                                                    @{{ activity.additional.to.join(', ') }}
                                                </p>

                                                <p
                                                    v-if="activity.additional.cc"
                                                    class="dark:text-white"
                                                >
                                                    @lang('admin::app.components.activities.index.cc'):

                                                    @{{ activity.additional.cc.join(', ') }}
                                                </p>

                                                <p
                                                    v-if="activity.additional.bcc"
                                                    class="dark:text-white"
                                                >
                                                    @lang('admin::app.components.activities.index.bcc'):

                                                    @{{ activity.additional.bcc.join(', ') }}
                                                </p>
                                            </template>

                                            <template v-else>
                                                <!-- Activity Schedule -->
                                                <p
                                                    v-if="activity.schedule_from && activity.schedule_from"
                                                    class="dark:text-white"
                                                >
                                                    @lang('admin::app.components.activities.index.scheduled-on'):

                                                    @{{ $admin.formatDate(activity.schedule_from, 'd MMM yyyy, h:mm A', timezone) + ' - ' + $admin.formatDate(activity.schedule_to, 'd MMM yyyy, h:mm A', timezone) }}
                                                </p>

                                                <!-- Activity Participants -->
                                                <p
                                                    v-if="activity.participants?.length || activity.doctor_name"
                                                    class="dark:text-white"
                                                >
                                                    @lang('admin::app.components.activities.index.participants'):

                                                    <template v-if="activity.doctor_name">
                                                        <span class="after:content-[',_'] last:after:content-['']">
                                                            @{{ activity.doctor_name }}
                                                        </span>
                                                    </template>

                                                    <template v-for="(participant, index) in activity.participants">
                                                        <span class="after:content-[',_'] last:after:content-['']">
                                                            @{{ participant.user?.name ?? participant.person.name }}
                                                        </span>
                                                    </template>
                                                </p>

                                                <!-- Activity Location -->
                                                <p
                                                    v-if="activity.location"
                                                    class="dark:text-white"
                                                >
                                                    @lang('admin::app.components.activities.index.location'):

                                                    @{{ activity.location }}
                                                </p>
                                            </template>
                                        </div>

                                        {!! view_render_event('admin.components.activities.content.activity.item.title.after') !!}

                                        {!! view_render_event('admin.components.activities.content.activity.item.description.before') !!}

                                        <!-- Activity Description -->
                                        <p
                                            class="dark:text-white"
                                            v-if="activity.comment"
                                            v-safe-html="activity.comment"
                                        ></p>

                                        {!! view_render_event('admin.components.activities.content.activity.item.description.after') !!}

                                        {!! view_render_event('admin.components.activities.content.activity.item.attachments.before') !!}

                                        <!-- Attachments -->
                                        <div
                                            class="flex flex-wrap gap-2"
                                            v-if="activity.files.length"
                                        >
                                            <a
                                                :href="
                                                    activity.type == 'email'
                                                    ? `{{ route('admin.mail.attachment_download', 'replaceID') }}`.replace('replaceID', file.id)
                                                    : `{{ route('admin.activities.file_download', 'replaceID') }}`.replace('replaceID', file.id)
                                                "
                                                class="flex cursor-pointer items-center gap-1 rounded-md p-1.5"
                                                target="_blank"
                                                v-for="(file, index) in activity.files"
                                            >
                                                <span class="icon-attached-file text-xl"></span>

                                                <span class="font-medium text-brandColor">
                                                    @{{ file.name }}
                                                </span>
                                            </a>
                                        </div>

                                        {!! view_render_event('admin.components.activities.content.activity.item.attachments.after') !!}

                                        {!! view_render_event('admin.components.activities.content.activity.item.time_and_user.before') !!}

                                        <!-- Activity Time and User -->
                                        <div class="text-gray-500 dark:text-gray-300">
                                            @{{ $admin.formatDate(activity.created_at, 'd MMM yyyy, h:mm A', timezone) }},

                                            @{{ "@lang('admin::app.components.activities.index.by-user', ['user' => 'replace'])".replace('replace', activity.user?.name ?? '@lang('admin::app.components.activities.index.system')') }}
                                        </div>

                                        {!! view_render_event('admin.components.activities.content.activity.item.time_and_user.after') !!}
                                    </div>

                                    {!! view_render_event('admin.components.activities.content.activity.item.more_actions.before') !!}

                                    <!-- Activity More Options -->
                                    <template v-if="activity.type != 'system'">
                                        {!! view_render_event('admin.components.activities.content.activity.item.more_actions.dropdown.after') !!}

                                        <x-admin::dropdown position="bottom-{{ in_array(app()->getLocale(), ['fa', 'ar']) ? 'left' : 'right' }}">
                                            <x-slot:toggle>
                                                {!! view_render_event('admin.components.activities.content.activity.item.more_actions.dropdown.toggle.before') !!}

                                                <template v-if="! isUpdating[activity.id]">
                                                    <button
                                                        class="icon-more flex h-7 w-7 cursor-pointer items-center justify-center rounded-md text-2xl transition-all hover:bg-gray-200 dark:hover:bg-gray-800"
                                                    ></button>
                                                </template>

                                                <template v-else>
                                                    <x-admin::spinner />
                                                </template>

                                                {!! view_render_event('admin.components.activities.content.activity.item.more_actions.dropdown.toggle.after') !!}
                                            </x-slot>

                                            <x-slot:menu class="!min-w-40">
                                                {!! view_render_event('admin.components.activities.content.activity.item.more_actions.dropdown.menu_item.before') !!}

                                                <template v-if="activity.type != 'email'">
                                                    @if (bouncer()->hasPermission('activities.edit'))
                                                        <x-admin::dropdown.menu.item
                                                            v-if="! activity.is_done && ['call', 'meeting', 'lunch'].includes(activity.type)"
                                                            @click="markAsDone(activity)"
                                                        >
                                                            <div class="flex items-center gap-2">
                                                                <span class="icon-tick text-2xl"></span>

                                                                @lang('admin::app.components.activities.index.mark-as-done')
                                                            </div>
                                                        </x-admin::dropdown.menu.item>

                                                        <x-admin::dropdown.menu.item v-if="['call', 'meeting', 'lunch'].includes(activity.type)">
                                                            <a
                                                                class="flex items-center gap-2"
                                                                :href="'{{ route('admin.activities.edit', 'replaceId') }}'.replace('replaceId', activity.id)"
                                                                target="_blank"
                                                            >
                                                                <span class="icon-edit text-2xl"></span>

                                                                @lang('admin::app.components.activities.index.edit')
                                                            </a>
                                                        </x-admin::dropdown.menu.item>
                                                    @endif

                                                    @if (bouncer()->hasPermission('activities.delete'))
                                                        <x-admin::dropdown.menu.item @click="remove(activity)">
                                                            <div class="flex items-center gap-2">
                                                                <span class="icon-delete text-2xl"></span>

                                                                @lang('admin::app.components.activities.index.delete')
                                                            </div>
                                                        </x-admin::dropdown.menu.item>
                                                    @endif
                                                </template>

                                                <template v-else>
                                                    @if (bouncer()->hasPermission('mail.view'))
                                                        <x-admin::dropdown.menu.item>
                                                            <a
                                                                :href="'{{ route('admin.mail.view', ['route' => 'replaceFolder', 'id' => 'replaceMailId']) }}'.replace('replaceFolder', activity.additional.folders[0]).replace('replaceMailId', activity.id)"
                                                                class="flex items-center gap-2"
                                                                target="_blank"
                                                            >
                                                                <span class="icon-eye text-2xl"></span>

                                                                @lang('admin::app.components.activities.index.view')
                                                            </a>
                                                        </x-admin::dropdown.menu.item>
                                                    @endif

                                                    <x-admin::dropdown.menu.item @click="unlinkEmail(activity)">
                                                        <div class="flex items-center gap-2">
                                                            <span class="icon-attachment text-2xl"></span>

                                                            @lang('admin::app.components.activities.index.unlink')
                                                        </div>
                                                    </x-admin::dropdown.menu.item>
                                                </template>

                                                {!! view_render_event('admin.components.activities.content.activity.item.more_actions.dropdown.menu_item.after') !!}
                                            </x-slot>
                                        </x-admin::dropdown>

                                        {!! view_render_event('admin.components.activities.content.activity.item.more_actions.dropdown.after') !!}
                                    </template>

                                    {!! view_render_event('admin.components.activities.content.activity.item.more_actions.after') !!}
                                </div>

                                {!! view_render_event('admin.components.activities.content.activity.item.details.after') !!}
                            </div>

                            {!! view_render_event('admin.components.activities.content.activity.item.after') !!}

                            <!-- Empty Placeholder -->
                            <div
                                class="grid justify-center justify-items-center gap-3.5 py-12 min-h-[100vh]"
                                v-if="! filteredActivities.length"
                            >
                                <img
                                    class="dark:mix-blend-exclusion dark:invert"
                                    :src="typeIllustrations[selectedType]?.image ?? typeIllustrations['all'].image"
                                >

                                <div class="flex flex-col items-center gap-2">
                                    <p class="text-xl font-semibold dark:text-white">
                                        @{{ typeIllustrations[selectedType]?.title ?? typeIllustrations['all'].title }}
                                    </p>

                                    <p class="text-gray-400 dark:text-gray-400">
                                        @{{ typeIllustrations[selectedType]?.description ?? typeIllustrations['all'].description }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        {!! view_render_event('admin.components.activities.content.activity.list.after') !!}
                    </div>
                </template>

                <template v-else-if="selectedType !== 'calendar'">
                    <template v-for="type in extraTypes">
                        {!! view_render_event('admin.components.activities.content.activity.extra_types.before') !!}

                        <div
                            v-show="selectedType == type.name"
                            class="flex min-h-[100vh] flex-col max-lg:min-h-0"
                        >
                            <slot :name="type.name"></slot>
                        </div>

                        {!! view_render_event('admin.components.activities.content.activity.extra_types.after') !!}
                    </template>
                </template>
            </div>

            {!! view_render_event('admin.components.activities.content.after') !!}
        </template>
    </script>

        <script type="text/x-template" id="v-doctor-multiselect-template">
            <div class="ms-container" ref="root">
                <div class="ms-input" @click="open=!open">
                    <div class="ms-chips">
                        <span class="ms-chip" v-for="sid in model" :key="'chip-'+sid">
                            @{{ nameById(sid) }}
                            <i class="icon-cross-large ms-chip-x" @click.stop="remove(sid)"></i>
                        </span>
                    </div>
                    <div class="ms-actions">
                        <span class="ms-count">@{{ model.length }}</span>
                        <i :class="open?'icon-up-arrow':'icon-down-arrow'" class="text-xl"></i>
                    </div>
                </div>
                <div v-if="open" class="ms-dropdown">
                    <input type="text" v-model="q" class="ms-search" placeholder="Buscar" />
                    <ul class="ms-list">
                        <li v-for="d in filtered" :key="'li-'+d.id" class="ms-item" @click="toggle(String(d.id))">
                            <input type="checkbox" :checked="set.has(String(d.id))" />
                            <span>@{{ d.name }}</span>
                        </li>
                        <li v-if="!filtered.length" class="ms-empty">Sin resultados</li>
                    </ul>
                </div>
            </div>
        </script>

        <script type="module">
            app.component('v-doctor-multiselect', {
                template: '#v-doctor-multiselect-template',
                props: {
                    items: { type: Array, default: () => [] },
                    modelValue: { type: Array, default: () => [] },
                },
                emits: ['update:modelValue'],
                data(){return{open:false,q:''}},
                computed:{
                    model(){return this.modelValue},
                    set(){return new Set(this.model)},
                    filtered(){
                        const q=this.q.trim().toLowerCase();

                        return q
                            ? this.items.filter(d => d && String(d.name).toLowerCase().includes(q))
                            : this.items.filter(d => d);
                    }
                },
                methods:{
                    nameById(id){
                        const d=this.items.find(x => x && String(x.id) === String(id));

                        return d && d.name ? d.name : '';
                    },
                    toggle(id){
                        const next=new Set(this.model);
                        if(next.has(id)){next.delete(id)}else{next.add(id)}
                        this.$emit('update:modelValue', Array.from(next));
                    },
                    remove(id){
                        const next=this.model.filter(x=>String(x)!==String(id));
                        this.$emit('update:modelValue', next);
                    },
                    onClickOutside(e){
                        const root=this.$refs.root;
                        if(root && !root.contains(e.target)) this.open=false;
                    }
                },
                mounted(){window.addEventListener('click', this.onClickOutside)},
                beforeUnmount(){window.removeEventListener('click', this.onClickOutside)}
            });
        </script>

        <style>
            .ms-container{display:inline-block;min-width:240px;position:relative}
            .ms-input{display:flex;align-items:center;justify-content:space-between;border:1px solid var(--border-color,#e5e7eb);border-radius:8px;padding:6px;gap:6px;background:var(--hours-bg,#fff)}
            .dark .ms-input{border-color:#1f2937;background:#0b0f19}
            .ms-chips{display:flex;flex-wrap:wrap;gap:6px;max-height:64px;overflow:auto}
            .ms-chip{display:inline-flex;align-items:center;gap:6px;background:#f3f4f6;border-radius:12px;padding:2px 8px;font-size:12px}
            .dark .ms-chip{background:#262b36}
            .ms-chip-x{cursor:pointer}
            .ms-actions{display:flex;align-items:center;gap:8px}
            .ms-count{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:6px;background:#f3f4f6}
            .dark .ms-count{background:#262b36}
            .ms-dropdown{position:absolute;left:0;right:0;top:calc(100% + 4px);border:1px solid var(--border-color,#e5e7eb);background:var(--hours-bg,#fff);border-radius:8px;box-shadow:0 4px 10px rgba(0,0,0,0.08);padding:8px;z-index:20}
            .dark .ms-dropdown{border-color:#1f2937;background:#0b0f19}
            .ms-search{width:100%;border:1px solid var(--border-color,#e5e7eb);border-radius:6px;padding:6px 8px;font-size:12px;margin-bottom:8px}
            .dark .ms-search{border-color:#1f2937;background:#0b0f19;color:#cbd5e1}
            .ms-list{max-height:180px;overflow:auto}
            .ms-item{display:flex;align-items:center;gap:8px;padding:6px;border-radius:6px;cursor:pointer}
            .ms-item:hover{background:#f3f4f6}
            .dark .ms-item:hover{background:#262b36}
            .ms-empty{padding:6px;color:#6b7280;font-size:12px}
        </style>
    <script type="module">
        app.component('v-activities', {
            template: '#v-activities-template',

            props: {
                endpoint: {
                    type: String,
                    default: '',
                },

                emailDetachEndpoint: {
                    type: String,
                    default: '',
                },

                activeType: {
                    type: String,
                    default: 'all',
                },

                types: {
                    type: Array,
                    default: [
                        {
                            name: 'all',
                            label: "{{ trans('admin::app.components.activities.index.all') }}",
                        }, {
                            name: 'note',
                            label: "{{ trans('admin::app.components.activities.index.notes') }}",
                        }, {
                            name: 'file',
                            label: "{{ trans('admin::app.components.activities.index.files') }}",
                        }, {
                            name: 'system',
                            label: "{{ trans('admin::app.components.activities.index.change-log') }}",
                        }
                    ],
                },

                extraTypes: {
                    type: Array,
                    default: [],
                },
            },

            data() {
                return {
                    isLoading: false,

                    isUpdating: {},

                    activities: [],

                    selectedType: this.activeType,

                    typeClasses: {
                        email: 'icon-mail bg-green-200 text-green-900 dark:!text-green-900',
                        note: 'icon-note bg-orange-200 text-orange-800 dark:!text-orange-800',
                        call: 'icon-call bg-cyan-200 text-cyan-800 dark:!text-cyan-800',
                        meeting: 'icon-activity bg-blue-200 text-blue-800 dark:!text-blue-800',
                        lunch: 'icon-activity bg-blue-200 text-blue-800 dark:!text-blue-800',
                        file: 'icon-file bg-green-200 text-green-900 dark:!text-green-900',
                        system: 'icon-system-generate bg-yellow-200 text-yellow-900 dark:!text-yellow-900',
                        default: 'icon-activity bg-blue-200 text-blue-800 dark:!text-blue-800',
                    },

                    typeIllustrations: {
                        all: {
                            image: "{{ vite()->asset('images/empty-placeholders/activities.svg') }}",
                            title: "{{ trans('admin::app.components.activities.index.empty-placeholders.all.title') }}",
                            description: "{{ trans('admin::app.components.activities.index.empty-placeholders.all.description') }}",
                        },

                        planned: {
                            image: "{{ vite()->asset('images/empty-placeholders/plans.svg') }}",
                            title: "{{ trans('admin::app.components.activities.index.empty-placeholders.planned.title') }}",
                            description: "{{ trans('admin::app.components.activities.index.empty-placeholders.planned.description') }}",
                        },

                        note: {
                            image: "{{ vite()->asset('images/empty-placeholders/notes.svg') }}",
                            title: "{{ trans('admin::app.components.activities.index.empty-placeholders.notes.title') }}",
                            description: "{{ trans('admin::app.components.activities.index.empty-placeholders.notes.description') }}",
                        },

                        call: {
                            image: "{{ vite()->asset('images/empty-placeholders/calls.svg') }}",
                            title: "{{ trans('admin::app.components.activities.index.empty-placeholders.calls.title') }}",
                            description: "{{ trans('admin::app.components.activities.index.empty-placeholders.calls.description') }}",
                        },

                        meeting: {
                            image: "{{ vite()->asset('images/empty-placeholders/meetings.svg') }}",
                            title: "{{ trans('admin::app.components.activities.index.empty-placeholders.meetings.title') }}",
                            description: "{{ trans('admin::app.components.activities.index.empty-placeholders.meetings.description') }}",
                        },

                        lunch: {
                            image: "{{ vite()->asset('images/empty-placeholders/lunches.svg') }}",
                            title: "{{ trans('admin::app.components.activities.index.empty-placeholders.lunches.title') }}",
                            description: "{{ trans('admin::app.components.activities.index.empty-placeholders.lunches.description') }}",
                        },

                        file: {
                            image: "{{ vite()->asset('images/empty-placeholders/files.svg') }}",
                            title: "{{ trans('admin::app.components.activities.index.empty-placeholders.files.title') }}",
                            description: "{{ trans('admin::app.components.activities.index.empty-placeholders.files.description') }}",
                        },

                        email: {
                            image: "{{ vite()->asset('images/empty-placeholders/emails.svg') }}",
                            title: "{{ trans('admin::app.components.activities.index.empty-placeholders.emails.title') }}",
                            description: "{{ trans('admin::app.components.activities.index.empty-placeholders.emails.description') }}",
                        },

                        system: {
                            image: "{{ vite()->asset('images/empty-placeholders/activities.svg') }}",
                            title: "{{ trans('admin::app.components.activities.index.empty-placeholders.system.title') }}",
                            description: "{{ trans('admin::app.components.activities.index.empty-placeholders.system.description') }}",
                        }
                    },

                    timezone: "{{ config('app.timezone') }}",
                }
            },

            computed: {
                filteredActivities() {
                    if (this.selectedType == 'all') {
                        return this.activities;
                    } else if (this.selectedType == 'planned') {
                        return this.activities.filter(activity => ! activity.is_done);
                    }

                    return this.activities.filter(activity => activity.type == this.selectedType);
                }
            },

            mounted() {
                this.get();

                if (this.extraTypes?.length) {
                    this.extraTypes.forEach(type => {
                        this.types.push(type);
                    });
                }

                /* if (! this.types.find(t => t.name === 'calendar')) {
                    this.types.push({
                        name: 'calendar',
                        label: 'Calendario',
                    });
                } */

                this.selectedType = 'all';

                this.$emitter.on('on-activity-added', (activity) => this.activities.unshift(activity));
            },

            methods: {
                /**
                 * Celular: la fila de pestañas se desliza hasta la abierta
                 * (WhatsApp queda a la derecha y no se veía cuál estaba activa).
                 */
                mostrarPestanaActiva() {
                    const fila = this.$refs.pestanas;

                    const activa = fila?.querySelector('[data-activa]');

                    if (fila && activa && fila.scrollWidth > fila.clientWidth) {
                        fila.scrollLeft = activa.offsetLeft - fila.offsetLeft - 12;
                    }
                },

                get() {
                    this.isLoading = true;

                    this.$axios.get(this.endpoint)
                        .then(response => {
                            this.activities = response.data.data;

                            this.isLoading = false;

                            this.$nextTick(() => this.mostrarPestanaActiva());
                        })
                        .catch(error => {
                            console.error(error);
                        });
                },

                markAsDone(activity) {
                    this.$emitter.emit('open-confirm-modal', {
                        agree: () => {
                            this.isUpdating[activity.id] = true;

                            this.$axios.put("{{ route('admin.activities.update', 'replaceId') }}".replace('replaceId', activity.id), {
                                    'is_done': 1
                                })
                                .then((response) => {
                                    this.isUpdating[activity.id] = false;

                                    activity.is_done = 1;

                                    this.$emitter.emit('add-flash', { type: 'success', message: response.data.message });
                                })
                                .catch((error) => {
                                    this.isUpdating[activity.id] = false;

                                    this.$emitter.emit('add-flash', { type: 'error', message: error.response.data.message });
                                });
                        },
                    });
                },

                remove(activity) {
                    this.$emitter.emit('open-confirm-modal', {
                        agree: () => {
                            this.isUpdating[activity.id] = true;

                            this.$axios.delete("{{ route('admin.activities.delete', 'replaceId') }}".replace('replaceId', activity.id))
                                .then((response) => {
                                    this.isUpdating[activity.id] = false;

                                    this.activities.splice(this.activities.indexOf(activity), 1);

                                    this.$emitter.emit('add-flash', { type: 'success', message: response.data.message });
                                })
                                .catch((error) => {
                                    this.isUpdating[activity.id] = false;

                                    this.$emitter.emit('add-flash', { type: 'error', message: error.response.data.message });
                                });
                        },
                    });
                },

                unlinkEmail(activity) {
                    this.$emitter.emit('open-confirm-modal', {
                        agree: () => {
                            let emailId = activity.parent_id ?? activity.id;

                            this.$axios.delete(this.emailDetachEndpoint, {
                                    data: {
                                        email_id: emailId,
                                    }
                                })
                                .then((response) => {
                                    let relatedActivities = this.activities.filter(activity => activity.parent_id == emailId || activity.id == emailId);

                                    relatedActivities.forEach(activity => {
                                        const index = this.activities.findIndex(a => a === activity);

                                        if (index !== -1) {
                                            this.activities.splice(index, 1);
                                        }
                                    });

                                    this.$emitter.emit('add-flash', { type: 'success', message: response.data.message });
                                })
                                .catch((error) => {
                                    this.$emitter.emit('add-flash', { type: 'error', message: error.response.data.message });
                                });
                        }
                    });
                },
            },
        });
    </script>
@endPushOnce

@pushOnce('styles')
<style>
.dwc-container{display:flex;flex-direction:column;gap:8px;width:100%}
.dwc-controls{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px;border:1px solid var(--border-color);border-radius:8px;background:var(--controls-bg)}
.dwc-filters{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.dwc-grid{display:grid;gap:0;border:1px solid var(--border-color);border-radius:8px;overflow:hidden;background:var(--events-bg)}
.dwc-hours{border-right:1px solid var(--border-color);background:var(--hours-bg)}
.dwc-hour-row{display:flex;align-items:center;justify-content:flex-end;padding-right:8px;height:var(--hour-height);font-size:12px;color:var(--hour-text)}
.dwc-doctor-col{border-right:1px solid var(--border-color)}
.dwc-doctor-header{display:flex;align-items:center;justify-content:space-between;padding:8px;font-size:12px;color:var(--day-text);border-bottom:1px solid var(--border-color);background:var(--hours-bg)}
.dwc-days-stack{position:relative}
.dwc-day-block{position:relative;border-bottom:1px solid var(--border-color)}
.dwc-hour-line{position:absolute;left:0;right:0;height:1px;background:var(--grid-line)}
.dwc-event{position:absolute;left:6px;right:6px;border-left:4px solid var(--event-accent);background:var(--event-bg);color:var(--event-text);border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.08);padding:6px 8px;font-size:12px}
.dwc-event-title{font-weight:600}
.dwc-event-time{font-size:11px;opacity:.85}
            .dwc-add-overlay{position:absolute;background:var(--hours-bg);border:1px solid var(--border-color);border-radius:6px;padding:8px;z-index:10}
:root{--hour-height:64px;--border-color:#e5e7eb;--grid-line:#eef2f7;--hours-bg:#fff;--events-bg:#fff;--hour-text:#6b7280;--event-bg:#f8fafc;--event-text:#111827;--event-accent:#3b82f6;--controls-bg:#fff}
.dark .dwc-grid,.dark .dwc-controls{--border-color:#1f2937;--grid-line:#101828;--hours-bg:#0b0f19;--events-bg:#0b0f19;--hour-text:#cbd5e1;--event-bg:#111827;--event-text:#f3f4f6;--event-accent:#60a5fa;--controls-bg:#0b0f19}
@media (max-width:640px){:root{--hour-height:48px}}
</style>
@endPushOnce

@pushOnce('scripts')
<script type="text/x-template" id="v-doctor-week-calendar-template">
    <div class="dwc-container">
        <div class="dwc-controls">
            <div class="flex items-center gap-2">
                <button class="px-2 py-1 rounded border dark:border-gray-800" @click="prevWeek">←</button>
                <button class="px-2 py-1 rounded border dark:border-gray-800" @click="goThisWeek">Esta semana</button>
                <button class="px-2 py-1 rounded border dark:border-gray-800" @click="nextWeek">→</button>
                <span class="text-sm font-semibold dark:text-white">@{{ weekLabel }}</span>
            </div>
                <div class="dwc-filters">
                    <span class="text-xs dark:text-gray-300">Filtrar doctores:</span>
                    <button type="button" class="px-2 py-1 rounded border text-xs dark:border-gray-800" @click="selectAllDoctors">Todos</button>
                    <button type="button" class="px-2 py-1 rounded border text-xs dark:border-gray-800" @click="clearDoctors">Ninguno</button>
                    <v-doctor-multiselect :items="doctors" v-model="selectedDoctorIds"></v-doctor-multiselect>
                </div>
        </div>

        <div class="dwc-grid" :style="{ gridTemplateColumns: '80px repeat(' + columns.length + ', 1fr)' }">
            <div class="dwc-hours">
                <div v-for="h in 24" :key="'hr-'+h" class="dwc-hour-row" :style="{ height: hourHeight + 'px' }">@{{ pad2(h-1) }}:00</div>
            </div>

            <div v-for="col in columns" :key="'col-'+col.id" class="dwc-doctor-col">
                <div class="dwc-doctor-header">
                    <span>@{{ col.name }}</span>
                    <span class="text-xs dark:text-gray-300">@{{ totalCount(col.id) }}</span>
                </div>

                <div class="dwc-days-stack" :style="{ height: totalHeight + 'px' }" @click="onColumnClick($event, col.id)">
                    <div v-for="(day, di) in days" :key="'day-'+di" class="dwc-day-block" :style="{ height: dayHeight + 'px' }">
                        <div v-for="idx in 24" :key="'line-'+di+'-'+idx" class="dwc-hour-line" :style="{ top: ((idx - 1) * hourHeight) + 'px' }"></div>

                        <div v-for="ev in dayDoctorEvents(day.date, col.id)" :key="'ev-'+ev.id" class="dwc-event" :style="{ top: ev.top + 'px', height: ev.height + 'px' }">
                            <div class="dwc-event-title">@{{ ev.title || ev.type }}</div>
                            <div class="dwc-event-time">@{{ formatTime(ev.start) }} - @{{ formatTime(ev.end) }} · @{{ day.label }}</div>
                            <div class="flex items-center gap-2 mt-1">
                                <a :href="editUrl(ev.id)" class="icon-edit text-xl"></a>
                                <button type="button" class="icon-delete text-xl text-red-600" @click.stop="remove(ev)"></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="addForm.visible" class="dwc-add-overlay" :style="{ top: addForm.top + 'px', left: addForm.left + 'px', width: overlayWidth + 'px' }">
            <div class="flex items-center gap-2 mb-2">
                <span class="text-xs dark:text-gray-300">@{{ addForm.dayLabel }}</span>
                <span class="text-xs dark:text-gray-300">·</span>
                <span class="text-xs dark:text-gray-300">@{{ addForm.doctorLabel }}</span>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <input type="text" class="rounded border px-2 py-1 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" v-model="addForm.title" placeholder="Título" />
                <select class="rounded border px-2 py-1 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" v-model="addForm.type">
                    <option value="meeting">Consulta</option>
                    <option value="call">Llamada</option>
                    <option value="lunch">Almuerzo</option>
                </select>
                <input type="time" class="rounded border px-2 py-1 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" v-model="addForm.startTime" />
                <input type="time" class="rounded border px-2 py-1 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" v-model="addForm.endTime" />
            </div>
            <div class="mt-2 flex items-center gap-2">
                <button type="button" class="secondary-button" @click="cancelAdd">Cancelar</button>
                <button type="button" class="primary-button" @click="saveAdd" :disabled="isSaving">
                    <span v-if="!isSaving">Guardar</span>
                    <span v-else class="flex items-center gap-2"><x-admin::spinner /> Guardando...</span>
                </button>
            </div>
            <div class="mt-1 text-sm text-red-600" v-if="addError">@{{ addError }}</div>
        </div>
    </div>
</script>

<script type="module">
app.component('v-doctor-week-calendar', {
    template: '#v-doctor-week-calendar-template',
    data() {
        const today = new Date();
        const start = new Date(today);
        start.setDate(today.getDate() - today.getDay());
        return {
            isLoading: false,
            isSaving: false,
            addError: '',
            hourHeight: 64,
            startISO: this.toISO(start),
            days: [],
            doctors: [],
            appointments: [],
            selectedDoctorIds: [],
            overlayWidth: 320,
            overlayHeight: 180,
            addForm: {
                visible: false,
                doctorId: null,
                doctorLabel: 'Global',
                day: '',
                dayLabel: '',
                top: 0,
                left: 0,
                title: '',
                type: 'meeting',
                startTime: '09:00',
                endTime: '10:00',
            },
            endpoint: "{{ route('admin.activities.get') }}",
            storeUrl: "{{ route('admin.activities.store') }}",
            editUrlTemplate: "{{ route('admin.activities.edit', 'replaceId') }}",
            deleteUrlTemplate: "{{ route('admin.activities.delete', 'replaceId') }}",
        };
    },
    computed: {
        minuteHeight() { return this.hourHeight / 60; },
        dayHeight() { return 24 * this.hourHeight; },
        totalHeight() { return this.days.length * this.dayHeight; },
        weekLabel() {
            if (!this.days.length) return '';
            const s = new Date(this.days[0].date);
            const e = new Date(this.days[this.days.length - 1].date);
            return `${this.formatDate(s)} - ${this.formatDate(e)}`;
        },
        columns() {
            const ids = new Set(this.selectedDoctorIds.map(id => Number(id)));
            const selected = this.selectedDoctorIds.length
                ? this.doctors.filter(d => ids.has(Number(d.id)))
                : this.doctors;
            return selected;
        },
    },
    mounted() {
        this.fetch();
    },
    methods: {
        pad2(n){return String(n).padStart(2,'0')},
        toISO(d){const yyyy=d.getFullYear(),mm=String(d.getMonth()+1).padStart(2,'0'),dd=String(d.getDate()).padStart(2,'0');return `${yyyy}-${mm}-${dd}`},
        formatDate(d){const wd=['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'][d.getDay()],dd=String(d.getDate()).padStart(2,'0'),mm=String(d.getMonth()+1).padStart(2,'0'),yyyy=d.getFullYear();return `${wd} ${dd}/${mm}/${yyyy}`},
        formatTime(dt){const t=new Date(dt);return `${this.pad2(t.getHours())}:${this.pad2(t.getMinutes())}`},
        prevWeek(){const d=new Date(this.startISO);d.setDate(d.getDate()-7);this.startISO=this.toISO(d);this.fetch()},
        nextWeek(){const d=new Date(this.startISO);d.setDate(d.getDate()+7);this.startISO=this.toISO(d);this.fetch()},
        goThisWeek(){const today=new Date();const start=new Date(today);start.setDate(today.getDate()-today.getDay());this.startISO=this.toISO(start);this.fetch()},
        fetch(){
            this.isLoading=true;
            this.$axios.get(this.endpoint,{params:{view_type:'calendar',calendar_mode:'doctor',start:this.startISO}})
                .then(r=>{
                    this.days=r.data.days;
                    this.doctors=r.data.doctors;
                    this.appointments=r.data.appointments;
                    if(!this.selectedDoctorIds.length){this.selectedDoctorIds=this.doctors.map(d=>String(d.id))}
                    this.isLoading=false;
                }).catch(()=>this.isLoading=false);
        },
        selectAllDoctors(){this.selectedDoctorIds=this.doctors.map(d=>String(d.id))},
        clearDoctors(){this.selectedDoctorIds=[]},
        dayDoctorEvents(dateStr,doctorId){
            return this.appointments.filter(a=>{
                const d=a.start.split(' ')[0];
                const matchDay=d===dateStr;
                const matchDoctor=a.doctor_id===doctorId;
                return matchDay&&matchDoctor;
            }).map(a=>{
                const dtStart=new Date(a.start),dtEnd=new Date(a.end);
                const startMin=dtStart.getHours()*60+dtStart.getMinutes();
                const endMin=dtEnd.getHours()*60+dtEnd.getMinutes();
                const top=startMin*this.minuteHeight;
                const height=Math.max((endMin-startMin)*this.minuteHeight,8);
                return {...a,top,height};
            });
        },
        totalCount(doctorId){return this.appointments.filter(a=>a.doctor_id===doctorId).length},
        onColumnClick(e,doctorId){
            const container=e.currentTarget,rect=container.getBoundingClientRect();
            const x=e.clientX-rect.left,y=e.clientY-rect.top;
            const dayIndex=Math.floor(y/this.dayHeight),day=this.days[dayIndex],within=y-(dayIndex*this.dayHeight);
            const minutes=Math.max(0,Math.min(23*60+59,Math.round(within/this.minuteHeight)));
            const h=Math.floor(minutes/60),m=minutes%60;
            this.addForm.visible=true;
            // Prefer right of click; if not enough space, place left of click
            const desiredLeft=x+8;
            const maxLeft=rect.width-this.overlayWidth-8;
            let left=desiredLeft<=maxLeft?desiredLeft:Math.max(8,x-this.overlayWidth-8);
            // Prefer above the click with small gap; if not enough space, place below with small gap
            const desiredTop=y-this.overlayHeight-8;
            const minTop=8;
            const maxTop=rect.height-this.overlayHeight-8;
            let top=desiredTop>=minTop?desiredTop:Math.min(maxTop,y+8);
            this.addForm.left=left;
            this.addForm.top=top;
            this.addForm.day=day.date;
            this.addForm.dayLabel=day.label;
            this.addForm.doctorId=doctorId;
            this.addForm.doctorLabel=(this.doctors.find(d=>d.id===doctorId)?.name||'');
            this.addForm.startTime=`${this.pad2(h)}:${this.pad2(m)}`;
            const endMins=Math.min(23*60+59,minutes+60),eh=Math.floor(endMins/60),em=endMins%60;
            this.addForm.endTime=`${this.pad2(eh)}:${this.pad2(em)}`;
            this.addForm.title='';this.addForm.type='meeting';this.addError='';
        },
        cancelAdd(){this.addForm.visible=false;this.addError=''},
        saveAdd(){
            this.isSaving=true;
            const start=`${this.addForm.day} ${this.addForm.startTime}`,end=`${this.addForm.day} ${this.addForm.endTime}`;
            const payload={type:this.addForm.type,title:this.addForm.title,schedule_from:start,schedule_to:end};
            if(this.addForm.doctorId){payload['participants']={doctors:[this.addForm.doctorId]}}
            this.$axios.post(this.storeUrl,payload).then(()=>{this.isSaving=false;this.addForm.visible=false;this.fetch()})
                .catch(err=>{this.isSaving=false;this.addError=err?.response?.data?.message||'Error al guardar'});
        },
        editUrl(id){return this.editUrlTemplate.replace('replaceId',id)},
        remove(ev){this.$axios.delete(this.deleteUrlTemplate.replace('replaceId',ev.id)).then(()=>this.fetch()).catch(()=>{})},
    },
});
</script>
@endPushOnce
