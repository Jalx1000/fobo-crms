<?php

/*
|--------------------------------------------------------------------------
| Historial del agente de IA (memoria de n8n)
|--------------------------------------------------------------------------
| n8n guarda cada conversación en su propia tabla de Postgres, una por tenant.
| Esto vive en un archivo de config —y no en un `env()` leído desde la ruta—
| porque `env()` devuelve null fuera de config/ en cuanto se corre
| `config:cache`, y el endpoint quedaba consultando un nombre de tabla inválido.
|
| Una sola tabla: EXTERNAL_PGSQL_CHAT_TABLE=n8n_chat_histories_sensia
| Varias:        EXTERNAL_PGSQL_CHAT_TABLES=tabla_a,tabla_b
|
| Sin valor por defecto a propósito: apuntar al tenant equivocado mostraría
| conversaciones de OTRO cliente dentro de este CRM.
*/

$tables = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('EXTERNAL_PGSQL_CHAT_TABLES', (string) env('EXTERNAL_PGSQL_CHAT_TABLE', '')))
)));

return [
    'tables' => $tables,
];
