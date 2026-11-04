# Entrega — Equipo del Agente IA (CRM Tessa)

Bundle con todo lo necesario para que el Agente IA ofrezca servicios según el
catálogo nuevo de Tessa. Validado en **producción** (2026-11-04).

## Contenido

| Archivo | Qué es |
|---|---|
| `agente-endpoints-ofrecer-servicios.md` | **Guía completa**: catálogo, disponibilidad, pacientes, crear cita y WhatsApp. Reglas de negocio y flujo paso a paso. |
| `tessa-agente.postman_collection.json` | Colección Postman v2.1 (importar en Postman). |
| `tessa.postman_environment.json` | Entorno Postman (`base_url`, `token`, `branch_id=1`, …). |

## Cómo usar la colección

1. En Postman: **Import** → arrastrar los dos `.json`.
2. Seleccionar el entorno **“Tessa · Producción (agente)”**.
3. Cargar la variable `token` (Bearer Sanctum del usuario dedicado del agente).
   El **catálogo** y la **disponibilidad pública** funcionan sin token para probar.

## Descargar esta carpeta por SSH (Railway)

Los archivos viven en el contenedor de la app desplegada. Desde una máquina con el
CLI de Railway autenticado (`railway login`) y el repo enlazado:

```bash
# Baja toda la carpeta como .tgz (base64 para no corromper el binario)
railway ssh --service tessa-crm --environment production \
  "tar czf - docs/entrega-agente | base64" | base64 -d > entrega-agente.tgz
tar xzf entrega-agente.tgz    # -> docs/entrega-agente/

# O un archivo suelto:
railway ssh --service tessa-crm --environment production \
  "cat docs/entrega-agente/tessa-agente.postman_collection.json" > tessa-agente.postman_collection.json
```

> También está en el repo Git (rama `tessa`): `docs/entrega-agente/`.

## Endpoints en una línea

| Para | Endpoint | Auth |
|---|---|---|
| Catálogo | `GET /api/public/products?branch_id=1` | pública |
| Disponibilidad | `GET /api/doctors/1/available-slots?date=&duration_minutes=` | Bearer |
| Paciente | `POST /api/v1/contacts/persons` · `GET /api/v1/contacts/persons/search` | Bearer |
| Crear cita | `POST /api/v1/activities` | Bearer |
| WhatsApp | `POST /api/v1/whatsapp/conversations/{id}/messages` · `/handoff` | Bearer |

Base URL: `https://tessa.sofopolis.com`
