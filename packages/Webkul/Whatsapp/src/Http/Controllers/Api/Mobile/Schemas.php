<?php

namespace Webkul\Whatsapp\Http\Controllers\Api\Mobile;

/**
 * Schemas y respuestas compartidas de la API mobile, sólo para Swagger.
 *
 * Describen lo que ya devuelve la bandeja del CRM (los controladores mobile
 * delegan en ella): si cambia una respuesta allá, se actualiza acá.
 *
 * ---------------------------------------------------------------------------
 * Respuestas de error
 * ---------------------------------------------------------------------------
 *
 * @OA\Response(
 *     response="MobileUnauthenticated",
 *     description="Sin token, o token inválido o revocado",
 *
 *     @OA\JsonContent(@OA\Property(property="message", type="string", example="Unauthenticated."))
 * )
 *
 * @OA\Response(
 *     response="MobileForbidden",
 *     description="El rol del usuario no tiene el permiso `whatsapp`",
 *
 *     @OA\JsonContent(@OA\Property(property="message", type="string", example="No tenés acceso al módulo de WhatsApp."))
 * )
 *
 * @OA\Response(
 *     response="MobileNotFound",
 *     description="No existe, o está fuera del alcance del usuario (su `view_permission`). Las dos se responden igual a propósito.",
 *
 *     @OA\JsonContent(@OA\Property(property="message", type="string", example="Not Found"))
 * )
 *
 * @OA\Response(
 *     response="MobileUnprocessable",
 *     description="Validación, o una regla de negocio: el agente IA está encendido, la plantilla no existe, faltan variables, etc. Mostrar `message` al usuario.",
 *
 *     @OA\JsonContent(
 *
 *         @OA\Property(property="message", type="string", example="El agente está activo en esta conversación. Desactivalo para escribir a mano."),
 *         @OA\Property(property="errors", type="object", nullable=true, description="Sólo en errores de validación: campo → mensajes", example={"text": {"El campo text es obligatorio."}})
 *     )
 * )
 *
 * @OA\Response(
 *     response="MobileConflict",
 *     description="Otro asesor ya tomó la conversación, o la derivación ya no está abierta",
 *
 *     @OA\JsonContent(@OA\Property(property="message", type="string", example="Otro asesor tomó esta conversación primero."))
 * )
 *
 * @OA\Response(
 *     response="MobileNotSupported",
 *     description="El canal de WhatsApp activo no soporta ese tipo de envío",
 *
 *     @OA\JsonContent(@OA\Property(property="message", type="string", example="El canal actual no envía ubicaciones."))
 * )
 *
 * ---------------------------------------------------------------------------
 * Bandeja
 * ---------------------------------------------------------------------------
 *
 * @OA\Schema(
 *     schema="MobileConversation",
 *     description="Una fila de la bandeja",
 *
 *     @OA\Property(property="id", type="integer", example=323),
 *     @OA\Property(property="name", type="string", description="Contacto del CRM, o nombre de WhatsApp, o teléfono", example="María Pérez"),
 *     @OA\Property(property="phone", type="string", example="+59171234567"),
 *     @OA\Property(property="gateway", type="string", example="cloud_api"),
 *     @OA\Property(property="person_id", type="integer", nullable=true, example=812),
 *     @OA\Property(property="lead_id", type="integer", nullable=true, example=null),
 *     @OA\Property(property="unread_count", type="integer", example=2),
 *     @OA\Property(property="ai_enabled", type="boolean", description="Si el agente IA responde ahora (ya considera las derivaciones)", example=false),
 *     @OA\Property(property="handoff", type="object", nullable=true, description="null si no hay derivación abierta",
 *         @OA\Property(property="pooled", type="boolean", description="Sin asesor: cualquiera la puede tomar", example=false),
 *         @OA\Property(property="city", type="string", nullable=true, example="Santa Cruz"),
 *         @OA\Property(property="advisor", type="string", nullable=true, example="Nancy Delgado"),
 *         @OA\Property(property="is_mine", type="boolean", example=true),
 *         @OA\Property(property="since", type="string", format="date-time", nullable=true, example="2026-10-10T13:40:00-04:00")
 *     ),
 *     @OA\Property(property="stage", type="string", nullable=true, description="Etapa del lead activo (o el último)", example="No atendido"),
 *     @OA\Property(property="city", type="string", nullable=true, example="Santa Cruz"),
 *     @OA\Property(property="preview", type="string", nullable=true, description="Último mensaje, hasta 60 caracteres. Empieza con ↩ si lo mandamos nosotros.", example="↩ Te paso los precios"),
 *     @OA\Property(property="last_message_at", type="string", format="date-time", nullable=true, example="2026-10-10T14:02:11-04:00")
 * )
 *
 * @OA\Schema(
 *     schema="MobileConversationPage",
 *
 *     @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/MobileConversation")),
 *     @OA\Property(property="current_page", type="integer", example=1),
 *     @OA\Property(property="last_page", type="integer", example=3),
 *     @OA\Property(property="total", type="integer", description="Usar éste para saber si quedan más (scroll infinito)", example=74),
 *     @OA\Property(property="max_pages", type="integer", example=10)
 * )
 *
 * ---------------------------------------------------------------------------
 * Hilo
 * ---------------------------------------------------------------------------
 *
 * @OA\Schema(
 *     schema="MobileMessage",
 *     description="Un mensaje del hilo",
 *
 *     @OA\Property(property="id", type="integer", example=10422),
 *     @OA\Property(property="direction", type="string", enum={"inbound", "outbound"}, description="inbound = lo escribió el cliente", example="inbound"),
 *     @OA\Property(property="type", type="string", description="text, image, audio, video, document, sticker, location, template, interactive, reaction… Mostrar `text` como fallback para un tipo desconocido.", example="text"),
 *     @OA\Property(property="text", type="string", nullable=true, example="Hola, quería el precio del colchón"),
 *     @OA\Property(property="sender", type="string", nullable=true, enum={"contact", "ia", "human"}, description="Quién lo escribió: el cliente, el agente IA o un asesor", example="contact"),
 *     @OA\Property(property="status", type="string", enum={"queued", "sent", "delivered", "read", "failed"}, description="Salientes: queued → sent → delivered → read, o failed. Entrantes llegan como delivered.", example="delivered"),
 *     @OA\Property(property="error", type="string", nullable=true, description="Motivo, si status = failed", example=null),
 *     @OA\Property(property="time", type="string", example="14:02"),
 *     @OA\Property(property="date", type="string", format="date", description="Para separar por día", example="2026-10-10"),
 *     @OA\Property(property="timestamp", type="string", format="date-time", example="2026-10-10T14:02:11-04:00"),
 *     @OA\Property(property="media", type="object", nullable=true,
 *         @OA\Property(property="url", type="string", description="URL absoluta, pública (nombre aleatorio). Descargar sin token.", example="https://crm.example.com/whatsapp/media/aB3dE5.jpg"),
 *         @OA\Property(property="filename", type="string", nullable=true, example="cotizacion.pdf"),
 *         @OA\Property(property="meta", type="string", nullable=true, description="MIME type", example="image/jpeg")
 *     ),
 *     @OA\Property(property="location", type="object", nullable=true,
 *         @OA\Property(property="lat", type="number", example=-17.7833),
 *         @OA\Property(property="lng", type="number", example=-63.1821),
 *         @OA\Property(property="name", type="string", nullable=true),
 *         @OA\Property(property="address", type="string", nullable=true),
 *         @OA\Property(property="url", type="string", description="Link a Google Maps")
 *     ),
 *     @OA\Property(property="interactive", type="object", nullable=true, description="Menú de botones o lista que enviamos (con sus opciones)"),
 *     @OA\Property(property="selection", type="object", nullable=true, description="La opción que el cliente tocó en un menú",
 *         @OA\Property(property="id", type="string", nullable=true, example="ciudad_scz"),
 *         @OA\Property(property="title", type="string", example="Santa Cruz"),
 *         @OA\Property(property="kind", type="string", nullable=true, example="list_reply")
 *     ),
 *     @OA\Property(property="reaction", type="object", nullable=true, description="Si el mensaje es una reacción (emoji) a otro"),
 *     @OA\Property(property="replyTo", type="object", nullable=true, description="El mensaje citado",
 *         @OA\Property(property="author", type="string", enum={"Cliente", "Agente"}, example="Cliente"),
 *         @OA\Property(property="preview", type="string", example="Hola, quería el precio")
 *     )
 * )
 *
 * @OA\Schema(
 *     schema="MobileNote",
 *     description="Nota interna. Llega mezclada con los mensajes del hilo; se distingue por `kind`.",
 *
 *     @OA\Property(property="id", type="string", example="note-29811"),
 *     @OA\Property(property="kind", type="string", enum={"note"}, example="note"),
 *     @OA\Property(property="text", type="string", example="Pidió que lo llamen después de las 18 h"),
 *     @OA\Property(property="author", type="string", nullable=true, example="Nancy Delgado"),
 *     @OA\Property(property="time", type="string", example="14:05"),
 *     @OA\Property(property="date", type="string", format="date", example="2026-10-10"),
 *     @OA\Property(property="timestamp", type="string", format="date-time", example="2026-10-10T14:05:00-04:00")
 * )
 *
 * @OA\Schema(
 *     schema="MobileHandoff",
 *     description="Derivación: un humano debe atender",
 *
 *     @OA\Property(property="state", type="string", nullable=true, enum={"requested", "assigned", "resolved"}, example="assigned"),
 *     @OA\Property(property="open", type="boolean", description="requested o assigned", example=true),
 *     @OA\Property(property="pooled", type="boolean", description="Pedida y sin asesor", example=false),
 *     @OA\Property(property="reason", type="string", nullable=true, example="Quiere hablar con una persona"),
 *     @OA\Property(property="source", type="string", nullable=true, enum={"ai", "human"}, example="ai"),
 *     @OA\Property(property="requested_at", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="city", type="string", nullable=true, example="Santa Cruz"),
 *     @OA\Property(property="assigned_user", type="object", nullable=true,
 *         @OA\Property(property="id", type="integer", example=7),
 *         @OA\Property(property="name", type="string", example="Nancy Delgado")
 *     ),
 *     @OA\Property(property="assigned_at", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="is_mine", type="boolean", example=true)
 * )
 *
 * @OA\Schema(
 *     schema="MobileAgentState",
 *
 *     @OA\Property(property="enabled", type="boolean", nullable=true, description="Lo configurado en esta conversación. null = hereda el valor global", example=false),
 *     @OA\Property(property="effective", type="boolean", description="Lo que rige ahora. Si es true, no se puede escribir a mano.", example=false)
 * )
 *
 * @OA\Schema(
 *     schema="MobileThread",
 *
 *     @OA\Property(property="conversation", type="object", description="La conversación (columnas crudas: id, wa_phone, wa_name, person_id, lead_id, unread_count, last_message_at, last_inbound_at, handoff_state…)"),
 *     @OA\Property(property="messages", type="array", description="Mensajes y notas, en orden cronológico", @OA\Items(oneOf={
 *
 *         @OA\Schema(ref="#/components/schemas/MobileMessage"),
 *         @OA\Schema(ref="#/components/schemas/MobileNote")
 *     })),
 *
 *     @OA\Property(property="server_time", type="string", description="Mandarlo como `since` en el próximo refresco", example="2026-10-10 14:03:22"),
 *     @OA\Property(property="capabilities", type="object", description="Qué tipos puede enviar y recibir el canal",
 *         @OA\Property(property="send", type="array", @OA\Items(type="string"), example={"text", "reply", "emoji", "template", "image", "document", "audio", "video", "location", "buttons", "list"}),
 *         @OA\Property(property="receive", type="array", @OA\Items(type="string"), example={"text", "image", "audio", "document", "video", "sticker", "location", "contact", "interactive", "button"})
 *     ),
 *     @OA\Property(property="locations", type="array", description="Sucursales guardadas, listas para enviar", @OA\Items(
 *         @OA\Property(property="name", type="string", example="Sucursal Centro"),
 *         @OA\Property(property="address", type="string", nullable=true),
 *         @OA\Property(property="latitude", type="number", example=-17.7833),
 *         @OA\Property(property="longitude", type="number", example=-63.1821)
 *     )),
 *     @OA\Property(property="handoff", ref="#/components/schemas/MobileHandoff"),
 *     @OA\Property(property="window", type="object", description="Ventana de 24 h de WhatsApp. Cerrada: sólo plantillas.",
 *         @OA\Property(property="applies", type="boolean", description="Si el canal tiene ventana", example=true),
 *         @OA\Property(property="open", type="boolean", example=true),
 *         @OA\Property(property="expires_at", type="string", format="date-time", nullable=true),
 *         @OA\Property(property="seconds_left", type="integer", nullable=true, example=73800)
 *     ),
 *     @OA\Property(property="agent", ref="#/components/schemas/MobileAgentState")
 * )
 *
 * @OA\Schema(
 *     schema="MobileSentMessage",
 *
 *     @OA\Property(property="message", ref="#/components/schemas/MobileMessage")
 * )
 *
 * ---------------------------------------------------------------------------
 * Catálogos y CRM
 * ---------------------------------------------------------------------------
 *
 * @OA\Schema(
 *     schema="MobileTemplate",
 *
 *     @OA\Property(property="name", type="string", example="seguimiento_pedido"),
 *     @OA\Property(property="language", type="string", example="es"),
 *     @OA\Property(property="body", type="string", example="Hola {{1}}, ¿pudiste ver la cotización?"),
 *     @OA\Property(property="variables", type="integer", description="Cuántas variables hay que mandar", example=1),
 *     @OA\Property(property="category", type="string", nullable=true, example="MARKETING")
 * )
 *
 * @OA\Schema(
 *     schema="MobileQuickReply",
 *
 *     @OA\Property(property="id", type="integer", example=4),
 *     @OA\Property(property="shortcut", type="string", example="/precios"),
 *     @OA\Property(property="title", type="string", example="Lista de precios"),
 *     @OA\Property(property="content", type="string", description="Puede traer {{producto}} y {{precio}}: completarlos con /products", example="El {{producto}} está a {{precio}} Bs."),
 *     @OA\Property(property="is_global", type="boolean", example=true),
 *     @OA\Property(property="is_mine", type="boolean", example=false)
 * )
 *
 * @OA\Schema(
 *     schema="MobilePerson",
 *     description="Ficha del contacto. Fechas formateadas para mostrar (d/m/Y).",
 *
 *     @OA\Property(property="id", type="integer", example=812),
 *     @OA\Property(property="name", type="string", example="María Pérez"),
 *     @OA\Property(property="job_title", type="string", nullable=true),
 *     @OA\Property(property="organization", type="string", nullable=true),
 *     @OA\Property(property="owner", type="string", nullable=true, description="Asesor dueño del contacto", example="Nancy Delgado"),
 *     @OA\Property(property="tags", type="array", @OA\Items(
 *         @OA\Property(property="name", type="string"),
 *         @OA\Property(property="color", type="string", nullable=true)
 *     )),
 *     @OA\Property(property="emails", type="array", @OA\Items(type="string")),
 *     @OA\Property(property="contact_numbers", type="array", @OA\Items(type="string"), example={"59171234567"}),
 *     @OA\Property(property="created_at", type="string", example="03/08/2026"),
 *     @OA\Property(property="view_url", type="string", description="Ficha en el CRM web"),
 *     @OA\Property(property="whatsapp", type="object", description="Huella en WhatsApp: messages, first_contact, last_inbound"),
 *     @OA\Property(property="followup", type="object", description="awaiting_reply (el cliente escribió último), last_message_human, last_sender"),
 *     @OA\Property(property="active_lead", type="object", nullable=true,
 *         @OA\Property(property="title", type="string"),
 *         @OA\Property(property="stage", type="string", nullable=true),
 *         @OA\Property(property="value", type="string", nullable=true, example="2,500.00"),
 *         @OA\Property(property="days_open", type="integer", example=3),
 *         @OA\Property(property="url", type="string")
 *     ),
 *     @OA\Property(property="next_activity", type="object", nullable=true, description="title, type, date"),
 *     @OA\Property(property="images", type="array", description="Fotos y videos del chat: url, type, date, mine", @OA\Items(type="object")),
 *     @OA\Property(property="documents", type="array", description="Documentos y audios del chat: url, type, name…", @OA\Items(type="object"))
 * )
 */
class Schemas {}
