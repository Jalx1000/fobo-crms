# Spec: Vigilancia del sistema

- **Estado:** 🟡 Fase 1 (Specify). Pendiente de revisión. **No implementar hasta aprobación.**
- **Fase:** 1 de 4 (Specify → Plan → Tasks → Implement)
- **Instancia:** `c21.sofopolis.com` · Krayin 2.1.5 · sin despliegue, cambios en vivo

---

## Supuestos

1. **Cambios en vivo, sin despliegue.** `docker/entrypoint.sh` no vuelve a correr.
2. **Un solo operador técnico** (vos). Esto no es para un equipo de guardia: es para que una falla
   silenciosa se note el mismo día, no la semana que viene.
3. **El correo funciona** — `HandoffAsignado` y `HandoffSinTomar` ya se mandan por ahí.
4. **Sin herramientas externas.** Nada de Sentry, Datadog ni un stack de monitoreo: la instancia
   tiene 96 GB de disco y una base con 600 MB de RAM.
5. **Verificación manual**, como todo el resto: la suite de tests sigue siendo destructiva.

---

## Objetivo

Que una falla del sistema **se note**, en vez de aparecer días después porque un cliente no recibió
una foto.

**Usuario:** vos, manteniendo esto. Y de rebote los asesores, que hoy no tienen forma de distinguir
"el cliente no escribió" de "el mensaje se perdió".

**Fuera de alcance:** métricas de negocio (cuántas conversaciones atendió el agente, cuántos leads
generó). Eso es otra cosa y responde a otra pregunta.

---

## Hallazgos del reconocimiento

Ordenados por gravedad. Los dos primeros son fallas **activas ahora mismo**.

| # | Hallazgo | Impacto |
|---|---|---|
| H1 | 🔴 **No existe el proceso de scheduler.** Supervisor levanta `ide`, `php`, `nginx` y `whatsapp`, nada más; no hay cron en el host. `php artisan schedule:run` **nunca se ejecuta**. | Todo comando programado es ficción. Incluye `crm:escalar-handoffs`, entregado hoy: el aviso al supervisor por una derivación sin tomar **nunca va a salir**. El comentario del entrypoint dice que arranca un scheduler; no lo arranca. |
| H2 | 🔴 **742 errores de `[AUTHENTICATIONFAILED]`** en el log desde el 27/08 07:30, sin interrupción. La conexión IMAP de correos entrantes falla continuamente. | Nadie se enteró en más de un día. Es la prueba de que el log existe y nadie lo mira: es exactamente el modo de falla que este trabajo tiene que cerrar. |
| H3 | 🔴 **Los workers se duplican.** Un `queue:work` se desprendió de supervisor (`ppid=0`) y siguió consumiendo la misma cola con código viejo. `supervisorctl restart` levanta uno nuevo sin matar al huérfano. | Causó que una foto se descargara y la siguiente no — comportamiento intermitente, el más caro de diagnosticar. Se resolvió a mano; nada impide que vuelva. |
| H4 | No hay ninguna pantalla de estado en el admin. Ni `failed_jobs`, ni mensajes fallidos, ni descargas pendientes. | Todo diagnóstico exige `docker exec` y saber qué preguntar. |
| H5 | El log es un archivo único de 2.4 MB (`driver: single`). El canal `daily` existe configurado con 14 días de retención, pero no es el activo. | Crece sin techo y buscar en él es cada vez más caro. |
| H6 | ✅ Los datos para vigilar **ya existen** y son consultables: `failed_jobs`, `messages.status = failed`, entrantes con archivo sin `media_path`, fotos de catálogo sin `provider_media_id`, handoffs abiertos. | No hay que instrumentar nada nuevo: hay que mirarlo. |

---

## Decisiones propuestas

### D1 — Arreglar el scheduler antes que nada

Sin esto, cualquier vigilancia programada que construyamos tiene el mismo destino que el
escalamiento: código correcto que nadie ejecuta. Se agrega un `[program:scheduler]` a supervisor
corriendo `php artisan schedule:work`.

**`schedule:work` y no un cron con `schedule:run`:** es un proceso de larga vida, lo supervisa el
mismo mecanismo que al worker, y se ve en `supervisorctl status` igual que los demás. Un cron dentro
del contenedor sería una segunda forma de arrancar cosas, invisible desde donde ya miramos.

⚠️ **Vive en el contenedor, no en el repositorio.** Igual que `client_max_body_size` de nginx: un
redespliegue lo revierte. Hay que dejarlo anotado donde se vea.

### D2 — Un comando que responde "¿está todo bien?"

`crm:estado` imprime un resumen y **sale con código distinto de cero si algo está mal**. Un solo
comando para correr cuando algo se siente raro, en vez de recordar seis consultas.

Qué revisa, con el umbral que lo vuelve un problema:

| Señal | Umbral |
|---|---|
| Jobs fallidos | cualquiera > 0 |
| Workers de la cola | distinto de 1, o alguno con `ppid = 0` |
| Scheduler corriendo | ausente |
| Mensajes salientes fallidos | en las últimas 24 h |
| Media entrante sin descargar | con más de 15 minutos |
| Fotos de catálogo sin subir al proveedor | con más de 1 hora |
| Derivaciones abiertas sin tomar | pasado el umbral de escalamiento |
| Errores en el log | tasa por hora sobre lo normal |
| Espacio en disco y tamaño de `storage/app` | umbral configurable |

### D3 — Un aviso diario, no una alerta por cada cosa

Un mail una vez al día con lo que esté mal, y **nada cuando está todo bien**. Más un aviso inmediato
solo para lo que no puede esperar: jobs fallidos y workers duplicados.

**Por qué no alertar todo al instante:** los 742 errores de H2 son un aviso repetido que nadie mira.
Un sistema que avisa demasiado enseña a ignorarlo, y termina siendo peor que no avisar. Es la misma
razón por la que el escalamiento de derivaciones notifica una sola vez.

### D4 — Una pantalla de estado en el admin

La misma información que `crm:estado`, en `/admin/whatsapp/estado`, para no depender de `docker exec`.
Solo para administradores.

### D5 — Rotación de logs

Pasar a `daily` con 14 días, que ya está configurado y sin usar. Un cambio de una línea en `.env`.

### D6 — Impedir que los workers se dupliquen

`supervisorctl restart` no mata a un huérfano. Se agrega un comando de reinicio que primero termina
cualquier `queue:work` que no dependa de supervisor, y después reinicia el programa. Y `crm:estado`
lo detecta, para que si vuelve a pasar se vea en vez de manifestarse como rarezas.

---

## Tech stack

Laravel 10 · PHP 8.2 · Redis · MySQL · supervisor. **Sin dependencias nuevas.**

---

## Comandos

```bash
C=heaven_c21_laravel.1.imolcmadtascuk4e2xsutwci8

# Estado del sistema (lo que este trabajo construye)
docker exec -w /code -u www-data -e HOME=/tmp $C php artisan crm:estado
docker exec -w /code -u www-data -e HOME=/tmp $C php artisan crm:estado --json

# Reinicio seguro del worker (mata huérfanos primero)
docker exec $C supervisorctl status
docker exec -w /code $C ./vendor/bin/pint packages/Webkul/Whatsapp
```

---

## Estructura

```
packages/Webkul/Whatsapp/src/
  Console/Commands/Estado.php            → NUEVO: el chequeo, reutilizable
  Console/Commands/AvisoDiario.php       → NUEVO: manda el resumen si hay algo
  Services/Diagnostico.php               → NUEVO: las señales y sus umbrales
  Mail/SistemaConProblemas.php           → NUEVO
  Http/Controllers/EstadoController.php  → NUEVO: la pantalla del admin
  Resources/views/estado.blade.php       → NUEVO
config/logging.php · .env                → D5
/etc/supervisor/conf.d/supervisord.conf  → D1 (fuera del repositorio)
```

**El diagnóstico vive en un servicio, no en el comando:** lo consumen el comando, el aviso diario y
la pantalla. Tres copias de los mismos umbrales se desincronizan solas.

---

## Estilo

```php
/**
 * Una señal de salud del sistema.
 *
 * `ok` no alcanza: un problema tiene que explicar qué hacer. "3 jobs fallidos"
 * manda a alguien a buscar; "3 jobs fallidos — mirá `queue:failed` y reintentá
 * con `queue:retry all`" lo resuelve.
 */
final class Senal
{
    public function __construct(
        public string $nombre,
        public bool $ok,
        public string $detalle,
        public ?string $comoArreglarlo = null,
    ) {}
}
```

---

## Verificación

Sin suite automatizada. Tres niveles:

1. **Provocar cada falla y ver que la detecta:** un job fallido a mano, un worker duplicado a
   propósito, un mensaje marcado como fallido, una descarga pendiente. Una señal que nunca se vio en
   rojo no está verificada.
2. **Que el scheduler realmente corra:** después de D1, comprobar que `crm:escalar-handoffs` se
   ejecuta solo. Es la prueba de que H1 quedó cerrado.
3. **Que el aviso diario no llegue** cuando está todo bien. Un aviso que llega siempre es ruido.

---

## Límites

**Siempre**
- Correr artisan como `www-data`.
- Reiniciar el worker tras cada cambio de código, **y verificar que no quedó un huérfano**.
- Anotar en el repositorio todo cambio hecho dentro del contenedor.

**Preguntar primero**
- Mandar mail a direcciones reales.
- Tocar la configuración de supervisor: es infraestructura compartida.
- Cambiar umbrales que disparen avisos.

**Nunca**
- Agregar dependencias de monitoreo externo.
- Alertar por algo que no requiera acción.
- Escribir el diagnóstico tres veces.
- Editar `packages/Webkul/{Admin,Core,Lead}`.

---

## Criterios de éxito

1. `crm:escalar-handoffs` **se ejecuta solo**, verificado por su efecto y no por su existencia.
2. `crm:estado` detecta las cuatro fallas provocadas a mano y sale con código ≠ 0.
3. Detecta un worker duplicado — la falla de H3 — y dice cómo resolverlo.
4. Con todo sano, sale con 0 y **no manda ningún mail**.
5. `/admin/whatsapp/estado` muestra lo mismo que el comando.
6. El log rota diario y conserva 14 días.
7. **H2 queda resuelto o silenciado a conciencia:** o se arregla la conexión IMAP, o se decide
   apagarla. No se deja fallando 742 veces sin que nadie lo mire.
8. Los cambios hechos en el contenedor están anotados en el repositorio.

---

## Preguntas abiertas

1. **¿A quién llega el aviso diario?** Propongo los administradores activos, como el escalamiento.
2. **¿Qué hacemos con el IMAP de H2?** ¿Se arregla la credencial o se apaga la función? Necesito
   saber si el correo entrante se usa.
3. **¿A qué hora el aviso diario?** Propongo 8:00, antes de que arranque la jornada.
4. **¿La pantalla de estado va en el menú** o queda como URL que conocés vos?
