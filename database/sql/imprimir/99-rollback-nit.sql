-- Revierte 01-nit-persona.sql
--
-- Borra el atributo Y los valores cargados: kl_attribute_values no tiene FK
-- contra kl_attributes, así que dejar los valores huérfanos haría que un futuro
-- atributo con otro id herede datos que no son suyos.

START TRANSACTION;

SET @a := (SELECT id FROM kl_attributes WHERE entity_type='persons' AND code='nit');

DELETE FROM kl_attribute_values WHERE attribute_id = @a AND @a IS NOT NULL;
DELETE FROM kl_attributes WHERE id = @a AND @a IS NOT NULL;

COMMIT;
