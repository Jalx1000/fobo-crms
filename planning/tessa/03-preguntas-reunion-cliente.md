# Tessa: preguntas para la reunión con el cliente

**Fecha:** 19 de octubre de 2026 · **Cliente:** Tessa — "Libre · Joven · Bella" · Clínica de belleza: depilación láser y estética corporal

Cada pregunta indica **qué decide en el sistema**. Las marcadas con ★ son imprescindibles: sin esa respuesta no se puede configurar.

> **Ajuste respecto a los docs 01 y 02:** Tessa no es un spa de masajes sino una **clínica estética**. La depilación láser se vende por **paquetes de sesiones** con intervalos de semanas, por zonas del cuerpo, y requiere ficha clínica (fototipo, contraindicaciones, consentimiento). Eso acerca el sistema a lo que ya hace Odontoking más de lo que parecía.

## 1. El negocio

| # | Pregunta | Decide |
| --- | --- | --- |
| 1 ★ | ¿Cuántas sucursales tienen? ¿En qué ciudades? ¿Piensan abrir más? | Si se usan Organizaciones como sucursales y los reportes por sucursal |
| 2 ★ | ¿Cómo llaman a quien atienden: **paciente**, **clienta** o **cliente**? | La terminología de todo el CRM (hoy el tablero dice "cliente") |
| 3 | ¿Atienden solo mujeres o también hombres? ¿Qué porcentaje aproximado? | Textos, formularios y campañas |
| 4 | ¿Cuántas personas atienden por mes, aproximadamente? ¿Cuántas son nuevas? | Dimensionamiento e indicadores |
| 5 | ¿Qué usan hoy para agendar y llevar fichas: cuaderno, Excel, otro sistema? | Migración de datos y qué reemplazamos |

## 2. Servicios y zonas

| # | Pregunta | Decide |
| --- | --- | --- |
| 6 ★ | ¿Nos pueden pasar la lista de servicios con **precio y duración** de cada uno? | Catálogo de Servicios (`products`, ya tiene `duration_minutes`) |
| 7 ★ | En depilación láser, ¿cobran **por zona** (axilas, piernas, bikini, rostro…), por combos de zonas o cuerpo completo? | Si la "zona" es un servicio, un atributo o una combinación |
| 8 | ¿Qué servicios de estética corporal ofrecen (reductores, moldeadores, radiofrecuencia, cavitación, HIFU, criolipólisis…)? | Categorías de servicio (hoy "Especialidades") |
| 9 | ¿Hacen tratamientos faciales u otros servicios que no sean láser ni corporales? | Categorías |
| 10 | ¿Una persona puede hacerse varias zonas o servicios en la misma visita? | Si la cita lleva uno o varios servicios y cómo se suma la duración |

## 3. Paquetes y sesiones (lo más importante para depilación láser)

| # | Pregunta | Decide |
| --- | --- | --- |
| 11 ★ | ¿Venden **paquetes de sesiones** (ej. 6 o 10 sesiones de una zona)? ¿Cuántas sesiones trae cada uno? | Módulo de paquetes con saldo de sesiones (no existe hoy) |
| 12 ★ | ¿Cuánto tiempo debe pasar entre sesión y sesión? ¿Varía por zona? | Recordatorio automático "te toca tu próxima sesión" |
| 13 | ¿El paquete se paga completo al inicio, en cuotas o por sesión? | Pagos, saldos y deudas |
| 14 | ¿Los paquetes vencen? ¿Qué pasa si la persona falta a una sesión: la pierde o se reprograma? | Reglas de vencimiento e inasistencia |
| 15 | ¿Ofrecen sesión de prueba o de diagnóstico gratuita/paga antes del paquete? | Etapa "Evaluación" en el embudo |
| 16 | ¿Venden gift cards o paquetes de regalo? | Flujo "para otra persona" (ya existe en el CRM) |

## 4. Agenda y recursos

| # | Pregunta | Decide |
| --- | --- | --- |
| 17 ★ | ¿Cuántas **máquinas láser** y equipos corporales tienen? ¿Una cabina por máquina? | Si la agenda se arma por profesional, por máquina/cabina o por ambos |
| 18 ★ | ¿Quiénes atienden (cosmetólogas, técnicas, médica)? ¿Cualquiera puede hacer cualquier servicio o cada una tiene los suyos? | "Doctores" → profesionales y qué servicio hace cada una |
| 19 ★ | ¿Qué horarios de atención y turnos tienen las profesionales? | Turnos y días libres (ya existe) |
| 20 | ¿Hay un médico o dermatóloga que deba evaluar o firmar ciertos tratamientos? | Roles y aprobaciones |
| 21 | ¿Cada cuántos minutos se puede empezar una cita: cada 15, cada 30, en horas en punto? | Grilla de horarios disponibles |
| 22 | ¿Necesitan tiempo entre citas (limpieza o preparación de la máquina)? | Margen entre turnos |

## 5. Ficha clínica

| # | Pregunta | Decide |
| --- | --- | --- |
| 23 ★ | ¿Registran **fototipo de piel**, contraindicaciones, medicación, embarazo, alergias? | Atributos de la ficha del cliente |
| 24 ★ | ¿Hacen firmar **consentimiento informado**? ¿En papel o les serviría digital? | Adjuntos o firma digital por paciente |
| 25 | ¿Registran los parámetros de cada sesión (potencia, fluencia, zona) y fotos de antes/después? | Historial por sesión y adjuntos |
| 26 | ¿Necesitan medidas corporales (peso, cintura, cadera) por sesión en estética corporal? | Seguimiento de evolución |
| 27 | ¿Piden documento de identidad (CI)? ¿Fecha de nacimiento? | Campos obligatorios (hoy: CI y nacimiento) |

## 6. Cómo les llegan los clientes

| # | Pregunta | Decide |
| --- | --- | --- |
| 28 ★ | ¿Por dónde les escriben: WhatsApp, Instagram, Facebook, TikTok, web, presencial? | Fuentes y canales a integrar |
| 29 ★ | ¿Tienen **WhatsApp Business** con un número propio? ¿Alguna vez usaron la API de Meta? | Integración de WhatsApp, recordatorios, agente IA |
| 30 | ¿Hacen publicidad paga (Meta Ads)? ¿Quieren saber qué anuncio trajo a cada cliente? | Seguimiento de campañas |
| 31 | ¿Quién responde los mensajes hoy? ¿Cuánto tardan? ¿Se pierden consultas fuera de horario? | Si conviene el agente de IA y su horario |
| 32 | ¿Les gustaría que la clienta pueda **agendar sola** por WhatsApp, o prefieren que siempre confirme una persona? | Formulario de reserva automático o derivación a recepción |

## 7. Proceso comercial (embudo)

| # | Pregunta | Decide |
| --- | --- | --- |
| 33 ★ | Contanos el recorrido de una clienta desde que pregunta hasta que termina el paquete. | Etapas del embudo |
| 34 | ¿Hay vendedoras/asesoras que cierran paquetes? ¿Cobran comisión? | Usuarios, roles y reporte por asesora |
| 35 | ¿Hacen seguimiento a quien preguntó y no compró? ¿Cuántas veces y por qué medio? | Automatizaciones de seguimiento |
| 36 | ¿Mandan presupuestos o cotizaciones formales? | Si se usa o se oculta el módulo Cotizaciones |

## 8. Recordatorios y fidelización

| # | Pregunta | Decide |
| --- | --- | --- |
| 37 | ¿Recuerdan la cita el día anterior? ¿Qué dice el mensaje hoy? | Plantillas de recordatorio |
| 38 | ¿Tienen indicaciones previas a la sesión (rasurar 24 h antes, no tomar sol…) y cuidados posteriores? | Mensajes automáticos antes y después |
| 39 | ¿Hacen promociones de cumpleaños, referidos o puntos? | Campañas y automatizaciones |
| 40 | ¿Piden reseñas en Google o Instagram después de la sesión? | Mensaje posvisita |

## 9. Pagos y reportes

| # | Pregunta | Decide |
| --- | --- | --- |
| 41 ★ | ¿Qué medios de pago aceptan (efectivo, QR, tarjeta, transferencia)? ¿Necesitan facturar desde el sistema? | Registro de pagos; la facturación es otra integración |
| 42 ★ | ¿Qué números quiere ver la dueña cada semana? (ventas, paquetes vendidos, ocupación de máquinas, clientas nuevas, tasa de inasistencia…) | Indicadores del tablero |
| 43 | ¿Venden productos (cremas, protector solar, post-depilación)? ¿Llevan stock? | Módulo de almacén e inventario (ya existe) |

## 10. Usuarios y cierre

| # | Pregunta | Decide |
| --- | --- | --- |
| 44 ★ | ¿Quiénes van a usar el sistema y qué debe ver cada uno? (dueña, recepción, profesionales) | Roles y permisos |
| 45 | ¿Las profesionales deberían ver su agenda en el celular o recibir aviso de sus citas del día? | Aviso de turno por WhatsApp (ya existe para doctores) |
| 46 ★ | ¿Tienen fichas o clientas que quieran cargar al inicio? ¿En qué formato? | Importación |
| 47 ★ | ¿Para cuándo lo necesitan funcionando? ¿Qué es lo mínimo para arrancar? | Alcance de la primera entrega |

## Para pedir después de la reunión

- Lista de servicios y zonas con precio y duración.
- Lista de paquetes (sesiones, precio, vencimiento).
- Profesionales con sus horarios y qué servicios hace cada una.
- Logo, colores, dirección y horarios de cada sucursal.
- Textos actuales de WhatsApp (bienvenida, recordatorio, indicaciones previas).
- Modelo del consentimiento informado y de la ficha clínica.
- Acceso a su cuenta de Meta Business / WhatsApp Business.
