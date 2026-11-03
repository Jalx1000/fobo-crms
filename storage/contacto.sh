#!/bin/sh
# uso: sh storage/contacto.sh <person_id>
C=$(docker ps --format '{{.Names}}' | grep heaven_kolberg_laravel | head -1)
OUT=$(docker exec -u www-data -e HOME=/tmp -e PERSON=$1 -w /code $C php artisan tinker storage/aud_todo_contacto.php 2>&1 | grep -v DEPRECATED)
echo "$OUT"
K=$(echo "$OUT" | head -1 | grep -o '"value": "[^"]*' | head -1 | sed 's/.*"//' | tr -cd '0-9' | tail -c 8)
N=heaven_fobo-n8n-chat-memory-ia.1.t79m6qyhcfd4hbyts0e2hlndg; U=$(docker exec $N printenv POSTGRES_USER)
echo "HISTORIAL IA ($K)"
[ -n "$K" ] && docker exec $N psql -U $U -d chat-memory -At -c "select '  '||case when message->>'type'='human' then 'C '||replace(substring(message->>'content' from 'Hoy: ([^\n]+)'),' 2026','')||' | '||replace(trim(substring(message->>'content' from 'Usuario:(.*)')),E'\n',' / ') else 'A      | '||left(replace(coalesce((message->>'content')::jsonb->'output'->>'mensaje',''),E'\n',' / '),140) end from n8n_chat_histories_kolhberg where session_id like '%$K@%' order by id" | cut -c1-280
