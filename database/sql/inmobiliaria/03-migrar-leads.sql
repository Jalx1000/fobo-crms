-- 03a · Mover los 20 leads de imprenta al pipeline Inmobiliaria C21
-- Mapeo de etapas:  NO ATENIDOS -> Prospectos  ·  ENTREGADOS -> Ganado
-- El pipeline y la etapa se actualizan en el MISMO UPDATE (la FK de etapa es ON DELETE SET NULL).

START TRANSACTION;

SET @pid := (SELECT id FROM kl_lead_pipelines WHERE name = 'Inmobiliaria C21');
SET @st_prospectos := (SELECT id FROM kl_lead_pipeline_stages WHERE lead_pipeline_id=@pid AND code='prospectos');
SET @st_won        := (SELECT id FROM kl_lead_pipeline_stages WHERE lead_pipeline_id=@pid AND code='won');
SET @st_lost       := (SELECT id FROM kl_lead_pipeline_stages WHERE lead_pipeline_id=@pid AND code='lost');
SET @st_sininteres := (SELECT id FROM kl_lead_pipeline_stages WHERE lead_pipeline_id=@pid AND code='sin-interes');

-- El default pasa al embudo nuevo ANTES de borrar el viejo, para no quedar sin default.
UPDATE kl_lead_pipelines SET is_default = IF(id = @pid, 1, 0);

UPDATE kl_leads l
JOIN kl_lead_pipeline_stages s ON s.id = l.lead_pipeline_stage_id
SET l.lead_pipeline_id       = @pid,
    l.lead_pipeline_stage_id = CASE s.code
        WHEN 'entregados'   THEN @st_won
        WHEN 'cancelados'   THEN @st_lost
        WHEN 'sin-inters'   THEN @st_sininteres
        ELSE @st_prospectos
    END
WHERE l.lead_pipeline_id <> @pid;

-- Red de seguridad: cualquier lead que hubiera quedado sin etapa válida va a Prospectos.
UPDATE kl_leads SET lead_pipeline_id = @pid, lead_pipeline_stage_id = @st_prospectos
WHERE lead_pipeline_stage_id IS NULL;

COMMIT;
