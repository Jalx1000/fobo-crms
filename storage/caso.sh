#!/bin/sh
# uso: sh storage/caso.sh <lead>
C=$(docker ps --format '{{.Names}}' | grep heaven_kolberg_laravel | head -1)
OUT=$(docker exec -u www-data -e HOME=/tmp -e LEAD=$1 -w /code $C php artisan tinker storage/aud_caso.php 2>&1 | grep -v DEPRECATED)
echo "$OUT" | grep -v '^TEL '
T=$(echo "$OUT" | grep '^TEL ' | cut -c5-); K=$(echo "$T" | tail -c 9)
N=heaven_fobo-n8n-chat-memory-ia.1.t79m6qyhcfd4hbyts0e2hlndg; U=$(docker exec $N printenv POSTGRES_USER)
echo "HISTORIAL IA ($K)"
[ -n "$K" ] && docker exec $N psql -U $U -d chat-memory -At -c "select '  '||case when message->>'type'='human' then 'C '||replace(substring(message->>'content' from 'Hoy: ([^\n]+)'),' 2026','')||' | '||replace(trim(substring(message->>'content' from 'Usuario:(.*)')),E'\n',' / ') else 'A      | '||left(replace(coalesce((message->>'content')::jsonb->'output'->>'mensaje',''),E'\n',' / '),130) end from n8n_chat_histories_kolhberg where session_id like '%$K@%' order by id" | cut -c1-260
