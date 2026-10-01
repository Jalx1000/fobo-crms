-- 04 · Atributos custom de lead para la operación inmobiliaria
-- Idempotente. Todos: entity_type='leads', is_user_defined=1, is_required=0, is_unique=0.
-- is_required=0 a propósito: los atributos son por entidad, un obligatorio bloquearía toda carga.
-- is_unique=0 en bot_wa_id: la deduplicación es lógica de negocio, no un índice.

START TRANSACTION;

INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'op_modalidad','Modalidad','select',NULL,'leads',100,NULL,0,0,0,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='leads' AND code='op_modalidad') AS x);
SET @a := (SELECT id FROM kl_attributes WHERE entity_type='leads' AND code='op_modalidad');
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'venta',1,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='venta') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'alquiler',2,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='alquiler') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'anticrético',3,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='anticrético') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'preventa',4,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='preventa') AS y);

INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'op_tipo_inmueble','Tipo de inmueble','select',NULL,'leads',101,NULL,0,0,0,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='leads' AND code='op_tipo_inmueble') AS x);
SET @a := (SELECT id FROM kl_attributes WHERE entity_type='leads' AND code='op_tipo_inmueble');
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'departamento',1,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='departamento') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'casa',2,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='casa') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'terreno',3,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='terreno') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'local',4,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='local') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'oficina',5,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='oficina') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'galpón',6,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='galpón') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'quinta',7,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='quinta') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'agrícola',8,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='agrícola') AS y);

INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'op_departamento','Departamento','select',NULL,'leads',102,NULL,0,0,0,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='leads' AND code='op_departamento') AS x);
SET @a := (SELECT id FROM kl_attributes WHERE entity_type='leads' AND code='op_departamento');
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'Beni',1,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='Beni') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'Chuquisaca',2,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='Chuquisaca') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'Cochabamba',3,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='Cochabamba') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'La Paz',4,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='La Paz') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'Oruro',5,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='Oruro') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'Pando',6,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='Pando') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'Potosí',7,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='Potosí') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'Santa Cruz',8,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='Santa Cruz') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'Tarija',9,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='Tarija') AS y);

INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'op_zona','Zona','text',NULL,'leads',103,NULL,0,0,0,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='leads' AND code='op_zona') AS x);

INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'op_presupuesto_min','Presupuesto mín.','price',NULL,'leads',104,NULL,0,0,0,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='leads' AND code='op_presupuesto_min') AS x);

INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'op_presupuesto_max','Presupuesto máx.','price',NULL,'leads',105,NULL,0,0,0,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='leads' AND code='op_presupuesto_max') AS x);

INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'op_moneda','Moneda','select',NULL,'leads',106,NULL,0,0,0,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='leads' AND code='op_moneda') AS x);
SET @a := (SELECT id FROM kl_attributes WHERE entity_type='leads' AND code='op_moneda');
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'USD',1,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='USD') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'BOB',2,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='BOB') AS y);

INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'op_dormitorios','Dormitorios mín.','select',NULL,'leads',107,NULL,0,0,0,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='leads' AND code='op_dormitorios') AS x);
SET @a := (SELECT id FROM kl_attributes WHERE entity_type='leads' AND code='op_dormitorios');
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'indistinto',1,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='indistinto') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT '1',2,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='1') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT '2',3,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='2') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT '3',4,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='3') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT '4',5,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='4') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT '5 o más',6,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='5 o más') AS y);

INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'op_forma_pago','Forma de pago','select',NULL,'leads',108,NULL,0,0,0,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='leads' AND code='op_forma_pago') AS x);
SET @a := (SELECT id FROM kl_attributes WHERE entity_type='leads' AND code='op_forma_pago');
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'contado',1,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='contado') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'crédito bancario',2,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='crédito bancario') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'crédito VIS',3,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='crédito VIS') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'plan desarrollador',4,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='plan desarrollador') AS y);

INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'op_banco','Banco','text',NULL,'leads',109,NULL,0,0,0,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='leads' AND code='op_banco') AS x);

INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'op_uso','Uso','select',NULL,'leads',110,NULL,0,0,0,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='leads' AND code='op_uso') AS x);
SET @a := (SELECT id FROM kl_attributes WHERE entity_type='leads' AND code='op_uso');
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'vivir',1,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='vivir') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'inversión',2,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='inversión') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'renta',3,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='renta') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'negocio',4,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='negocio') AS y);

INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'op_plazo','Plazo de decisión','select',NULL,'leads',111,NULL,0,0,0,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='leads' AND code='op_plazo') AS x);
SET @a := (SELECT id FROM kl_attributes WHERE entity_type='leads' AND code='op_plazo');
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT '≤30 días',1,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='≤30 días') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT '1–3 meses',2,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='1–3 meses') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT '3–6 meses',3,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='3–6 meses') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'sin definir',4,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='sin definir') AS y);

INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'bot_puntaje','Puntaje','text',NULL,'leads',120,NULL,0,0,0,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='leads' AND code='bot_puntaje') AS x);

INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'bot_temperatura','Temperatura','select',NULL,'leads',121,NULL,0,0,0,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='leads' AND code='bot_temperatura') AS x);
SET @a := (SELECT id FROM kl_attributes WHERE entity_type='leads' AND code='bot_temperatura');
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'caliente',1,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='caliente') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'tibio',2,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='tibio') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'frío',3,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='frío') AS y);

INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'bot_codigos_vistos','Inmuebles mostrados','textarea',NULL,'leads',122,NULL,0,0,0,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='leads' AND code='bot_codigos_vistos') AS x);

INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'bot_wa_id','WhatsApp ID','text',NULL,'leads',123,NULL,0,0,0,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='leads' AND code='bot_wa_id') AS x);

INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'bot_pausado_hasta','Bot pausado hasta','datetime',NULL,'leads',124,NULL,0,0,0,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='leads' AND code='bot_pausado_hasta') AS x);

INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'vis_codigo','Inmueble de la visita','text',NULL,'leads',130,NULL,0,0,0,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='leads' AND code='vis_codigo') AS x);

INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'vis_resultado','Resultado de la visita','select',NULL,'leads',131,NULL,0,0,0,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='leads' AND code='vis_resultado') AS x);
SET @a := (SELECT id FROM kl_attributes WHERE entity_type='leads' AND code='vis_resultado');
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'le gustó',1,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='le gustó') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'no le gustó',2,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='no le gustó') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'no asistió',3,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='no asistió') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'pendiente',4,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='pendiente') AS y);

INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'perdida_motivo','Motivo de pérdida','select',NULL,'leads',140,NULL,0,0,0,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='leads' AND code='perdida_motivo') AS x);
SET @a := (SELECT id FROM kl_attributes WHERE entity_type='leads' AND code='perdida_motivo');
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'Sin presupuesto real',1,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='Sin presupuesto real') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'No calificó al crédito',2,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='No calificó al crédito') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'Compró o alquiló con otra inmobiliaria',3,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='Compró o alquiló con otra inmobiliaria') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'Zona sin cartera',4,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='Zona sin cartera') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'Precio fuera de mercado',5,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='Precio fuera de mercado') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'No aceptó requisitos o garantía',6,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='No aceptó requisitos o garantía') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'Documentación trabada',7,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='Documentación trabada') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'Nunca respondió',8,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='Nunca respondió') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'Solo consultaba',9,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='Solo consultaba') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'No es el titular',10,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='No es el titular') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'No aceptó exclusividad',11,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='No aceptó exclusividad') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'Duplicado',12,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='Duplicado') AS y);

INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'cap_precio_esperado','Precio esperado (captación)','price',NULL,'leads',150,NULL,0,0,0,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='leads' AND code='cap_precio_esperado') AS x);

INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'cap_estado_documental','Estado documental','select',NULL,'leads',151,NULL,0,0,0,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='leads' AND code='cap_estado_documental') AS x);
SET @a := (SELECT id FROM kl_attributes WHERE entity_type='leads' AND code='cap_estado_documental');
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'folio real limpio',1,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='folio real limpio') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'en regularización',2,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='en regularización') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'solo minuta',3,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='solo minuta') AS y);
INSERT INTO kl_attribute_options (name,sort_order,attribute_id) SELECT 'preventa',4,@a WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attribute_options WHERE attribute_id=@a AND name='preventa') AS y);

INSERT INTO kl_attributes (code,name,type,lookup_type,entity_type,sort_order,validation,is_required,is_unique,quick_add,is_user_defined,created_at,updated_at)
SELECT 'cap_exclusividad','Exclusividad','boolean',NULL,'leads',152,NULL,0,0,0,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM kl_attributes WHERE entity_type='leads' AND code='cap_exclusividad') AS x);

COMMIT;