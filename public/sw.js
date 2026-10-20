/*
 * Service worker de la app instalable de Sensia.
 *
 * Existe para que el navegador ofrezca "Instalar app" / "Agregar a pantalla de
 * inicio": Chrome lo pide para instalar. NO guarda nada en caché a propósito: el CRM
 * muestra pedidos y cobros, y una pantalla vieja servida desde caché le haría ver al
 * distribuidor pedidos que ya cambiaron. Todo va siempre a la red.
 */
self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('fetch', () => {
    // Sin respondWith: el navegador hace el pedido normal, a la red.
});
