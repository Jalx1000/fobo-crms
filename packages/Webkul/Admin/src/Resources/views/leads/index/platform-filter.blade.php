{{--
    Channel selector for the kanban board.

    A plain navigating <select> rather than Vue state, matching how the pipeline
    switcher and the view switcher already work here. The kanban's get() forwards
    every URL parameter to the endpoint, so putting the choice in the URL is all
    it takes — and it survives a reload, a bookmark and a shared link, which
    component state would not.
--}}
@php
    $channelOptions = \Webkul\Whatsapp\Support\ContactChannel::options();
    $appliedChannel = request('platform');
    $rutaPlataforma ??= 'admin.leads.index';
@endphp

<select
    class="w-max cursor-pointer appearance-none rounded-lg border bg-white px-3 py-1.5 leading-6 text-gray-600 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 {{ $appliedChannel ? 'border-brandColor text-brandColor' : '' }}"
    onchange="window.location = this.value"
>
    {{-- Every other parameter is preserved: switching channel must not silently
         reset the city or the date range the user already picked. --}}
    <option value="{{ route($rutaPlataforma, array_merge(request()->except('platform'), ['platform' => ''])) }}">
        @lang('admin::app.common.channel')
    </option>

    @foreach ($channelOptions as $option)
        <option
            value="{{ route($rutaPlataforma, array_merge(request()->except('platform'), ['platform' => $option['value']])) }}"
            @selected($appliedChannel === $option['value'])
        >
            {{ $option['label'] }}
        </option>
    @endforeach
</select>
