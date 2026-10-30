<?php

namespace Webkul\Admin\Services\Smd;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;
use Webkul\Admin\Mail\SmdIntegrationAlert;

/**
 * Errores críticos de la integración con SMD y Dropbox (planning/16, RF-10):
 * cada tipo manda UN correo por hora a SMD_ALERT_EMAILS mientras siga pasando,
 * con desde cuándo y cuántas veces. Siempre queda en el log.
 *
 * Reportar nunca debe frenar lo que se estaba haciendo: cualquier falla acá
 * se traga y se loguea.
 */
class SmdAlerts
{
    public const AVAILABILITY = 'A-1';

    public const AUTH = 'A-2';

    public const EVENT_WRITE = 'A-3';

    public const DROPBOX = 'A-4';

    public const QUOTA = 'A-5';

    public const DOCTOR_WITHOUT_SPECIALTY = 'A-6';

    public const DOCTOR_NOT_IN_SMD = 'A-7';

    /** Qué pasó y qué revisar, por tipo (A-1 a A-7 de la spec). */
    public const TYPES = [
        self::AVAILABILITY => [
            'title' => 'SMD no responde la disponibilidad',
            'check' => 'Los pacientes no ven horarios y las citas no se pueden validar. Revisar el estado de SMD con `php artisan integraciones:check`.',
        ],
        self::AUTH => [
            'title' => 'SMD rechaza la apikey (401/403)',
            'check' => 'Ninguna consulta a SMD funciona. Revisar SMD_API_KEY y SMD_BASE_URL del .env (que sean del mismo entorno).',
        ],
        self::EVENT_WRITE => [
            'title' => 'No se pudo crear o editar una cita en SMD',
            'check' => 'La cita quedó solo en Sofo. Cargarla o corregirla a mano en SMD.',
        ],
        self::DROPBOX => [
            'title' => 'Falla la sincronización con Dropbox',
            'check' => 'Las citas creadas en SMD pueden no estar llegando a Sofo. Revisar el token y el cursor con `php artisan integraciones:check`.',
        ],
        self::QUOTA => [
            'title' => 'Se llegó al tope diario de consultas a SMD',
            'check' => 'El CRM dejó de consultar SMD hasta mañana. Ver de dónde salen las consultas con `php artisan smd:usage`.',
        ],
        self::DOCTOR_WITHOUT_SPECIALTY => [
            'title' => 'Doctor activo sin especialidad en Sofo',
            'check' => 'No aparece en ninguna especialidad del formulario de WhatsApp. Asignarle sus especialidades en Configuración → Doctores.',
        ],
        self::DOCTOR_NOT_IN_SMD => [
            'title' => 'Doctor activo cuyo id no aparece en SMD',
            'check' => 'No se le ven horarios. Revisar en SMD que esté en la especialidad General y que su id en Sofo sea el de SMD (`php artisan doctors:link-smd --dry-run`).',
        ],
    ];

    /** Un correo por tipo cada tanto, mientras siga pasando (D-11). */
    public const EVERY_MINUTES = 60;

    /**
     * @param  array<string, mixed>  $context  datos para el correo y el log (sin secretos)
     */
    public function report(string $type, array $context = []): void
    {
        try {
            $title = self::TYPES[$type]['title'] ?? $type;
            Log::error("[SMD Alerta {$type}] {$title}", $context);

            // Desde cuándo y cuántas veces en las últimas 24 h.
            Cache::add("smd:alert:{$type}:since", now()->toDateTimeString(), now()->addDay());
            Cache::add("smd:alert:{$type}:count", 0, now()->addDay());
            $count = (int) Cache::increment("smd:alert:{$type}:count");

            if (! Cache::add("smd:alert:{$type}:sent", true, now()->addMinutes(self::EVERY_MINUTES))) {
                return;
            }

            $emails = array_values(array_filter((array) config('smd.api_budget.alerts.emails', [])));

            if (! $emails) {
                return;
            }

            Mail::to($emails)->queue(new SmdIntegrationAlert(
                $type,
                $title,
                self::TYPES[$type]['check'] ?? '',
                (string) Cache::get("smd:alert:{$type}:since", now()->toDateTimeString()),
                $count,
                $context,
            ));
        } catch (Throwable $e) {
            Log::error('[SMD Alerta] No se pudo reportar', ['tipo' => $type, 'error' => $e->getMessage()]);
        }
    }
}
