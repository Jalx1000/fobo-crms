-- Revierte 03-cotizacion-atributos.sql (atributos, opciones y valores cargados)
START TRANSACTION;
DELETE v FROM kl_attribute_values v JOIN kl_attributes a ON a.id = v.attribute_id
 WHERE a.entity_type='quotes' AND a.code IN ('forma_pago','lugar_entrega','tiempo_entrega','orden_compra','sucursal','observaciones','razon_social');
DELETE o FROM kl_attribute_options o JOIN kl_attributes a ON a.id = o.attribute_id
 WHERE a.entity_type='quotes' AND a.code IN ('forma_pago','lugar_entrega','tiempo_entrega','orden_compra','sucursal','observaciones','razon_social');
DELETE FROM kl_attributes
 WHERE entity_type='quotes' AND code IN ('forma_pago','lugar_entrega','tiempo_entrega','orden_compra','sucursal','observaciones','razon_social');
COMMIT;
