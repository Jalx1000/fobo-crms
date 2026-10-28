# TODO — Fichas de evaluación + consentimiento (Fase 1) — ✅ COMPLETADA

- [x] Migración `create_patient_evaluations_table` (Contact pkg, idempotente)
- [x] Migración `create_patient_consents_table` (Contact pkg, idempotente)
- [x] Contratos + Proxies + Modelos (PatientEvaluation, PatientConsent) con cast answers=array
- [x] Registrar los 2 modelos en Contact ModuleServiceProvider
- [x] Person::evaluations() y Person::consents()
- [x] Aplicar esquema al dev de Railway + verificar (smoke test JSON insert/select OK, BD limpia)
- [x] Commit + push

## Fase 2 (pendiente, fuera de este spec)
- [ ] Plantilla de preguntas de la ficha (cuando Tessa la envíe en Word/Excel)
- [ ] UI de carga en el CRM (Blade/Vue) para ficha + consentimiento
- [ ] Upload del PDF del consentimiento (disco/S3)
- [ ] Alerta "no atender cita sin ficha + consentimiento"
