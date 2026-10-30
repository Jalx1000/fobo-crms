<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('conversation_id');
            $table->foreign('conversation_id')->references('id')->on('whatsapp_conversations')->onDelete('cascade');

            $table->string('direction'); // inbound | outbound
            $table->string('type')->default('text'); // text|image|document|audio|video|sticker|location|contact

            $table->text('body')->nullable();

            $table->string('media_path')->nullable();
            $table->string('media_mime')->nullable();
            $table->string('media_name')->nullable();

            // WhatsApp id (wamid). Unique for idempotency; null while outbound is queued.
            $table->string('wa_message_id')->nullable()->unique();

            $table->unsignedInteger('reply_to_id')->nullable();
            $table->foreign('reply_to_id')->references('id')->on('whatsapp_messages')->onDelete('set null');

            $table->string('status')->nullable(); // queued|sent|delivered|read|failed
            $table->string('sender')->nullable(); // contact|ia|agent|human|system

            $table->json('payload')->nullable();

            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
