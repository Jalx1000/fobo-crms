<?php

namespace Webkul\Whatsapp\Http\Controllers\Api\Mobile;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * @OA\Tag(
 *     name="WhatsApp Mobile",
 *     description="Bandeja de WhatsApp para la app mobile. Autenticación: token Sanctum de un usuario del CRM, obtenido con POST /api/v1/login (tag Authentication) y enviado como `Authorization: Bearer {token}`. Cada acción queda a nombre de ese usuario, y sólo ve las conversaciones que su `view_permission` le permite."
 * )
 */
class MeController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/v1/mobile/whatsapp/me",
     *     operationId="mobileWhatsappMe",
     *     tags={"WhatsApp Mobile"},
     *     summary="Usuario del token y si puede usar WhatsApp",
     *     description="Llamarlo al iniciar sesión. Responde 200 aunque el usuario no tenga el permiso `whatsapp`: en ese caso `can_whatsapp` es false y el resto de los endpoints del tag responden 403.",
     *     security={ {"sanctum_admin": {} }},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Usuario autenticado",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=7),
     *                 @OA\Property(property="name", type="string", example="Nancy Delgado"),
     *                 @OA\Property(property="email", type="string", example="nancy@example.com"),
     *                 @OA\Property(property="role", type="string", example="Encargado"),
     *                 @OA\Property(property="view_permission", type="string", enum={"global", "group", "individual"}, example="individual"),
     *                 @OA\Property(property="can_whatsapp", type="boolean", example=true)
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Sin token o token inválido")
     * )
     */
    public function show(): JsonResponse
    {
        $user = auth()->guard('user')->user();

        return response()->json([
            'data' => [
                'id'              => $user->id,
                'name'            => $user->name,
                'email'           => $user->email,
                'role'            => $user->role?->name,
                'view_permission' => $user->view_permission,
                'can_whatsapp'    => bouncer()->hasPermission('whatsapp'),
            ],
        ]);
    }
}
