-- Revierte 05-sucursal-atributos.sql (atributos y valores cargados)
START TRANSACTION;
DELETE v FROM kl_attribute_values v JOIN kl_attributes a ON a.id = v.attribute_id
 WHERE a.entity_type='warehouses' AND a.code IN ('linea','maps_url');
DELETE FROM kl_attributes WHERE entity_type='warehouses' AND code IN ('linea','maps_url');
COMMIT;
