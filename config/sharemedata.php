<?php

return [
    /*
    |--------------------------------------------------------------------------
    | ShareMeData (calendario médico externo)
    |--------------------------------------------------------------------------
    |
    | La apiKey estaba hardcodeada en ShareMeDataService y en dos scripts de
    | depuración servidos desde public/. Los scripts se borraron; la key sale del
    | código acá para que no siga viajando en cada commit ni en cada clon del
    | repo.
    |
    | El servicio lee config() y nunca env() directo: bajo `config:cache` —que es
    | como corre esto en producción— env() devuelve null fuera de los archivos de
    | config, y el síntoma es una integración que falla sin explicar por qué.
    |
    */

    'api_key' => env('SHAREMEDATA_API_KEY'),

    'base_url' => env('SHAREMEDATA_BASE_URL', 'https://gamma.sharemedata.com/api/calendar'),
];
