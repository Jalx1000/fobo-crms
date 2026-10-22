<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Descarta los caracteres UTF-8 inválidos de todo lo que llega por la API.
 *
 * El agente de IA a veces manda un emoji cortado (medio par de surrogates). Llegaba
 * hasta el modelo, y guardar el mensaje fallaba con 500 al convertirlo a JSON
 * («Malformed UTF-8 characters»): la respuesta no se guardaba ni se le enviaba al
 * cliente. Un texto raro de un sistema externo no puede tumbar el guardado.
 *
 * Solo saca los bytes inválidos: acentos y emojis bien formados quedan intactos.
 */
class LimpiarUtf8
{
    public function handle(Request $request, Closure $next)
    {
        $request->merge($this->limpiar($request->all()));

        if ($request->isJson()) {
            $request->json()->replace($this->limpiar($request->json()->all()));
        }

        return $next($request);
    }

    protected function limpiar($valor)
    {
        if (is_array($valor)) {
            return array_map(fn ($v) => $this->limpiar($v), $valor);
        }

        if (! is_string($valor) || mb_check_encoding($valor, 'UTF-8')) {
            return $valor;
        }

        $anterior = mb_substitute_character();
        mb_substitute_character('none');

        $limpio = mb_convert_encoding($valor, 'UTF-8', 'UTF-8');

        mb_substitute_character($anterior);

        return $limpio;
    }
}
