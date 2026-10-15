# Disponibilidad de doctores (SMD − citas locales)

Endpoint que devuelve la **disponibilidad real** de un doctor combinando la agenda
de ShareMeData (SMD), la jornada laboral local y las citas locales del CRM:

```
disponibilidad = (slots_libres_SMD  ∩  jornada_local)  −  citas_locales_no_canceladas
```

- **slots_libres_SMD**: lo que SMD marca como disponible para el doctor.
- **∩ jornada_local**: se recorta a los turnos del doctor en `doctor_shifts`.
  Un día **sin turno local cargado** se considera **cerrado** (`slots: []`),
  aunque SMD tenga disponibilidad.
- **− citas_locales**: se restan las citas del CRM no canceladas (evita doble-booking
  de citas aún no reflejadas en SMD).

Si SMD no se puede consultar (caído, doctor sin vínculo, o flag desactivado),
**degrada** a la disponibilidad local (`doctor_shifts` − `activities`) e informa el
motivo en la respuesta.

> Este es el endpoint recomendado para clientes externos (agente IA). Reemplaza a
> `/api/horarios` y `/api/disponibilidad` (deprecados) y a `/api/doctors/{id}/slots`
> (que solo mira datos locales, sin SMD).

---

## Endpoint

```
GET /api/doctors/{id}/available-slots
```

- **Auth:** `Authorization: Bearer <ODONTOKING_API_TOKEN>` (Sanctum)
- **Rate limit:** `doctor-availability`

### Parámetros (query)

| Param              | Req | Tipo    | Default | Descripción                                          |
|--------------------|-----|---------|---------|------------------------------------------------------|
| `date`             | sí  | `Y-m-d` | -       | Fecha de inicio. Entre hoy y hoy+6 meses             |
| `days`             | no  | int     | 7       | Días a consultar (1–30)                              |
| `duration_minutes` | no  | int     | -       | Corta los bloques libres en slots de esa duración (15–480) |
| `specialty`        | no  | string  | todas   | Filtra por una especialidad puntual                  |
| `subsidiary`       | no  | string  | `Santa Cruz` | Sucursal en SMD                                 |

---

## Ejemplo

```bash
curl -H "Authorization: Bearer <TOKEN>" -H "Accept: application/json" \
  "https://odontoking.sofopolis.com/api/doctors/12/available-slots?date=2026-06-25&days=1&duration_minutes=60"
```

### Respuesta

```json
{
    "doctor_id": 12,
    "from": "2026-06-25",
    "days": 1,
    "source": "smd",
    "degraded": false,
    "reason": null,
    "schedule": [
        {
            "date": "2026-06-25",
            "slots": [
                { "start_time": "08:00", "end_time": "09:00", "status": "available" }
            ]
        }
    ]
}
```

### Campos de la respuesta

| Campo               | Descripción                                                            |
|---------------------|------------------------------------------------------------------------|
| `source`            | `"smd"` = datos reales de SMD · `"local"` = fallback                    |
| `degraded`          | `true` cuando hubo fallback                                             |
| `reason`            | `null`, o el motivo del fallback (ver abajo)                           |
| `schedule[].date`   | Fecha (`Y-m-d`)                                                         |
| `schedule[].slots`  | Bloques libres `{ start_time, end_time, status:"available" }` (`H:i`)   |

### Motivos de fallback (`reason`)

| Valor              | Significado                                              |
|--------------------|---------------------------------------------------------|
| `doctor_unlinked`  | El doctor no tiene `unique_id` vinculado a SMD          |
| `smd_unavailable`  | SMD no respondió (timeout / error de red / 5xx)         |
| `smd_disabled`     | `smd.validate_availability=false` (integración apagada) |

> Nota: si SMD responde `200` pero el doctor no tiene turnos ese día, se devuelve
> `source: "smd"` con `slots: []` (vacío legítimo, **no** se usa el fallback).

---

## Errores

| HTTP | Caso                                  |
|------|---------------------------------------|
| 401  | Falta / token inválido                |
| 404  | Doctor inexistente o inactivo         |
| 422  | Parámetros inválidos                  |

---

## Mantenimiento

**Vincular `unique_id` de los doctores con el `physician._id` de SMD** (prerrequisito
para que `source` sea `smd`). Auditar sin escribir:

```bash
php artisan doctors:link-smd --dry-run
```

Aplicar correcciones:

```bash
php artisan doctors:link-smd
```

**Tras desplegar el endpoint por primera vez**, refrescar el cache de rutas:

```bash
php artisan route:clear
```
