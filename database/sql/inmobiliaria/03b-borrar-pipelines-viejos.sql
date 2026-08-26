-- 03b · Borrar los 7 pipelines de imprenta.
-- PRECONDICION VERIFICADA: 0 leads con lead_pipeline_id <> 14.
-- kl_leads.lead_pipeline_id es ON DELETE CASCADE: correr esto con leads dentro los destruye.
START TRANSACTION;
DELETE FROM kl_lead_pipelines WHERE name <> 'Inmobiliaria C21';
COMMIT;
