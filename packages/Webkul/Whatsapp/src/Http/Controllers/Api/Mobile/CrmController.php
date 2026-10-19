<?php

namespace Webkul\Whatsapp\Http\Controllers\Api\Mobile;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Whatsapp\Http\Controllers\ChatController;
use Webkul\Whatsapp\Http\Controllers\QuickReplyController;

/**
 * El contexto del CRM alrededor de un chat: quién es el contacto, sus
 * pedidos, y los catálogos para los filtros y selectores de la app.
 */
class CrmController extends MobileController
{
    /**
     * @OA\Post(
     *     path="/api/v1/mobile/whatsapp/conversations/{id}/link-person",
     *     operationId="mobileWhatsappLinkPerson",
     *     tags={"WhatsApp Mobile"},
     *     summary="Crear el contacto del chat y vincularlo",
     *     description="Para un número que todavía no es contacto del CRM. 422 si la conversación ya tiene contacto.",
     *     security={ {"sanctum_admin": {} }},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(required=false, @OA\JsonContent(
     *
     *         @OA\Property(property="name", type="string", maxLength=120, nullable=true, description="Por defecto, el nombre de WhatsApp", example="María Pérez")
     *     )),
     *
     *     @OA\Response(response=200, description="Contacto creado", @OA\JsonContent(
     *
     *         @OA\Property(property="message", type="string", example="Contacto creado y vinculado."),
     *         @OA\Property(property="person_id", type="integer", example=812)
     *     )),
     *
     *     @OA\Response(response=401, ref="#/components/responses/MobileUnauthenticated"),
     *     @OA\Response(response=403, ref="#/components/responses/MobileForbidden"),
     *     @OA\Response(response=404, ref="#/components/responses/MobileNotFound"),
     *     @OA\Response(response=422, ref="#/components/responses/MobileUnprocessable")
     * )
     */
    public function linkPerson(Request $request, string $id): JsonResponse
    {
        $this->conversation($id);

        return $this->delegate(ChatController::class, 'linkPerson', ['request' => $request, 'id' => $id]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/mobile/whatsapp/persons/{id}",
     *     operationId="mobileWhatsappPerson",
     *     tags={"WhatsApp Mobile"},
     *     summary="Ficha del contacto",
     *     description="Datos, pedido activo, próxima actividad, archivos compartidos y huella en WhatsApp. 404 si el contacto está fuera del alcance del usuario.",
     *     security={ {"sanctum_admin": {} }},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="Ficha", @OA\JsonContent(
     *
     *         @OA\Property(property="person", ref="#/components/schemas/MobilePerson")
     *     )),
     *
     *     @OA\Response(response=401, ref="#/components/responses/MobileUnauthenticated"),
     *     @OA\Response(response=403, ref="#/components/responses/MobileForbidden"),
     *     @OA\Response(response=404, ref="#/components/responses/MobileNotFound")
     * )
     */
    public function person(string $id): JsonResponse
    {
        return $this->delegate(ChatController::class, 'person', ['id' => $id]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/mobile/whatsapp/persons/{id}/leads",
     *     operationId="mobileWhatsappStoreLead",
     *     tags={"WhatsApp Mobile"},
     *     summary="Abrir un pedido (lead) para el contacto",
     *     description="Igual que el pedido automático: en el pipeline de la ciudad del contacto, en su etapa inicial, y con el Encargado de esa ciudad como dueño (no necesariamente el usuario del token).",
     *     security={ {"sanctum_admin": {} }},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"title"},
     *
     *         @OA\Property(property="title", type="string", maxLength=191, example="Colchón 2 plazas"),
     *         @OA\Property(property="lead_value", type="number", minimum=0, nullable=true, example=2500)
     *     )),
     *
     *     @OA\Response(response=200, description="Pedido creado", @OA\JsonContent(
     *
     *         @OA\Property(property="message", type="string", example="Lead creado y vinculado al cliente."),
     *         @OA\Property(property="lead", type="object",
     *             @OA\Property(property="id", type="integer", example=5120),
     *             @OA\Property(property="title", type="string", example="Colchón 2 plazas")
     *         )
     *     )),
     *
     *     @OA\Response(response=401, ref="#/components/responses/MobileUnauthenticated"),
     *     @OA\Response(response=403, ref="#/components/responses/MobileForbidden"),
     *     @OA\Response(response=404, ref="#/components/responses/MobileNotFound"),
     *     @OA\Response(response=422, ref="#/components/responses/MobileUnprocessable")
     * )
     */
    public function storeLead(Request $request, string $id): JsonResponse
    {
        return $this->delegate(ChatController::class, 'storeLead', ['request' => $request, 'personId' => $id]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/mobile/whatsapp/stages",
     *     operationId="mobileWhatsappStages",
     *     tags={"WhatsApp Mobile"},
     *     summary="Etapas, para el filtro `stage` del listado",
     *     security={ {"sanctum_admin": {} }},
     *
     *     @OA\Response(response=200, description="Nombres de etapa", @OA\JsonContent(
     *
     *         @OA\Property(property="stages", type="array", @OA\Items(type="string"), example={"No atendido", "En seguimiento", "Confirmado"})
     *     )),
     *
     *     @OA\Response(response=401, ref="#/components/responses/MobileUnauthenticated"),
     *     @OA\Response(response=403, ref="#/components/responses/MobileForbidden")
     * )
     */
    public function stages(): JsonResponse
    {
        return $this->delegate(ChatController::class, 'stages');
    }

    /**
     * @OA\Get(
     *     path="/api/v1/mobile/whatsapp/cities",
     *     operationId="mobileWhatsappCities",
     *     tags={"WhatsApp Mobile"},
     *     summary="Ciudades con conversaciones, para el filtro `city`",
     *     description="Con cuántas conversaciones visibles tiene cada una. Sólo las que tienen al menos una.",
     *     security={ {"sanctum_admin": {} }},
     *
     *     @OA\Response(response=200, description="Ciudades", @OA\JsonContent(
     *
     *         @OA\Property(property="cities", type="array", @OA\Items(
     *             @OA\Property(property="id", type="integer", example=3),
     *             @OA\Property(property="name", type="string", example="Santa Cruz"),
     *             @OA\Property(property="count", type="integer", example=42)
     *         )),
     *         @OA\Property(property="none", type="object",
     *             @OA\Property(property="value", type="string", example="none"),
     *             @OA\Property(property="name", type="string", example="Sin ciudad"),
     *             @OA\Property(property="count", type="integer", example=5)
     *         ),
     *         @OA\Property(property="total", type="integer", example=47)
     *     )),
     *
     *     @OA\Response(response=401, ref="#/components/responses/MobileUnauthenticated"),
     *     @OA\Response(response=403, ref="#/components/responses/MobileForbidden")
     * )
     */
    public function cities(): JsonResponse
    {
        return $this->delegate(ChatController::class, 'cities');
    }

    /**
     * @OA\Get(
     *     path="/api/v1/mobile/whatsapp/products",
     *     operationId="mobileWhatsappProducts",
     *     tags={"WhatsApp Mobile"},
     *     summary="Buscar productos (máximo 20)",
     *     security={ {"sanctum_admin": {} }},
     *
     *     @OA\Parameter(name="search", in="query", required=false, description="Nombre o SKU", @OA\Schema(type="string")),
     *
     *     @OA\Response(response=200, description="Productos", @OA\JsonContent(
     *
     *         @OA\Property(property="products", type="array", @OA\Items(
     *             @OA\Property(property="id", type="integer", example=15),
     *             @OA\Property(property="name", type="string", example="Colchón Kohlberg 2 plazas"),
     *             @OA\Property(property="sku", type="string", example="COL-2P"),
     *             @OA\Property(property="price", type="string", example="2500.0000")
     *         ))
     *     )),
     *
     *     @OA\Response(response=401, ref="#/components/responses/MobileUnauthenticated"),
     *     @OA\Response(response=403, ref="#/components/responses/MobileForbidden")
     * )
     */
    public function products(Request $request): JsonResponse
    {
        return $this->delegate(ChatController::class, 'products', ['request' => $request]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/mobile/whatsapp/quick-replies",
     *     operationId="mobileWhatsappQuickReplies",
     *     tags={"WhatsApp Mobile"},
     *     summary="Respuestas rápidas (sólo lectura)",
     *     description="Las compartidas y las propias del usuario. Se crean y editan desde el CRM web.",
     *     security={ {"sanctum_admin": {} }},
     *
     *     @OA\Response(response=200, description="Respuestas rápidas", @OA\JsonContent(
     *
     *         @OA\Property(property="quick_replies", type="array", @OA\Items(ref="#/components/schemas/MobileQuickReply"))
     *     )),
     *
     *     @OA\Response(response=401, ref="#/components/responses/MobileUnauthenticated"),
     *     @OA\Response(response=403, ref="#/components/responses/MobileForbidden")
     * )
     */
    public function quickReplies(): JsonResponse
    {
        return $this->delegate(QuickReplyController::class, 'index');
    }
}
