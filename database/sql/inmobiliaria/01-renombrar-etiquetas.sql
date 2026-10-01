-- 01 · Renombrar las etiquetas nativas de lead a lenguaje inmobiliario
START TRANSACTION;
UPDATE kl_attributes SET name='Título del lead'           WHERE entity_type='leads' AND code='title';
UPDATE kl_attributes SET name='Descripción'               WHERE entity_type='leads' AND code='description';
UPDATE kl_attributes SET name='Valor / comisión esperada' WHERE entity_type='leads' AND code='lead_value';
UPDATE kl_attributes SET name='Fecha estimada de cierre'  WHERE entity_type='leads' AND code='expected_close_date';
COMMIT;
