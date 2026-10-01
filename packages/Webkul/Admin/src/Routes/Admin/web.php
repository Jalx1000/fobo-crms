<?php

/**
 * Auth routes.
 */
require 'auth-routes.php';

/**
 * Leads routes.
 */
require 'leads-routes.php';

/**
 * Email routes.
 */
require 'mail-routes.php';

/**
 * Settings routes.
 */
require 'settings-routes.php';

/**
 * Products routes.
 */
require 'products-routes.php';

/**
 * Contacts routes.
 */
require 'contacts-routes.php';

/**
 * Activities routes.
 */
require 'activities-routes.php';

/**
 * Quotes routes.
 */
require 'quote-routes.php';

/**
 * Configuration routes.
 */
require 'configuration-routes.php';

/**
 * Rest routes.
 */
require 'rest-routes.php';

/**
 * Historial del agente de IA.
 *
 * Bajo el admin ('web' + 'user') porque la pestaña del CRM autentica por sesión:
 * el grupo 'api' no es stateful en esta app, así que la cookie del navegador no
 * sirve ahí. Antes la pestaña pegaba a la versión pública de este endpoint.
 */
Route::get('chat-history', [\App\Http\Controllers\Api\ChatHistoryController::class, 'byEmail'])
    ->name('admin.chat-history');
