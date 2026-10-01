-- 04 · Razón social en la cotización
--
-- El formato RG-VCO-005 separa CLIENTE (la persona con la que se habla) de
-- RAZON SOCIAL (a nombre de quién se factura). No siempre coinciden: se cotiza
-- a «Carlos Cáceres» y se factura a «GRUPO QUIROZ HOLDING».
--
-- Va en la cotización y no solo en la organización del contacto porque es un
-- dato del documento: dos cotizaciones al mismo contacto pueden facturarse a
-- razones sociales distintas.
--
-- Aplicar con --default-character-set=utf8mb4.
-- Idempotente. Revertir: 99-rollback-cotizacion.sql

START TRANSACTION;

INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'razon_social','Razón social','text',NULL,'quotes',NULL,'',0,0,1,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='quotes' AND code='razon_social') AS x);

COMMIT;

SELECT id, code, name, type FROM kl_attributes WHERE entity_type='quotes' AND code='razon_social';
