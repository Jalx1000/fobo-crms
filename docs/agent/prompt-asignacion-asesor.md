# Prompt para el equipo del agente: asignación del asesor

> Para copiar en el prompt del agente de WhatsApp de Imprimir (sección de
> derivación) y para que el equipo revise que las derivaciones se asignan así.
> Auditoría del 14/10/2026: 40 conversaciones estaban con un asesor distinto
> del dueño del pedido.

---

## Bloque para el prompt del agente

```
═══════════════════════════════════════════════════════════════════
A QUIÉN SE DERIVA (lo decide el CRM, no vos)
═══════════════════════════════════════════════════════════════════

Vos nunca elegís al asesor. El CRM lo elige con la tabla de reparto
producto × ciudad. Para que pueda hacerlo, derivar_a_asesor SIEMPRE lleva:

  • sku     → el producto del que habla el cliente:
              CM_00001 Stretch Film · CM_00002 Bolsas Magia Verde
              CM_00003 Hules · CM_00004 Tapas
  • ciudad  → la ciudad que dijo el cliente, tal como la dijo
              ("Tupiza", "cbba", "Trinidad"): el CRM la lleva a su
              departamento.
  • reason  → una línea con lo que pidió (producto, variante, cantidad).

Si el cliente habló de dos productos, mandá el principal (el que tiene
cantidad). Si todavía no dijo la ciudad, preguntala ANTES de derivar; si
no la quiere dar, derivá igual sin ciudad: el CRM lo manda al turno de
los ejecutivos sin ciudad.

Nunca derives sin sku cuando el producto ya se sabe: sin sku el CRM no
consulta la tabla y el chat queda con quien tenía el pedido antes.

No le digas al cliente el nombre del asesor antes de derivar: lo asigna
el CRM y puede ser otro.
```

---

## Cómo reparte el CRM (para revisar)

| Lo que sabe | A quién va |
|---|---|
| Producto y ciudad con responsable local | El responsable de ese producto en esa ciudad (Fabio: Hules en Santa Cruz; Mauricio: todo en Cochabamba; Gabriela: todo en La Paz) |
| Producto y ciudad sin responsable local | El de cobertura nacional de ese producto (Bulmaro: Stretch, Hules y Tapas; Eduardo: Magia Verde) |
| Ciudad sin producto | Quien atiende todos los productos en esa ciudad; si no hay (Santa Cruz), turno entre Bulmaro y Eduardo |
| Sin ciudad | Turno entre los ejecutivos sin ciudad (Bulmaro y Eduardo) |

Mariano Pinell es supervisor desde el 14/10: no recibe clientes.

Al derivar, el CRM deja **al mismo asesor** en el pedido, el contacto, la
conversación y las cotizaciones. Si después cambia el asesor del pedido
(a mano o porque apareció la ciudad), la conversación lo sigue.

## Checklist de prueba

Mandar cada caso desde un número de prueba y verificar en el CRM que el
pedido, el contacto y la conversación quedaron con el asesor esperado:

1. Stretch Film, Santa Cruz → Bulmaro.
2. Hules, Santa Cruz → Fabio.
3. Bolsas Magia Verde, Santa Cruz → Eduardo.
4. Tapas, La Paz → Gabriela.
5. Stretch Film, Cochabamba → Mauricio.
6. Bolsas Magia Verde, Tupiza → Eduardo, con ciudad Potosí y el pedido en el
   tablero Potosí.
7. Sin ciudad (el cliente no la da) → Bulmaro o Eduardo, pedido en el tablero
   «Sin ciudad».
8. Llamada a `derivar_a_asesor` sin `sku` → debe aparecer en los logs del
   agente como error a corregir.
