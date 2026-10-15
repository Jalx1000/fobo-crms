# Plan - Regla "1 lead = 1 cita" (odontoking)

## Diagnóstico
- `AppointmentService` crea un lead por cita (`leadRepository->create` + `activity->leads()->sync`),
  así que en teoría ya es 1:1.
- **Anomalía real**: 3 leads tienen varias citas → lead **2689 (12 citas)**, **3948 (3)**, **2022 (2)**.
  Probable origen: reagendar/editar citas que reusaron el mismo lead, o edición manual.

## Decisión de negocio (confirmar)
- **Opción A (elegida): 1 lead por cada cita.** Cada cita = su propio lead; el historial del
  paciente = suma de sus leads/citas (cada cita es un registro independiente en el pipeline).
- (Opción B, descartada: 1 lead por paciente.)

## Fases

### Fase 1 · Diagnóstico (solo lectura) - 0.5d
- Revisar las 12 citas del lead 2689: fechas, estados, ¿reagendamientos?
- Determinar POR QUÉ comparten lead (¿el update de cita re-sincroniza el lead? ¿SMD sync?).
- Salida: informe de causa raíz.

### Fase 2 · Guard preventivo - 1d · 🟡
- En `AppointmentService::create` y en el update de cita: **cada cita tiene su propio lead**;
  nunca reusar un lead que ya está vinculado a otra cita.
- Al editar una cita, no re-vincular su lead a otra.
- Test: crear 2 citas del mismo paciente → 2 leads distintos.

### Fase 3 · Limpieza de datos - 0.5d · 🔴 (backup + OK explícito)
- Para los 3 leads con múltiples citas: crear un lead nuevo por cada cita extra y re-vincular,
  preservando etapa/estado de cada una.
- **Backup previo** de `leads` + `lead_activities` (+ `person_activities` si aplica).
- Verificar calendario + historial del paciente tras la limpieza.

## Rollback
- **Antes de Fase 3**: dump de las tablas afectadas.
  ```sql
  CREATE TABLE bak_leads_YYYYMMDD           AS SELECT * FROM kl_leads;
  CREATE TABLE bak_lead_activities_YYYYMMDD AS SELECT * FROM kl_lead_activities;
  ```
- Rollback = restaurar desde esas tablas (o `git`/DB backup completo del server).
- Fase 2 (guard) es solo código → rollback por `git revert`.

## Riesgos
- 🔴 Fase 3 modifica leads/pivotes en producción → backup obligatorio + aprobación por lead.
- El lead 2689 (12 citas) puede ser legítimo (tratamiento largo) → entenderlo en Fase 1 antes de separar.

## Estado
- [ ] Confirmar opción A
- [ ] Fase 1 diagnóstico
- [ ] Fase 2 guard
- [ ] Backup + Fase 3 limpieza
