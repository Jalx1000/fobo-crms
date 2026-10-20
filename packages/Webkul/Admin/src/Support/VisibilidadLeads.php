<?php

namespace Webkul\Admin\Support;

use Webkul\Core\Services\AlcanceCiudades;
use Webkul\Core\Services\Distribuidores;

/**
 * Qué leads puede ver el usuario: por CIUDAD si su grupo designa una, por DUEÑO
 * si no.
 *
 * Krayin trae un solo criterio, el dueño: con view_permission = group ves los
 * leads de los usuarios de tu grupo. Cuando el grupo representa una ciudad ese
 * criterio da el resultado equivocado, porque los pedidos los carga quien los
 * atiende —el encargado, el agente de WhatsApp, otro distribuidor— y no el
 * distribuidor que después los reparte. Medido sobre los datos reales: el
 * distribuidor de San Salvador de Jujuy veía 1 de los 12 leads de su ciudad.
 *
 * Existe como una sola clase porque el criterio estaba repetido en cinco lugares
 * (la grilla, el Kanban, la ficha, el buscador y el repositorio) y cada uno que
 * quede con el viejo es una puerta por la que se ven los leads de otra ciudad.
 *
 * Lo que NO cambia: quien ve todo el CRM sigue viendo todo, y quien tiene
 * view_permission = individual sigue viendo sólo lo suyo. El detalle de esa
 * decisión está en AlcanceCiudades::ciudadMandaSobreDueno().
 */
final class VisibilidadLeads
{
    /**
     * Restringe una consulta de leads al alcance del usuario.
     *
     * Las columnas se pasan porque los cinco lugares arman la consulta distinto:
     * unos desde `leads`, otros con joins y alias.
     */
    public static function aplicar(
        $query,
        string $columnaCiudad = 'leads.lead_pipeline_id',
        string $columnaDueno = 'leads.user_id',
    ): void {
        // Un distribuidor ve SÓLO lo asignado a él, aunque otro reparta en su misma
        // ciudad: cada uno es una empresa distinta. Va primero y gana sobre la ciudad.
        if ($empresa = app(Distribuidores::class)->miEmpresa()) {
            $query->whereIn($columnaDueno, $empresa);

            return;
        }

        $alcance = app(AlcanceCiudades::class);

        if ($alcance->ciudadMandaSobreDueno()) {
            $query->whereIn($columnaCiudad, $alcance->ciudadesDe());

            return;
        }

        if ($userIds = bouncer()->getAuthorizedUserIds()) {
            $query->whereIn($columnaDueno, $userIds);
        }
    }

    /**
     * Si puede abrir ESE lead.
     *
     * Para la ficha, que no filtra una lista sino que decide sobre uno solo. Sin
     * esto, con la grilla acotada igual se llegaba al lead de otra ciudad
     * escribiendo su id en la URL.
     */
    public static function puedeVer($lead): bool
    {
        if (app(Distribuidores::class)->soloLoSuyo()) {
            return app(Distribuidores::class)->esDeMiEmpresa((int) $lead->user_id);
        }

        $alcance = app(AlcanceCiudades::class);

        if ($alcance->ciudadMandaSobreDueno()) {
            return $alcance->puedeVer((int) $lead->lead_pipeline_id);
        }

        $userIds = bouncer()->getAuthorizedUserIds();

        return ! $userIds || in_array($lead->user_id, $userIds);
    }

    /**
     * Las ciudades a las que acotar, o null si el criterio no es la ciudad.
     *
     * Para las consultas que no pueden recibir un callable, como las subconsultas
     * de la bandeja de WhatsApp.
     *
     * @return array<int, int>|null
     */
    public static function ciudades(): ?array
    {
        $alcance = app(AlcanceCiudades::class);

        return $alcance->ciudadMandaSobreDueno() ? $alcance->ciudadesDe() : null;
    }
}
