-- 05 · Línea de producto y ubicación en la sucursal (almacén)
--
-- Las sucursales de Imprimir son los almacenes del módulo Warehouse. Les faltan
-- dos datos para que el agente pueda contestar «¿dónde retiro?»:
--
--   linea     qué fabrica esa planta (Embalajes / Tapas / Envases Flexibles).
--             Es lo que distingue una sucursal de otra: las tres están en Santa
--             Cruz y el nombre solo («Imprimir 2») no le dice nada al cliente.
--
--   maps_url  el enlace de Google Maps. Es lo único que de verdad resuelve un
--             «¿dónde queda?» por WhatsApp: una dirección escrita obliga al
--             cliente a copiarla y buscarla a mano.
--
-- Atributos personalizados y no columnas: `warehouses` ya pasa por el sistema de
-- atributos de Krayin, así que aparecen solos en su formulario de Configuración.
--
-- Aplicar con --default-character-set=utf8mb4. Idempotente.

START TRANSACTION;

INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'linea','Línea de producto','text',NULL,'warehouses',NULL,'',0,0,1,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='warehouses' AND code='linea') AS x);

INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'maps_url','Ubicación (Google Maps)','text',NULL,'warehouses',NULL,'url',0,0,1,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='warehouses' AND code='maps_url') AS x);

COMMIT;

SELECT id, code, name, type FROM kl_attributes WHERE entity_type='warehouses' AND code IN ('linea','maps_url');
