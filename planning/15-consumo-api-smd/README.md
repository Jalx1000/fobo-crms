# Incidente: consumo excesivo de la API de ShareMeData

**Fecha del incidente:** 12 de octubre de 2026 · **Detectado por:** shareMEData (13/10) · **Estado:** corregido en desarrollo, a la espera de reactivar el acceso de producción

---

## Introducción: la nueva integración

Hasta octubre de 2026, los pacientes que escribían a OdontoKing por WhatsApp agendaban conversando: el agente o una persona de recepción iba preguntando los datos y buscando un horario libre a mano.

El 12 de octubre se puso en marcha una nueva forma de agendar: **formularios nativos de WhatsApp** (WhatsApp Flows), conectados en tiempo real al CRM y a la agenda de ShareMeData (SMD). El paciente toca un botón en el chat y completa un formulario dentro de WhatsApp. El CRM hace el resto:

1. **Identifica al paciente.** El formulario pregunta para quién es la cita: para quien escribe o para otra persona (un hijo, un padre, la pareja). Pide la cédula, busca a la persona en el CRM y en SMD para no duplicar fichas, y la vincula a quien agenda.
2. **Verifica el seguro.** Con la cédula consulta Alianza, Nacional Vida y la Membresía Odontoking, y muestra si la cobertura está vigente antes de confirmar.
3. **Ofrece horarios reales.** El paciente elige la especialidad (o "No sé / Consulta general"). El formulario muestra los doctores con horarios libres según la agenda de SMD, cruzada con los turnos y las citas del CRM: turnos de 1 hora en punto.
4. **Confirma la cita.** La crea en el CRM y en SMD, la deja en la etapa "Cita Confirmada" y le avisa al paciente por el chat. El recordatorio le llega a quien la agendó.

Con la misma tecnología se agregaron formularios para **cancelar** y **reprogramar** citas. Todo el proceso lo dispara el agente de IA cuando detecta la intención, sin pedir datos por chat.

La integración necesita consultar la agenda de SMD para saber qué horarios están libres. **El problema descrito en este informe surgió en la forma en que el CRM hacía esas consultas.**

---

## 1. Qué sucedió

El 13 de octubre shareMEData (Christian Velasco) informó un consumo desproporcionado de su API por parte de la integración de Sofopolis:

> "Más de **5.700 requests en las últimas 17 horas**, aproximadamente **cada 5 segundos**."

- OdontoKing tiene contratadas **10.000 consultas por mes** (120.000 al año). A ese ritmo, el volumen de un año entero se habría consumido en unos 15 días.
- Eso implicaba costos adicionales de **Bs 5.880 cada 15 días**.
- shareMEData **cortó el acceso a la API de producción** hasta que se corrija el problema.

## 2. Cuál fue el problema

### Causa raíz

El 12 de octubre se agregó al CRM un **catálogo de horarios** para el formulario de agendar por WhatsApp. Su objetivo era que el formulario mostrara los horarios al instante, sin hacer esperar al paciente.

Para mantener ese catálogo al día, un proceso automático le preguntaba a SMD **cada 10 minutos, las 24 horas**, los horarios libres de **cada doctor en cada una de sus especialidades**, para los próximos 50 días:

| Dato | Valor |
|---|---|
| Pares doctor-especialidad consultados por vuelta | **59** (17 doctores) |
| Vueltas del 12/10 (07:40 → 00:40) | **97** |
| **Total** | **97 × 59 ≈ 5.720 consultas en 17 horas** |
| Frecuencia | 1 consulta cada ~7 segundos |

El proceso consultaba **aunque nadie estuviera agendando**. Sumado a eso, cada cancelación o reprogramación disparaba una actualización extra (unas 100 consultas más).

### Por qué no se detectó antes

- **No existía ningún registro de cuántas consultas hacía el CRM a SMD.** El consumo solo era visible del lado de shareMEData.
- **No había un límite diario.** Nada frenaba al proceso al pasar un umbral.
- **Al diseñar el catálogo no se calculó su costo** contra la cuota contratada.

### Un dato que lo hacía todavía más innecesario

SMD devuelve los horarios de **todos los doctores de una especialidad en una sola consulta** (filtra por especialidad, no por doctor). El catálogo hacía 59 consultas para traer información que se obtenía con 8, una por especialidad.

## 3. Cómo se arregló

### Diagnóstico (13/10)

Se cruzaron los registros del CRM con los números de shareMEData: 97 vueltas × 59 consultas = 5.723, igual a lo reportado. Se midió también el **consumo normal** del resto del CRM, que es de **60 a 250 consultas por día**, casi todas de la sincronización de Dropbox cuando entra un paciente nuevo.

### Corrección

| # | Cambio | Qué hace |
|---|---|---|
| 1 | **Se eliminó el catálogo programado** | El proceso que consultaba cada 10 minutos y su actualización automática ya no existen en el código. No se pueden volver a encender por error. |
| 2 | **Horarios a pedido** | El formulario consulta a SMD **solo cuando el paciente elige una especialidad**: 1 consulta que trae a todos los doctores de esa especialidad, para los próximos **7 días**. La respuesta se reutiliza 5 minutos para otros pacientes que elijan lo mismo. |
| 3 | **"Otra fecha"** | Si el paciente quiere un día fuera de esa semana, elige la fecha y se consulta **solo ese día** (1 consulta). No se piden semanas que nadie va a mirar. |
| 4 | **Sin reintentos en bucle** | Si SMD tarda, el formulario espera hasta ~8 segundos; si no llega, el paciente vuelve a tocar "Siguiente" y toma la respuesta ya obtenida, **sin consultar de nuevo**. Si SMD falla, se informa y se deriva a recepción. |

### Resultado medido (prueba real en el entorno de pruebas, 13/10)

Agendar una cita completa por el formulario de WhatsApp hizo **4 consultas a SMD**:

| Paso | Consultas |
|---|---|
| Elegir la especialidad | 1 |
| Confirmar: verificar que el horario sigue libre | 1 |
| Confirmar: crear la cita en SMD | 1 |
| Confirmar: asociar el paciente a la cita | 1 |
| Moverse entre pantallas (doctor, día, hora, resumen) | 0 |

**Antes:** ~5.700 consultas por día, hubiera o no pacientes agendando.
**Ahora:** ~4 consultas por cita agendada (5 si el paciente carga su cédula por primera vez). Con 20 citas por WhatsApp al día serían 80–100 consultas, más el consumo normal del resto del CRM: **muy por debajo de las ~330 diarias que permite el contrato**.

## 4. Medidas para prevenirlo

| Medida | Detalle |
|---|---|
| **Medidor de consultas** | Cada consulta a SMD se cuenta (por día, por mes y por qué parte del CRM la hizo) y queda registrada en el log. Se consulta con `php artisan smd:usage` (o `--days=7`). Las consultas fallidas también se cuentan, porque SMD las cobra igual. |
| **Tope diario de 800 consultas** | Al alcanzarlo, el CRM deja de hacer consultas no urgentes (búsquedas de pacientes, horarios del formulario, verificaciones). **Lo esencial sigue funcionando:** crear o editar citas confirmadas y la ficha de pacientes nuevos que llegan por Dropbox. Se configura con `SMD_DAILY_CAP`. |
| **Aviso mensual a las 8.000** | Queda un aviso en el log cuando el mes pasa las 8.000 consultas, para actuar antes de llegar a las 10.000 contratadas. Se configura con `SMD_MONTHLY_ALERT`. |
| **Un único punto de salida** | Todas las consultas a SMD pasan por el mismo servicio (`ShareMeDataService`), que es donde se cuentan y se aplica el tope. Ninguna parte del CRM puede consultar SMD por fuera. |
| **Sin procesos programados contra SMD** | Revisado: ninguna tarea automática del CRM consulta SMD. La única que lo toca es la sincronización de Dropbox, y solo cuando entra un paciente nuevo (su ficha se guarda 7 días). |
| **Tests automáticos** | Pruebas que verifican que cada consulta se cuenta, que el tope corta lo no esencial, que no queda ninguna tarea programada contra SMD y que el formulario hace 1 sola consulta por especialidad. |
| **Regla de trabajo** | Toda funcionalidad nueva que consulte SMD debe estimar su costo en consultas por día antes de publicarse. |

## 5. Estado actual y pendientes

- **Producción:** el acceso a la API de SMD sigue cortado por shareMEData.
- **Entorno de trabajo:** el CRM corre en modo desarrollo:
  - Base `odontoking_dev`, copia de producción, con los doctores del entorno de pruebas de SMD.
  - Conectado al SMD de pruebas.
  - El formulario nuevo (v3) está publicado y funcionando.

**Pendiente:**

1. Ajustar los tests que quedaron desactualizados.
2. Medir el consumo durante 7 días con `php artisan smd:usage` y enviar los números a shareMEData y OdontoKing para solicitar la reactivación del acceso de producción.
3. Al volver a producción: restaurar el `.env` de producción (base de datos, credenciales y carpeta de Dropbox) y verificar los doctores y turnos.

---

*Documentos relacionados: [00.spec.md](00.spec.md) (especificación) · [01.plan.md](01.plan.md) (plan y decisiones).*
