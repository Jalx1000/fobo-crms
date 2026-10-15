# Login crudo Alianza (MedinetGo) - comandos curl

Endpoint: `POST https://qualitynet.alianza.com.bo/ApiGateway/LoginUserProv`
Contenedor app: `heaven_odontoking-crm` · app en `/code` · credenciales en `/code/.env`

---

## A) Recomendado - lee credenciales del `.env` (no expone la pass)

Ejecutar desde el **host** de Easypanel:

```bash
docker exec -w /code "$(docker ps --format '{{.Names}}' | grep odontoking-crm | head -1)" sh -c '
  LURL=$(grep -E "^ALIANZA_LOGIN_URL=" .env | cut -d= -f2- | tr -d "\r")
  USER=$(grep -E "^ALIANZA_USER=" .env | cut -d= -f2- | tr -d "\r\047\"")
  PASS=$(grep -E "^ALIANZA_PASS=" .env | cut -d= -f2- | tr -d "\r\047\"")
  curl -s -k -m 40 -w "\n---HTTP:%{http_code} TIME:%{time_total}s---\n" \
    -X POST "$LURL/LoginUserProv" \
    -H "Content-Type: application/json" \
    -d "{\"user\":\"$USER\",\"password\":\"$PASS\",\"module\":\"string\",\"method\":\"string\",\"nApplication\":0}"
'
```

---

## B) Curl plano (rellena la pass tú)

Sirve desde el host o dentro del contenedor:

```bash
curl -s -k -m 40 -w "\n---HTTP:%{http_code} TIME:%{time_total}s---\n" \
  -X POST "https://qualitynet.alianza.com.bo/ApiGateway/LoginUserProv" \
  -H "Content-Type: application/json" \
  -d '{"user":"cli.odontok","password":"97531","module":"string","method":"string","nApplication":0}'
```

---

## C) Verificar cobertura (cuando tengas un accessToken NO vacío)

```bash
curl -s -k -m 40 -w "\n---HTTP:%{http_code}---\n" \
  -H "Authorization: Bearer EL_ACCESS_TOKEN" \
  "https://qualitynet.alianza.com.bo/ApiGateway/OdontologyCoverage?ci=CI_DEL_PACIENTE&nUsercode=EL_NUSERCODE"
```

---

## Notas

- `-k` ignora validación TLS · `-m 40` da margen (el login tarda ~17 s).
- El bloque `---HTTP:... TIME:...---` al final muestra código HTTP y duración.
- **Señal de éxito real:** `data.accessToken` NO vacío y `data.webClientVtime[0].nUsercode > 0`.
- Si la respuesta trae `accessToken:""` + `sIndBlockedPass:"2"` → credencial inválida/expirada.
  Reintentar puede renovar el bloqueo. Pedir reset a Alianza, no insistir.

## Última respuesta observada (2026-06-29)

```json
{"success":true,"data":{"webClientVtime":[{"nUsercode":0,"sIndBlockedPass":"2","sMessage":"","sExit":"0"}],"accessToken":"","refreshToken":"","expiresAt":"0001-01-01T00:00:00"},"messageError":null}
---HTTP:200 TIME:17.22s---
```

→ Credencial rechazada. Además el login tardó 17.2 s > `ALIANZA_TIMEOUT=15` (subir a 25-30 s).
