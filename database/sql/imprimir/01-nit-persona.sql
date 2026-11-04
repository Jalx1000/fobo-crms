-- 01 · Campo NIT en Personas (contactos)
--
-- El PASO 4 del flujo de venta pide razón social, NIT, correo y dirección. Los
-- otros tres ya tenían dónde ir (organizations.name, persons.emails,
-- organizations.address); el NIT no tenía ningún campo y terminaba apelotonado
-- como texto libre en la descripción de la cotización - donde nadie lo puede
-- filtrar, exportar ni usar para facturar.
--
-- Va en `persons` y no en `organizations` porque en Imprimir se le cotiza tanto
-- a empresas como a personas naturales con NIT propio, y muchos clientes de
-- WhatsApp nunca llegan a tener una organización cargada.
--
-- Idempotente: se puede correr las veces que haga falta.
--
--   docker exec -i <contenedor_mysql> mysql -u<u> -p<p> <db> < 01-nit-persona.sql
--
-- Para revertir: 99-rollback-nit.sql

START TRANSACTION;

INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'nit','NIT','text',NULL,'persons',NULL,'',0,0,1,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='persons' AND code='nit') AS x);

COMMIT;

-- is_required=0 : la mayoría de los contactos entran por WhatsApp sin NIT.
--                 Obligatorio bloquearía la creación automática de cada uno.
-- is_unique=0   : dos contactos de la misma empresa comparten NIT legítimamente.
--                 Un índice único los rechazaría al segundo.
-- validation='' : el NIT boliviano se escribe con y sin guiones ni complementos.
--                 'numeric' haría que el formulario rechace un NIT válido.
-- quick_add=1   : aparece en el alta rápida, que es donde el asesor convierte
--                 una conversación de WhatsApp en contacto.

SELECT id, code, name, type, entity_type, is_required, is_unique, quick_add, is_user_defined
FROM kl_attributes WHERE entity_type='persons' AND code='nit';
