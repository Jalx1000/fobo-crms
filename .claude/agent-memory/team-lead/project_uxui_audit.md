---
name: uxui-audit-planning
description: Auditoría UX/UI completada — planning en planning/07-mejoras-uxui/ con 14 hallazgos sobre componentes Krayin, spinners, modales y duplicación de código
metadata:
  type: project
---

Auditoría UX/UI de las vistas Admin del CRM completada el 2026-05-21.

Planning en `planning/07-mejoras-uxui/` con 5 documentos:
- `00.resumen-auditoria.md` — tabla de 14 hallazgos con severidad y estimación de esfuerzo (~11-14h)
- `01.sistema-componentes-krayin.md` — inventario de x-admin::* disponibles y estado de uso
- `02.modal-nuevo-paciente.frontend.md` — refactor del overlay manual (Teleport + div) a x-admin::modal
- `03.formulario-añadir-cita.frontend.md` — ~18 inputs con clase Tailwind manual, typos, botón negro
- `04.cards-seguro-smd.frontend.md` — 4 spinners ad-hoc, confirm() nativo, badges duplicados
- `05.componentes-reutilizables.frontend.md` — clase .krayin-input, helpers $insuranceBadgeClass, shimmer key-value

**Hallazgos críticos pendientes de implementar:**
- Sub-modal "Nuevo paciente": overlay manual z-99999 → x-admin::modal con Teleport
- ~25 inputs con clase manual → clase canónica del sistema
- 7 spinners ad-hoc → x-admin::spinner
- window.confirm() en insurance-verify.blade.php L210 → x-admin::modal confirm

**Why:** Consistencia visual con el sistema Krayin, mantenibilidad si el Admin package actualiza estilos base.
**How to apply:** Al proponer cambios en vistas de personas/calendario, referirse a los docs del planning para contexto de qué cambios ya están planificados.

[[sharemedata-v2-plan]]
