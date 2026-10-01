-- 05 · Sources y types inmobiliarios (idempotente, no toca los existentes)
START TRANSACTION;
INSERT INTO kl_lead_sources (name, created_at, updated_at)
SELECT n, NOW(), NOW() FROM (
              SELECT 'Anuncio Meta' AS n
    UNION ALL SELECT 'Anuncio Google'
    UNION ALL SELECT 'Portal inmobiliario'
    UNION ALL SELECT 'Referido'
    UNION ALL SELECT 'Feria'
    UNION ALL SELECT 'Cartel en obra'
) AS nuevos
WHERE NOT EXISTS (SELECT 1 FROM kl_lead_sources s WHERE s.name = nuevos.n);

INSERT INTO kl_lead_types (name, created_at, updated_at)
SELECT n, NOW(), NOW() FROM (
              SELECT 'Comprador' AS n
    UNION ALL SELECT 'Inquilino'
    UNION ALL SELECT 'Propietario'
    UNION ALL SELECT 'Inversionista'
    UNION ALL SELECT 'Referidor'
) AS nuevos
WHERE NOT EXISTS (SELECT 1 FROM kl_lead_types t WHERE t.name = nuevos.n);
COMMIT;
