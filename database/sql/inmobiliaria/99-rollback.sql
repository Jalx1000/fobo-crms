-- 99 · Rollback de las tareas 01, 04 y 05.
--
-- ⚠️ NO revierte las tareas 02/03/06 (creación del pipeline, migración de los 20 leads
-- y borrado de los 7 pipelines de imprenta). Eso solo se recupera restaurando el dump:
--   docker exec -i <mysql> mysql -ukohlberg -p<pw> kohlberg < /tmp/kohlberg-pre-inmobiliaria-*.sql

START TRANSACTION;

-- 04 · borrar opciones y atributos custom
DELETE o FROM kl_attribute_options o
JOIN kl_attributes a ON a.id = o.attribute_id
WHERE a.entity_type='leads' AND a.is_user_defined=1;

DELETE FROM kl_attribute_values
WHERE attribute_id IN (SELECT id FROM kl_attributes WHERE entity_type='leads' AND is_user_defined=1);

DELETE FROM kl_attributes WHERE entity_type='leads' AND is_user_defined=1;

-- 05 · borrar sources y types inmobiliarios (solo si ningún lead los usa)
DELETE FROM kl_lead_sources
WHERE name IN ('Anuncio Meta','Anuncio Google','Portal inmobiliario','Referido','Feria','Cartel en obra')
  AND id NOT IN (SELECT DISTINCT lead_source_id FROM kl_leads WHERE lead_source_id IS NOT NULL);

DELETE FROM kl_lead_types
WHERE name IN ('Comprador','Inquilino','Propietario','Inversionista','Referidor')
  AND id NOT IN (SELECT DISTINCT lead_type_id FROM kl_leads WHERE lead_type_id IS NOT NULL);

-- 01 · restaurar etiquetas originales de imprenta
UPDATE kl_attributes SET name='Nombre de cliente'          WHERE entity_type='leads' AND code='title';
UPDATE kl_attributes SET name='Descripción del pedido'     WHERE entity_type='leads' AND code='description';
UPDATE kl_attributes SET name='Valor del Pedido'           WHERE entity_type='leads' AND code='lead_value';
UPDATE kl_attributes SET name='Fecha de Entrega Esperada'  WHERE entity_type='leads' AND code='expected_close_date';

COMMIT;
