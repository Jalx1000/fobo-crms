<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_quick_replies', function (Blueprint $table) {
            $table->increments('id');

            // null = global (visible to the whole team); set = personal.
            $table->integer('user_id')->unsigned()->nullable();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // What the advisor types after "/" to find it, e.g. "saludo".
            $table->string('shortcut');
            $table->string('title')->nullable();
            $table->text('content');

            $table->timestamps();

            $table->index('user_id', 'wa_qr_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_quick_replies');
    }
};
