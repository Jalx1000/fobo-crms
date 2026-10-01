<?php

namespace Webkul\Messenger\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Contact\Models\PersonProxy;
use Webkul\Messenger\Models\SocialConnection;
use Webkul\Messenger\Services\MessengerProfile;
use Webkul\Whatsapp\Models\ConversationProxy;

/**
 * Rellena nombre y foto de los contactos de Messenger que entraron sin ellos.
 *
 * Normalmente no hace falta: MessengerProfile::known() cachea contra wa_name, y
 * si esta en null la busqueda se reintenta con cada mensaje, asi que un contacto
 * activo se arregla solo. Este comando es para los que no volvieron a escribir
 * (y para reparar en bloque despues de arreglar el token o los permisos).
 */
class BackfillProfiles extends Command
{
    protected $signature = 'messenger:backfill-profiles
        {--limit=100 : Cuantas conversaciones procesar como maximo}
        {--dry-run : Muestra lo que haria, sin escribir nada}
        {--force : Reintenta tambien las que ya tienen nombre}';

    protected $description = 'Rellena nombre y foto de perfil de los contactos de Messenger sin resolver.';

    public function handle(MessengerProfile $profiles): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $query = ConversationProxy::modelClass()::query()
            ->where('gateway', 'messenger')
            ->whereNotNull('provider_conversation_id');

        // Sin --force solo las que faltan. El OR incluye a las que tienen nombre
        // pero perdieron el avatar, que es un caso real: el nombre llego y la
        // copia local de la imagen fallo.
        if (! $this->option('force')) {
            $query->where(function ($q) {
                $q->whereNull('wa_name')->orWhereNull('avatar_url');
            });
        }

        $conversations = $query->orderBy('id')->limit((int) $this->option('limit'))->get();

        if ($conversations->isEmpty()) {
            $this->info('No hay conversaciones de Messenger para rellenar.');

            return self::SUCCESS;
        }

        $this->info("Procesando {$conversations->count()} conversacion(es)".($dryRun ? ' [DRY RUN]' : '').'.');

        $connections = SocialConnection::where('channel', 'messenger')->get();

        $resolved = $failed = $skipped = 0;

        foreach ($conversations as $conversation) {
            $connection = $connections->firstWhere('account_id', (string) $conversation->provider_account_id)
                ?? $connections->first();

            if (! $connection?->access_token) {
                $this->warn("#{$conversation->id}: sin conexion con token para la pagina {$conversation->provider_account_id}.");

                $skipped++;

                continue;
            }

            // fetch() cortocircuita si la conversacion ya tiene wa_name, asi que
            // con --force hay que saltear ese cache a mano.
            $profile = $this->option('force')
                ? $this->fetchIgnoringCache($profiles, $connection, $conversation)
                : $profiles->fetch($connection, (string) $conversation->provider_conversation_id);

            if (empty($profile['name']) && empty($profile['avatar_url'])) {
                $this->line("<fg=red>✗</> #{$conversation->id}  psid={$conversation->provider_conversation_id}  Graph no devolvio perfil (ver storage/logs para el error)");

                $failed++;

                continue;
            }

            $this->line("<fg=green>✓</> #{$conversation->id}  ".($profile['name'] ?? '(sin nombre)').($profile['avatar_url'] ? '  + foto' : ''));

            $resolved++;

            if ($dryRun) {
                continue;
            }

            $conversation->forceFill(array_filter([
                'wa_name'    => $profile['name'] ?: null,
                'avatar_url' => $profile['avatar_url'] ?: null,
            ]))->save();

            $this->renamePlaceholderPerson($conversation, $profile['name'] ?? null);
        }

        $this->newLine();
        $this->info("Resueltas: {$resolved}   Fallidas: {$failed}   Salteadas: {$skipped}");

        if ($failed > 0) {
            $this->warn('Las fallidas suelen ser el token de pagina sin el permiso vigente.');
            $this->line('  Verificar con: <info>./check_messenger_token.sh</info>');
        }

        return self::SUCCESS;
    }

    /**
     * El Person creado sin perfil quedo con una etiqueta generada ("Messenger
     * 232251"). Se reemplaza SOLO si sigue siendo exactamente esa etiqueta: un
     * nombre que un humano corrigio a mano no se pisa.
     */
    protected function renamePlaceholderPerson($conversation, ?string $name): void
    {
        if (! $name || ! $conversation->person_id) {
            return;
        }

        $person = PersonProxy::modelClass()::find($conversation->person_id);

        if (! $person) {
            return;
        }

        $placeholder = config('whatsapp.gateways.messenger.lead_source', 'Messenger')
            .' '.substr((string) $conversation->provider_conversation_id, -6);

        if ($person->name !== $placeholder) {
            return;
        }

        $person->update(['name' => $name]);

        $this->line("    person #{$person->id}: \"{$placeholder}\" -> \"{$name}\"");
    }

    /**
     * Con --force el cache de known() estorba: se limpia wa_name en memoria no
     * alcanza porque known() relee de la base, asi que se consulta Graph por
     * fuera del servicio.
     */
    protected function fetchIgnoringCache(MessengerProfile $profiles, SocialConnection $connection, $conversation): array
    {
        $original = $conversation->wa_name;

        $conversation->forceFill(['wa_name' => null])->save();

        $profile = $profiles->fetch($connection, (string) $conversation->provider_conversation_id);

        if (empty($profile['name'])) {
            $conversation->forceFill(['wa_name' => $original])->save();
        }

        return $profile;
    }
}
