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
        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->increments('id');

            $table->string('wa_phone')->unique();
            $table->string('wa_name')->nullable();

            // null = inherit the global whatsapp.ai.enabled default
            $table->boolean('ai_enabled')->nullable();

            $table->string('status')->default('open'); // open | closed | unassigned
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);

            $table->unsignedInteger('person_id')->nullable();
            $table->foreign('person_id')->references('id')->on('persons')->onDelete('set null');

            $table->unsignedInteger('lead_id')->nullable();
            $table->foreign('lead_id')->references('id')->on('leads')->onDelete('set null');

            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_conversations');
    }
};
