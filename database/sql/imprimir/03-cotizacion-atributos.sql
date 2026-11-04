-- 03 · Atributos de la cotización para el formato RG-VCO-005
--
-- Forma de pago, lugar de entrega, tiempo de entrega, orden de compra, sucursal
-- y observaciones: los campos del bloque de condiciones y de la cabecera de la
-- propuesta comercial real. Atributos personalizados y no columnas: aparecen
-- solos en el formulario y en el editor inline, igual que `marca` y `nit`.
--
-- Todos opcionales: una cotización pre-elaborada por el agente no los tiene
-- todos, y obligatorios romperían `crear_cotizacion`.
--
-- Idempotente. Revertir: 99-rollback-cotizacion.sql
--
-- ⚠️ Aplicar SIEMPRE con --default-character-set=utf8mb4: sin eso el cliente
-- mysql manda los acentos como latin1 y quedan guardados como "CrÃ©dito".
--   docker exec -i <mysql> mysql --default-character-set=utf8mb4 -u… <db> < este.sql

START TRANSACTION;

-- forma_pago (select)
INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'forma_pago','Forma de pago','select',NULL,'quotes',NULL,'',0,0,1,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='quotes' AND code='forma_pago') AS x);
SET @a := (SELECT id FROM kl_attributes WHERE entity_type='quotes' AND code='forma_pago');
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'Al contado',1,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='Al contado') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'Crédito 15 días',2,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='Crédito 15 días') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'Crédito 30 días',3,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='Crédito 30 días') AS y);

-- lugar_entrega (select)
INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'lugar_entrega','Lugar de entrega','select',NULL,'quotes',NULL,'',0,0,1,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='quotes' AND code='lugar_entrega') AS x);
SET @a := (SELECT id FROM kl_attributes WHERE entity_type='quotes' AND code='lugar_entrega');
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'Puesto almacén cliente',1,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='Puesto almacén cliente') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'Recoge en planta',2,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='Recoge en planta') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'A domicilio',3,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='A domicilio') AS y);

-- tiempo_entrega (text) - se propone desde los ítems; el asesor lo edita
INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'tiempo_entrega','Tiempo de entrega','text',NULL,'quotes',NULL,'',0,0,1,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='quotes' AND code='tiempo_entrega') AS x);

-- orden_compra (text) - lo llena el cliente al aceptar
INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'orden_compra','Orden de compra','text',NULL,'quotes',NULL,'',0,0,1,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='quotes' AND code='orden_compra') AS x);

-- sucursal (select) - hereda de la persona, editable
INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'sucursal','Sucursal','select',NULL,'quotes',NULL,'',0,0,1,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='quotes' AND code='sucursal') AS x);
SET @a := (SELECT id FROM kl_attributes WHERE entity_type='quotes' AND code='sucursal');
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'Santa Cruz',1,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='Santa Cruz') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'La Paz',2,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='La Paz') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'Cochabamba',3,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='Cochabamba') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'Otra',4,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='Otra') AS y);

-- observaciones (textarea)
INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'observaciones','Observaciones','textarea',NULL,'quotes',NULL,'',0,0,1,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='quotes' AND code='observaciones') AS x);

COMMIT;

SELECT a.code, a.type, COUNT(o.id) AS opciones
FROM kl_attributes a LEFT JOIN kl_attribute_options o ON o.attribute_id = a.id
WHERE a.entity_type='quotes' AND a.code IN ('forma_pago','lugar_entrega','tiempo_entrega','orden_compra','sucursal','observaciones')
GROUP BY a.id ORDER BY a.id;
