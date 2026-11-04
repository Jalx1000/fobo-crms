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

## Descargar este bundle

Desde cualquier servidor (en tu sesión SSH), **descarga directa por HTTP** (sin auth):

```bash
curl -O https://tessa.sofopolis.com/entrega-agente.tgz
tar xzf entrega-agente.tgz      # -> entrega-agente/
```

> También está en el repo Git (rama `tessa`): `docs/entrega-agente/`.
> (Las docs se excluyen del contenedor vía `.dockerignore`, por eso la descarga
> es el `.tgz` publicado en `public/`, no `railway ssh`.)

## Endpoints en una línea

| Para | Endpoint | Auth |
|---|---|---|
| Catálogo | `GET /api/public/products?branch_id=1` | pública |
| Disponibilidad | `GET /api/doctors/1/available-slots?date=&duration_minutes=` | Bearer |
| Paciente | `POST /api/v1/contacts/persons` · `GET /api/v1/contacts/persons/search` | Bearer |
| Crear cita | `POST /api/v1/activities` | Bearer |
| WhatsApp | `POST /api/v1/whatsapp/conversations/{id}/messages` · `/handoff` | Bearer |

Base URL: `https://tessa.sofopolis.com`
