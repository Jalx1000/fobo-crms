# Prompt: consumir el endpoint "Doctores por especialidad"

Copia y pega el bloque de abajo como prompt para un asistente de IA (o como brief para un
desarrollador) que deba **construir la integración/consumo** de este endpoint del CRM Odontoking.

---

## PROMPT

Eres un desarrollador. Necesito que consumas un endpoint REST del CRM Odontoking para obtener
la lista de doctores **activos** de una especialidad, junto con su disponibilidad de cita.

### Endpoint

```
GET https://odontoking.sofopolis.com/api/specialties/{identifier}/doctors
```

- **Público**: no requiere token ni autenticación.
- `{identifier}` acepta **tres formas** (resolución automática en cascada):
  1. **ID numérico** de la especialidad → ej. `5`
  2. **slug** → ej. `ortodoncia`
  3. **nombre** (coincidencia parcial, insensible) → ej. `Ortodoncia`
- Si el nombre lleva espacios o acentos, **URL-encode** el segmento
  (ej. `Cirug%C3%ADa%20Maxilofacial`).
- No tiene parámetros de query. Devuelve **solo doctores con `is_active = true`**.

### Respuesta 200 (ejemplo)

```json
{
  "specialty": { "id": 5, "name": "Ortodoncia", "slug": "ortodoncia" },
  "data": [
    {
      "id": 1159,
      "name": "Dr. García",
      "unique_id": "132|Dr. García",
      "age_range_min": "0",
      "age_range_max": "99",
      "type_service_doctor": ["PROTESIS FIJA", "ORTODONCIA FIJA"],
      "attendsPatientType": "Pacientes nuevos",
      "available_7d": true,
      "available_14d": true,
      "available_30d": false
    }
  ],
  "meta": { "total": 1 }
}
```

### Contrato de cada doctor en `data[]`

| Campo | Tipo | Notas |
|---|---|---|
| `id` | integer | ID del doctor |
| `name` | string | Nombre del doctor |
| `unique_id` | string \| null | Identificador interno (`número\|nombre`) |
| `age_range_min` | string \| null | Edad mínima que atiende (ej. `"0"`) |
| `age_range_max` | string \| null | Edad máxima que atiende (ej. `"99"`) |
| `type_service_doctor` | string[] \| null | **Array de etiquetas** de servicio (texto, no ids). `null` si no tiene |
| `attendsPatientType` | string \| null | Modalidad de atención (ej. `"Pacientes nuevos"`). `null` si no tiene |
| `available_7d` | boolean | `true` si hay al menos un cupo libre en los próximos 7 días |
| `available_14d` | boolean | Igual, para 14 días |
| `available_30d` | boolean | Igual, para 30 días |

Reglas importantes:
- Los valores de `type_service_doctor` y `attendsPatientType` vienen **ya traducidos a texto**,
  nunca como id numérico.
- Cualquier campo sin dato viene como **`null`** (JSON null, no el string `"null"`).
- Los booleanos de disponibilidad son **acumulativos**: si `available_7d` es `true`,
  `available_14d` y `available_30d` también lo son.

### Otras respuestas

- **404** - especialidad no encontrada:
  ```json
  { "message": "Specialty not found" }
  ```
- **200 con lista vacía** - la especialidad existe pero no tiene doctores activos:
  ```json
  { "specialty": {...}, "data": [], "meta": { "total": 0 } }
  ```

### Lo que quiero que hagas

1. Llama al endpoint (por ID, slug o nombre según el caso).
2. Maneja los estados: cargando, éxito (con y sin doctores), y **404**.
3. Muestra por cada doctor: nombre, rango de edad, servicios (`type_service_doctor`),
   modalidad (`attendsPatientType`) y un indicador de disponibilidad según la ventana
   (7/14/30 días) que el usuario elija.
4. Trata correctamente los `null` (no muestres "null" en la UI; muestra un guion o "-").

[Especifica aquí tu stack: fetch/axios en JS, un componente Vue/React, un cliente en PHP/Python, etc.]

---

## Ejemplos de invocación rápida (curl)

```bash
# Por ID
curl -s "https://odontoking.sofopolis.com/api/specialties/5/doctors" | jq

# Por slug
curl -s "https://odontoking.sofopolis.com/api/specialties/ortodoncia/doctors" | jq

# Por nombre (parcial, URL-encoded)
curl -s "https://odontoking.sofopolis.com/api/specialties/Ortodoncia/doctors" | jq
```

> Nota: cambia el host por `http://localhost:8000` (o el que uses) para pruebas locales.
