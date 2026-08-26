-- 02 · Crear el pipeline "Inmobiliaria C21" y sus 7 etapas
-- Idempotente: se puede correr varias veces sin duplicar.
-- Nota: kl_lead_pipeline_stages NO tiene columna lead_stage_id en esta instancia (ver C4 del spec).

START TRANSACTION;

INSERT INTO kl_lead_pipelines (name, is_default, rotten_days, created_at, updated_at)
SELECT 'Inmobiliaria C21', 0, 100, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_lead_pipelines WHERE name = 'Inmobiliaria C21') AS x);

SET @pid := (SELECT id FROM kl_lead_pipelines WHERE name = 'Inmobiliaria C21');

INSERT INTO kl_lead_pipeline_stages (code, name, probability, sort_order, lead_pipeline_id)
SELECT nuevas.code, nuevas.name, nuevas.probability, nuevas.sort_order, @pid
FROM (
              SELECT 'prospectos'       AS code, 'Prospectos'       AS name,  10 AS probability, 1 AS sort_order
    UNION ALL SELECT 'visita-agendada',      'Visita agendada',      40, 2
    UNION ALL SELECT 'visita-realizada',     'Visita realizada',     65, 3
    UNION ALL SELECT 'cliente-efectivo',     'Cliente efectivo',     90, 4
    UNION ALL SELECT 'sin-interes',          'Sin interés',          20, 5
    UNION ALL SELECT 'won',                  'Ganado',              100, 6
    UNION ALL SELECT 'lost',                 'Perdido',               0, 7
) AS nuevas
WHERE NOT EXISTS (
    SELECT 1 FROM kl_lead_pipeline_stages s
    WHERE s.lead_pipeline_id = @pid AND s.code = nuevas.code
);

COMMIT;
