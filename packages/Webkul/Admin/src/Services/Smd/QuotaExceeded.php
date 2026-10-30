<?php

namespace Webkul\Admin\Services\Smd;

use RuntimeException;

/**
 * Se llegó al tope diario de consultas a SMD y esta no es esencial: no se hace.
 * Los métodos de ShareMeDataService la atrapan como cualquier falla de SMD y
 * responden vacío, así el CRM sigue igual que cuando SMD no contesta.
 */
class QuotaExceeded extends RuntimeException {}
