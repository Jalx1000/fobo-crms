<!DOCTYPE html>

<html
    class="{{ request()->cookie('dark_mode') ? 'dark' : '' }}"
    lang="{{ app()->getLocale() }}"
    dir="{{ in_array(app()->getLocale(), ['fa', 'ar']) ? 'rtl' : 'ltr' }}"
>

<head>

    {!! view_render_event('admin.layout.head.before') !!}

    <title>{{ $title ?? '' }}</title>

    <meta charset="UTF-8">

    <meta
        http-equiv="X-UA-Compatible"
        content="IE=edge"
    >
    <meta
        http-equiv="content-language"
        content="{{ app()->getLocale() }}"
    >

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1, viewport-fit=cover"
    >
    <meta
        name="base-url"
        content="{{ url()->to('/') }}"
    >
    <meta
        name="currency"
        content="{{
            json_encode([
                'code'   => config('app.currency'),
                'symbol' => core()->currencySymbol(config('app.currency'))])
            }}
        "
    >

    @stack('meta')

    {{
        vite()->set(['src/Resources/assets/css/app.css', 'src/Resources/assets/js/app.js'])
    }}

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@100..900&display=swap"
        rel="stylesheet"
    />

    <link
        rel="preload"
        as="image"
        href="{{ url('cache/logo/bagisto.png') }}"
    >

    @if ($favicon = core()->getConfigData('general.design.admin_logo.favicon'))
        <link
            type="image/x-icon"
            href="{{ Storage::url($favicon) }}"
            rel="shortcut icon"
            sizes="16x16"
        >
    @else
        <link
            type="image/x-icon"
            href="{{ vite()->asset('images/favicon.ico') }}"
            rel="shortcut icon"
            sizes="16x16"
        />
    @endif

    @php
        $brandColor = core()->getConfigData('general.settings.menu_color.brand_color') ?? '#0E90D9';
    @endphp

    @stack('styles')

    <style>
        :root {
            --brand-color: {{ $brandColor }};
        }

        {!! core()->getConfigData('general.content.custom_scripts.custom_css') !!}
    </style>

    {!! view_render_event('admin.layout.head.after') !!}
</head>

<body class="h-full font-inter dark:bg-gray-950">
    {!! view_render_event('admin.layout.body.before') !!}

    <div
        id="app"
        class="h-full"
    >
        {{-- #region agent log --}}
        @php
            try {
                file_put_contents(
                    base_path('.cursor/debug.log'),
                    json_encode([
                        'sessionId'    => 'debug-session',
                        'runId'        => 'pre-fix',
                        'hypothesisId' => 'H2',
                        'location'     => 'packages/Webkul/Admin/src/Resources/views/components/layouts/index.blade.php:app',
                        'message'      => 'Admin layout rendered',
                        'data'         => [
                            'url'        => request()->fullUrl(),
                            'routeName'  => optional(request()->route())->getName(),
                        ],
                        'timestamp'    => round(microtime(true) * 1000),
                    ]) . PHP_EOL,
                    FILE_APPEND
                );
            } catch (\Throwable $e) {
                // ignore logging errors
            }
        @endphp
        {{-- #endregion agent log --}}
        <!-- Flash Message Blade Component -->
        <x-admin::flash-group />

        <!-- Confirm Modal Blade Component -->
        <x-admin::modal.confirm />

        {!! view_render_event('admin.layout.content.before') !!}

        <!-- Page Header Blade Component -->
        <x-admin::layouts.header />

        <div
            class="group/container sidebar-collapsed flex gap-4"
            ref="appLayout"
        >
            <!-- Page Sidebar Blade Component -->
            <x-admin::layouts.sidebar.desktop />

            {{-- min-w-0 + overflow-x-clip: nada puede ser más ancho que la pantalla del
                 celular; si no, el teléfono aleja el zoom y la barra inferior queda afuera. --}}
            <div class="flex min-h-[calc(100vh-62px)] min-w-0 max-w-full flex-1 flex-col bg-gray-100 pt-3 transition-all duration-300 dark:bg-gray-950 max-lg:overflow-x-clip">
                <!-- Page Content Blade Component -->
                {{-- max-lg:pb-24: lo que tapa la barra inferior del celular. --}}
                <div class="px-4 pb-6 max-lg:px-3 max-lg:pb-24 ltr:lg:pl-[85px] rtl:lg:pr-[85px]">
                    {{ $slot }}
                </div>

                <!-- Powered By -->
                <div class="mt-auto pt-6 max-lg:hidden">
                    <div class="border-t bg-white py-5 text-center text-sm font-normal dark:border-gray-800 dark:bg-gray-900 dark:text-white max-md:py-3">
                        <p>{!! core()->getConfigData('general.settings.footer.label') !!}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Barra inferior del celular -->
        <x-admin::layouts.bottom-nav />

        {!! view_render_event('admin.layout.content.after') !!}
    </div>

    {!! view_render_event('admin.layout.body.after') !!}

    @stack('scripts')

    {!! view_render_event('admin.layout.vue-app-mount.before') !!}

    <script>
        /**
         * Load event, the purpose of using the event is to mount the application
         * after all of our `Vue` components which is present in blade file have
         * been registered in the app. No matter what `app.mount()` should be
         * called in the last.
         */
        window.addEventListener("load", function(event) {
            app.mount("#app");
        });
    </script>

    {!! view_render_event('admin.layout.vue-app-mount.after') !!}
</body>

</html>
