# Respuesta: el 429 y el registro incremental

> Responde al pedido de subir el throttle. Verificado en el CRM el 2026-09-23.

## Resumen

El límite alto que piden **ya existe desde antes** y su token ya lo tiene: **600/min**.
El `Retry-After` **también se manda ya**. El 429 que ven no viene de ahí — viene de
las llamadas que hacen **sin el token**.

Y hay algo bastante más urgente: **el registro incremental, tal como está planteado,
les habría borrado los productos de cada pedido.** Lo arreglamos hoy.

---

## 🔴 Lo urgente: un PUT sin `products` vaciaba el pedido

En `LeadRepository::update` el borrado de los productos sobrantes estaba **fuera** del
`if` que mira si el payload trae la lista:

```php
$previousProductIds = $lead->products()->pluck('id');

if (isset($data['products'])) { ...  }   // ← sólo entra si mandaron la lista

foreach ($previousProductIds as $productId) {
    $this->productRepository->delete($productId);   // ← esto corría SIEMPRE
}
```

Con el flujo de hoy no se notaba: ustedes mandan el pedido completo una sola vez. Con
el incremental —PUT al saber la ciudad, PUT al saber el nombre, PUT por cada vino—
**cada PUT que no llevara `products` habría borrado los que ya estaban cargados.** El
pedido terminaba en la etapa correcta, con el cliente correcto, y sin nada adentro.

**Ya está arreglado.** El contrato ahora es:

```
products ausente        → no se tocan los productos       ← sus PUT parciales
products presente       → es la lista COMPLETA; lo que no venga, se borra
products = []           → se borran todos (vaciar el pedido a propósito)
```

Cubierto con tests. Pueden mandar PUT parciales tranquilos: ciudad, nombre y edad no
necesitan llevar `products`.

⚠️ Lo que **no** cambia: cuando sí manden `products`, sigue siendo la lista completa.
Agregar el segundo vino es `products` = los anteriores + el nuevo.

## El 429: no es el límite, es el token

Su token (`user 13`, "Agente") ya cae en el bucket alto:

```php
// config/agent_api.php
'rate_limit'         => 600,   // por minuto, para tráfico con token Sanctum
'default_rate_limit' => 60,    // el resto
```

Está keyed por usuario (`agent-api:13`), que es exactamente la Opción A que pedían.
Ya estaba.

**Dónde se rompe:** estos endpoints son públicos y no exigen auth:

```
GET /api/public/products
GET /api/pedidos/por-telefono
GET /api/personas/por-telefono
GET /api/productos/por-ciudad-sucursal
```

Si los llaman **sin** el header `Authorization`, el limiter no tiene a quién
reconocer y cae al bucket por defecto: **60/min keyed por IP**. Como todas sus
conversaciones salen de la misma IP, ese cupo es compartido entre todas — con 5-6
llamadas por charla se agota con ~10 conversaciones por minuto.

**La solución es de una línea de su lado:** manden el `Authorization: Bearer <token>`
en **todas** las llamadas, también en las públicas. El endpoint las acepta igual y
pasan a 600/min por token en vez de 60/min por IP.

Si prefieren que lo resolvamos del lado del CRM, díganlo y armamos un limiter propio
para esas rutas — pero seguiría siendo por IP, que es justamente lo que no alcanza.

## `Retry-After`: ya lo mandamos

Laravel lo agrega solo en cada 429, junto con:

```
Retry-After: 37
X-RateLimit-Limit: 600
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1758623456
```

Si no lo están viendo, revisen que su cliente HTTP no descarte los headers en las
respuestas de error. Con `X-RateLimit-Limit` además pueden verificar en qué bucket
están cayendo: si dice **60**, esa llamada fue sin token.

## Para verificar que quedó bien

```bash
curl -si -H "Authorization: Bearer $TOKEN" \
  "https://kohlberg.sofopolis.com/api/pedidos/por-telefono?telefono=59170000000" \
  | grep -i x-ratelimit-limit
# X-RateLimit-Limit: 600     ← con token
# X-RateLimit-Limit: 60      ← sin token, este es el que les da 429
```

## Una cosa más sobre el incremental

Con el registro en vivo, el pedido deja de estar vacío en el primer PUT. Eso cambia
el `is_empty` del payload de `true` a `false` enseguida, así que **no lo usen para
decidir si siguen escribiendo esa conversación**: para eso guarden el `lead_id` de la
conversación, como ya tenían pensado para la corrección post-registro. `is_empty`
sirve para el arranque —¿relleno el que abrió el CRM o creo uno nuevo?—, no como
estado durante la charla.
