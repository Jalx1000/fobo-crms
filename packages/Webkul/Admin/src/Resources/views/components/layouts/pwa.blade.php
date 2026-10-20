{{--
    La app instalable de Sensia (PWA).

    Con esto el celular ofrece "Instalar app" / "Agregar a pantalla de inicio": queda
    un ícono de Sensia que abre el CRM a pantalla completa, sin barra del navegador, y
    el distribuidor no pierde el link. Abre en /admin/login, que manda a cada uno a lo
    suyo: el distribuidor a su Punto, el encargado al dashboard.

    Va en los dos layouts (el del login y el del CRM) para que se pueda instalar desde
    cualquiera de las dos pantallas.
--}}
<link rel="manifest" href="{{ url('manifest.webmanifest') }}">
<meta name="theme-color" content="#0b4aa6">

{{-- iPhone: Safari no lee los íconos del manifest. --}}
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Sensia">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="apple-touch-icon" href="{{ url('pwa/sensia-192.png') }}">

<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('{{ url('sw.js') }}').catch(() => {});
        });
    }
</script>
