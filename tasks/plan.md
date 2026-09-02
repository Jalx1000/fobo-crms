# Plan técnico — Recepción de media entrante en WhatsApp

- **Spec:** `docs/specs/media-entrante-whatsapp.md` (D1–D11 cerradas)
- **Fase:** 2 de 4 (Specify ✅ → **Plan** → Tasks → Implement)
- **Estado:** 🟡 Pendiente de revisión humana. No implementar hasta aprobación.
- **Plan anterior:** `tasks/plan-setup-inmobiliaria.md` (trabajo terminado, archivado)

---

## Qué hay que construir, en una frase

Cinco piezas nuevas y tres modificaciones, sobre una base que ya guarda el puntero al archivo y ya
sabe pintarlo. El trabajo real es **traer el binario desde Meta y servirlo**, no reconstruir el
camino.

---

## Componentes

| # | Componente | Archivos | Depende de |
|---|---|---|---|
| C1 | Campos de media en el DTO de entrada | `Gateways/Dto/InboundMessage.php` | — |
| C2 | `downloadMedia()` en el contrato + Cloud API + Kommo | `Gateways/Contracts/Gateway.php`, `CloudApi/CloudApiGateway.php`, `Kommo/KommoGateway.php` | C1 |
| C3 | El parser extrae id, mime y nombre del payload | `CloudApi/CloudApiGateway.php` | C1 |
| C4 | Job `DescargarMediaEntrante` | `Jobs/DescargarMediaEntrante.php` | C2 |
| C5 | El ingest despacha el job | `Jobs/IngestInboundPayload.php` | C3, C4 |
| C6 | **Ruta de servido + `transform()` con URL real** | `Http/Controllers/InboxController.php`, `Routes/admin.php` | — |
| C7 | Estado de la descarga visible en el hilo | `Resources/views/inbox-component.blade.php`, `InboxController.php` | C4, C6 |
| C8 | Bloque `media` para el agente | `Services/AgentPayload.php` | C4 |
| C9 | `capabilities()` honestas | `CloudApi/CloudApiGateway.php` | todo lo anterior |
| C10 | Comando de relleno retroactivo | `Console/Commands/DescargarMediaHistorica.php` | C4 |

```
C1 ──┬──> C2 ──> C4 ──┬──> C7 ──> C9
     └──> C3 ──> C5 ──┘   
                       └──> C8
                       └──> C10

C6 ─────────────────────────────────>  (independiente: arregla un bug ya existente)
```

**Paralelizable:** C6 no depende de nada y arregla un bug activo — va primero y solo. C8 y C10 se
pueden escribir mientras se verifica C7.

**Secuencial y crítico:** C1 antes que C2 y C3; C4 antes de cualquier cosa que muestre el archivo.
**C9 va último**, siempre: declarar una capacidad antes de que funcione es exactamente lo que causó
el bug del botón de adjuntar.

---

## Orden de implementación

### Etapa 0 — Arreglar la URL rota *(C6)*

**Se hace primero porque es un bug activo en producción de facto**, no una preparación. El adjunto
que el asesor envía hoy sale bien a WhatsApp y se ve roto en su propio hilo.

Entrega valor sola, se verifica sola, y deja lista la ruta que después usará todo lo entrante.

### Etapa 1 — Poder traer el archivo *(C1, C2, C3)*

Extraer el id del payload y poder descargar por él. Al terminar esta etapa **todavía no cambia nada
para el usuario**: es la plomería.

### Etapa 2 — Descarga real de punta a punta *(C4, C5)*

El job descarga y completa la fila. Al terminar, una foto que manda un cliente **se ve en el inbox**.
Es el hito que justifica todo el trabajo.

### Etapa 3 — Contar la verdad sobre el estado *(C7, C8)*

El hilo distingue "descargando", "listo" y "no se pudo". El agente recibe el bloque `media` con
`disponible` reflejando el estado real.

### Etapa 4 — Cerrar *(C9, C10)*

`capabilities()` pasa a listar exactamente lo que se verificó tipo por tipo. El comando de relleno
queda como red.

---

## Riesgos

| # | Riesgo | Prob. | Impacto | Mitigación |
|---|---|---|---|---|
| R1 | **El worker queda con código viejo.** Ya pasó: once fotos no subieron a Meta y el error solo estaba en `failed_jobs`. Con cambios en vivo y sin despliegue, el entrypoint nunca reinicia nada. | Alta | 🔴 Alto | `supervisorctl restart whatsapp` es parte de la definición de "etapa terminada", no un paso opcional. Cada checkpoint lo verifica mirando la hora de arranque del proceso. |
| R2 | **La URL de descarga de Meta caduca en minutos.** Un reintento que reuse la URL vieja falla siempre. | Alta | Medio | Cada intento vuelve a resolver el id (`GET /{media-id}`). Nunca se persiste la URL. |
| R3 | Descargar dentro del webhook ata el acuse a Meta a la velocidad de la descarga → Meta reintenta y duplica. | Media | 🔴 Alto | D1: la descarga vive en un job. El ingest no cambia su tiempo de respuesta. Ya hay dedup por `wa_message_id` (índice único + verificación explícita), que acota el daño si igual pasa. |
| R4 | **Permisos root/www-data.** Un archivo creado por la CLI queda ilegible para la web y viceversa. Ya rompió la carga de galerías. | Media | Medio | Artisan siempre como `www-data`; el disco `local` ya tiene 0775/0664 declarados. El checkpoint incluye escribir y leer como www-data. |
| R5 | **Cambiar `capabilities` enciende UI.** Agregar `image` a `send` hizo aparecer un botón conectado a un mock. | Media | Medio | C9 va último y su verificación incluye recorrer el inbox buscando controles que se hayan encendido solos. |
| R6 | No hay forma de probar lo entrante sin un WhatsApp real. | Alta | Medio | Etapas 1–3 se verifican con **payloads sintéticos** copiados del formato real de Meta. La prueba con un teléfono real es el último checkpoint y **requiere autorización explícita**. |
| R7 | **Disco sin techo** (D10, retención permanente). | Baja hoy | Medio | Línea base medida: `storage/app/whatsapp` = 16 KB, disco al 54% (52 de 96 GB). Se vuelve a medir al cerrar. |
| R8 | Un archivo corrupto o un mime inesperado rompe el job en bucle. | Media | Bajo | 3 intentos y se marca con motivo; nunca reintento infinito. El mensaje queda legible en el hilo. |
| R9 | Meta cambia el `sha256` o el formato del payload entre versiones. | Baja | Bajo | Todo lo específico del proveedor vive en el gateway; el resto habla en DTOs. |

---

## Puntos de verificación

Compuertas entre etapas: si una falla, no se avanza.

| V | Después de | Comprobación | Criterio |
|---|---|---|---|
| V1 | C6 | Adjuntar una imagen desde el inbox y recargar el hilo | **La imagen se ve.** Es el bug de H4 cerrado |
| V2 | C6 | Pedir la ruta de media sin sesión | 401/404, nunca el binario |
| V3 | C3 | Parsear un payload sintético de imagen de Meta | El DTO trae `mediaId`, `mediaMime` y el epígrafe en `body` |
| V4 | C4 | Correr el job a mano contra un `media_id` real | Archivo en disco, fila completa, mime correcto |
| V5 | C5 | Ingestar un payload sintético completo | Mensaje creado al instante + job encolado; **el ingest no tarda más que hoy** |
| V6 | C7 | Hilo con un mensaje descargando y otro fallado | Los tres estados se distinguen a simple vista |
| V7 | C8 | Evento del webhook para un mensaje con imagen | `type: "image"`, epígrafe en `text`, `media.disponible` correcto |
| V8 | C9 | Recorrer `receive` tipo por tipo | Cada tipo listado funciona de verdad; el inbox no encendió controles nuevos |
| V9 | cierre | **Foto real desde un WhatsApp** (requiere autorización) | Se ve en el inbox, con su epígrafe |
| V10 | cierre | `git status --porcelain packages/Webkul/{Admin,Core,Lead}` | Vacío |

**Antes de cada V:** el worker reiniciado y `queue:failed` en cero. Un checkpoint con jobs fallidos
sin explicar no está aprobado.

---

## Fuera de alcance

- Promover fotos al catálogo y crear inmuebles desde la conversación (D11).
- Interpretar el contenido de las imágenes.
- Recibir media en Kommo: su driver lanza `UnsupportedCapability` y no declara lo que no hace.
- Transcribir audios.
- Arreglar la suite de tests destructiva — aunque es la razón de fondo por la que estos bugs de
  "declaro algo que no cumplo" se escapan dos veces seguidas.
