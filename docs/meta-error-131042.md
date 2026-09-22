# Error 131042 — Business eligibility payment issue

Fecha: 2026-09-17
Cuenta: Vinos Kohlberg · WhatsApp Cloud API

---

## El error, tal cual lo devuelve Meta

Llega por el webhook de estado (`statuses[].errors[]`) segundos después de que Meta
**acepta** el mensaje con HTTP 200 y un `wamid`:

```
code:     131042
title:    Business eligibility payment issue
details:  Message failed to send because your WhatsApp Business account
          payment has been restricted. Visit
          https://business.facebook.com/billing_hub/accounts/details/?business_id=939523667552918&asset_id=1714811009708730&account_type=whatsapp-business-account
          to resolve this issue.
```

---

## Identificadores de la cuenta

| | |
|---|---|
| WABA (WhatsApp Business Account) | `1714811009708730` — "Kohlberg" |
| Business Manager | `939523667552918` |
| Número | `1162182773639133` — +591 62875485 |
| App | `2015986879126095` — "Kohlberg dev" |

---

## Estado de la cuenta según la API (2026-09-17)

| Campo | Valor | Lectura |
|---|---|---|
| `account_review_status` | `APPROVED` | ✓ |
| `business_verification_status` | `verified` | ✓ |
| `ownership_type` | `SELF` | ✓ |
| `quality_rating` del número | `GREEN` | ✓ — no es un problema de calidad |
| `name_status` | `APPROVED` | ✓ |
| `account_mode` | `LIVE` | ✓ |
| `status` | `CONNECTED` | ✓ |
| `currency` del WABA | **`AED`** | ✗ — dirham de Emiratos Árabes |
| `timezone_id` | `21` | — |
| `messaging_limit_tier` | **(ausente)** | ✗ — la mensajería paga nunca se habilitó |
| `primary_funding_id` | no legible | requiere permiso de BSP |

---

## Lo que ya se verificó del lado del negocio

- Hay tarjeta cargada y **asignada a esta cuenta de WhatsApp**.
- Moneda y zona horaria están configuradas.
- La tarjeta está como método de pago **predeterminado**.
- **No hay ningún saldo pendiente** de pago.

---

## Qué sí funciona y qué no

| Tipo de mensaje | Quién lo inicia | Se cobra | Resultado |
|---|---|---|---|
| Respuesta a un cliente (ventana de 24 h) | el cliente | no | **llega** |
| Plantilla (fuera de la ventana) | la empresa | sí | **falla con 131042** |

Ese es el patrón que separa "tiene tarjeta" de "esta cuenta puede cobrar".

---

## Mensajes afectados

- 2026-09-16: 14 plantillas `consulta_pendiente` a contactos sin ciudad — **14 fallidas**
  (el comando corrió dos veces: 28 registros)
- 2026-09-17: 1 reenvío de prueba a conv #26 (+59173014454) — fallida
- 2026-09-17: 1 a conv #854 (+59176800014) — fallida
  `wamid.HBgLNTkxNzY4MDAwMTQVAgARGBJBNkZDQTEzOTUzRkZDNDM5N0YA`

Un envío de prueba al número propio (+59176616013) el 2026-09-16 **sí se entregó**.

---

## Hipótesis principal

El WABA factura en **AED**. La tarjeta es boliviana. La mayoría de los bancos
bolivianos rechazan por defecto los cobros en moneda extranjera, así que la
pre-autorización de Meta no entra: no queda como deuda pendiente, pero Meta
marca la cuenta como *pago restringido*.

Refuerza la hipótesis que `messaging_limit_tier` no venga: un número habilitado
para mensajes pagos devuelve `TIER_250`, `TIER_1K`, etc.

**Meta no permite cambiar la moneda del WABA una vez fijada.**

---

## Qué revisar, en orden

1. **Billing Hub → pestaña Actividad de pagos / Transacciones** del link del error.
   Un intento en AED con estado *rechazado* confirma la hipótesis.
2. **WhatsApp Manager → Configuración de la cuenta → Información de pago → Moneda.**
   Confirmar que dice AED.
3. **Con el banco:** habilitar "compras internacionales / en moneda extranjera" en la
   tarjeta, o cargar una que ya las acepte.
4. **WhatsApp Manager → Números de teléfono → +591 62875485 → "Límite de mensajes".**
   Debería pasar de vacío a un tier una vez que el cobro entre.
5. Si todo lo anterior está bien y sigue fallando: **soporte de Meta Business**, con
   los identificadores de arriba y el `wamid` fallido.

---

## Cómo reintentar cuando esté resuelto

```bash
# Los 14 contactos sin ciudad (excluye cuentas propias y el número de prueba)
php artisan whatsapp:reabrir-ventana consulta_pendiente \
    --sin-ciudad --excluir=960,316,968,1 --dry-run
```

Sacar `--dry-run` para enviar. El motivo de cada fallo queda guardado en el mensaje
y se ve en rojo en el hilo del CRM.
