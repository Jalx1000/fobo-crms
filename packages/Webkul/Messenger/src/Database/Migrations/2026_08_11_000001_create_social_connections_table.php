<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Connected social accounts - for Messenger, one row per Facebook page.
 *
 * This is the only schema the channel owns: conversations and messages live in
 * the shared inbox tables. What cannot live there is the per-page credential,
 * because an installation may connect several pages and each has its own token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_connections', function (Blueprint $table) {
            $table->increments('id');
            $table->string('channel')->index();      // matches a gateway key
            $table->string('account_id');            // page id for Messenger
            $table->string('name')->nullable();
            $table->text('access_token')->nullable(); // encrypted by the model
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_connections');
    }
};
