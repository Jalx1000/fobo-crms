# Catálogo inmobiliario CENTURY 21

Configura esta instancia para operación inmobiliaria: 3 pipelines con sus etapas, 23 atributos de
calificación sobre `leads`, 9 sources y 5 types.

- **Paquete:** `packages/Webkul/Inmobiliaria/`
- **Qué se crea:** definido en `src/Config/catalogo.php` — datos puros, sin lógica
- **Spec y decisiones:** `docs/specs/setup-inmobiliaria-c21.md`
- **Limitaciones conocidas:** `docs/inmobiliaria/limitaciones.md` ← **leelo antes de usar**

---

## Cómo correrlo

```bash
C=heaven_c21_laravel.1.q90ec5h2gx8wqgu8cg00qztrg
DBC=heaven_c21.1.etvwmtujhc67odnkcowk3f2vz

# 1. Simular SIEMPRE primero
docker exec -w /code $C php artisan crm:setup-inmobiliaria --dry-run
docker exec -w /code $C php artisan crm:setup-inmobiliaria --dry-run -v   # detalle ítem por ítem

# 2. Backup OBLIGATORIO antes de escribir
PW=$(grep '^DB_PASSWORD=' .env | cut -d= -f2- | tr -d '"')
docker exec -e MYSQL_PWD="$PW" $DBC mysqldump -u kohlberg \
  --single-transaction --routines --no-tablespaces kohlberg \
  > backups/kohlberg-$(date +%Y%m%d-%H%M%S).sql
tail -1 backups/kohlberg-*.sql | grep "Dump completed"   # verificá que terminó

# 3. Aplicar
docker exec -w /code $C php artisan crm:setup-inmobiliaria

# 4. Confirmar idempotencia — debe decir "Sin cambios"
docker exec -w /code $C php artisan crm:setup-inmobiliaria --dry-run
```

Es **idempotente**: correrlo dos veces deja el mismo estado y no duplica nada. Si un ítem ya existe
lo reporta como `ya existía` y sigue.

## Cómo revertirlo

```bash
docker exec -w /code $C php artisan crm:rollback-inmobiliaria --dry-run
docker exec -w /code $C php artisan crm:rollback-inmobiliaria
```

El rollback borra **solo lo que el setup creó**, no lo que figura en el catálogo. La diferencia es
real: `Web` está en el catálogo y **ya existía** en esta instancia (`lead_sources.id = 2`); un
rollback que razonara por catálogo la destruiría. Por eso el setup guarda un **manifiesto** de los ids
que creó, en `core_config` bajo la clave `inmobiliaria.setup.manifest`.

**Se niega a borrar** —sin tocar nada— si algún pipeline, etapa, source o type del manifiesto tiene
leads asociados, o si algún atributo tiene valores cargados. Devuelve exit code 1.

Sin manifiesto no hace nada: si necesitás revertir igual, restaurá el dump de `backups/`.

## Qué verificar después

| # | Qué | Cómo |
|---|---|---|
| 1 | Idempotencia | `--dry-run` reporta "Sin cambios" |
| 2 | Los 3 pipelines con sus etapas | `/admin/settings/pipelines` — orden, probabilidad, `rotten_days` 30/5/7 |
| 3 | Cada pipeline cierra en Ganado/Perdido | Las dos últimas etapas de cada uno |
| 4 | `Venta` es el default | Exactamente uno con la marca por defecto |
| 5 | Formulario de lead completo | `/admin/leads/create` — los 23 campos, **ninguno obligatorio** |
| 6 | Selects poblados | `perdida_motivo` con sus 12 opciones |
| 7 | Sources y types | `/admin/settings/sources` y `/types` — 9 y 5, sin duplicar `Web` |

Consulta de duplicados (todo debe volver vacío):

```sql
SELECT lead_pipeline_id, code, COUNT(*) c FROM kl_lead_pipeline_stages
  GROUP BY lead_pipeline_id, code HAVING c > 1;
SELECT name, COUNT(*) c FROM kl_lead_sources GROUP BY name HAVING c > 1;
SELECT attribute_id, name, COUNT(*) c FROM kl_attribute_options
  GROUP BY attribute_id, name HAVING c > 1;
SELECT COUNT(*) FROM kl_attributes
  WHERE entity_type='leads' AND is_user_defined=1 AND is_required=1;  -- debe ser 0
```

## Para n8n

Un atributo `select` guarda **el id de la opción** en `attribute_values.integer_value`, no la etiqueta.
El setup imprime al final el mapa `código → opción=id` de los 10 selects. También se puede leer en
vivo, sin hardcodear ids:

```
GET /api/v1/settings/attributes     (auth:sanctum)
GET /api/v1/settings/pipelines
```

Los campos `text` (`op_zona`, `op_banco`, `bot_wa_id`, `op_dormitorios`, `bot_puntaje`, …) reciben el
valor crudo. Ojo con `bot_puntaje`: ver limitaciones.

## Si cambia el catálogo

Editá `src/Config/catalogo.php` y volvé a correr el setup: agrega lo que falte y respeta lo que ya
está. **No renombra ni borra** nada existente — un ítem renombrado en el catálogo se crea como nuevo,
y el viejo queda. Para renombrar, hacelo desde la UI del CRM.
