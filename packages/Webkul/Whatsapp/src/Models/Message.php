<?php

namespace Webkul\Whatsapp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Whatsapp\Contracts\Message as MessageContract;

class Message extends Model implements MessageContract
{
    protected $table = 'whatsapp_messages';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'conversation_id',
        'direction',
        'type',
        'body',
        'media_path',
        'media_mime',
        'media_name',
        'wa_message_id',
        'reply_to_id',
        'status',
        'error',
        'sender',
        'payload',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'payload' => 'array',
    ];

    /**
     * El payload a JSON sin reventar por un carácter UTF-8 inválido: se reemplaza
     * (U+FFFD) en vez de tirar JsonEncodingException. Un emoji cortado del agente
     * hacía fallar con 500 el guardado de su respuesta.
     */
    protected function asJson($value)
    {
        return json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ConversationProxy::modelClass(), 'conversation_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(MessageProxy::modelClass(), 'reply_to_id');
    }
}
