-- 02 · Campo NIT en Cotizaciones
--
-- La cotización guarda su PROPIA copia del NIT en vez de leerlo de la persona.
-- No es duplicación por descuido: una cotización es un documento que ya se
-- emitió. Si leyera el NIT de la persona, corregir un NIT hoy cambiaría
-- retroactivamente todas las cotizaciones emitidas el año pasado - que dejarían
-- de coincidir con el PDF que el cliente tiene guardado.
--
-- El listener SincronizarNitDeCotizacion mantiene los dos lados al día:
-- la cotización hereda el NIT de la persona, y una persona sin NIT lo aprende
-- de la primera cotización que lo traiga.
--
-- Idempotente. Requiere 01-nit-persona.sql.

START TRANSACTION;

INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'nit','NIT','text',NULL,'quotes',NULL,'',0,0,1,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='quotes' AND code='nit') AS x);

COMMIT;

-- is_required=0 : muchas cotizaciones internas no llevan NIT. Obligatorio
--                 rompería la creación de cotizaciones desde el agente.

SELECT id, code, name, type, entity_type, is_required, quick_add, is_user_defined
FROM kl_attributes WHERE code='nit' ORDER BY entity_type;
