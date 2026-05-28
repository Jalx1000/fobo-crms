---
name: project-smd-integration-state
description: Estado actual de la integración SMD en la vista del paciente — qué existe, qué falta, qué endpoints hay
metadata:
  type: project
---

# Estado actual SMD en la vista del paciente

## Lo que ya existe en el sidebar izquierdo

- **Badge de estado** (Sincronizado/No sincronizado) en base a `$person->smd_patient_id`
- **Componente Vue `v-smd-sync`**: botón "Sincronizar con SMD" que llama POST `admin.contacts.persons.sync_smd`
- Muestra ID SMD en monospace tras sincronizar

## Modelo y base de datos

- `persons.smd_patient_id` — columna en BD (migración `2026_05_19_000001_add_smd_patient_id_to_persons_table.php`)
- El modelo `Person` tiene `smd_patient_id` en `$fillable`

## Endpoints disponibles para SMD

- `GET admin.contacts.persons.search_smd` — busca en SMD por teléfono/CI (throttle 30/min)
- `POST admin.contacts.persons.sync_smd` — vincula al paciente con SMD
- `ShareMeDataService::searchPatient(phone)` — busca por teléfono
- `ShareMeDataService::searchPatientByCi(ci)` — busca por CI
- `ShareMeDataService::updatePatient(smdId, data)` — actualiza datos en SMD

## Datos que SMD retorna por paciente

`_id, fullName, phone, secondEmail, name, lastName, personID (CI), birthday, extra`

**Why:** El badge y botón de sync ya están pero están en el sidebar, no en una tab dedicada. La tab nueva unificaría y expandiría esa información con más detalle y acciones.
