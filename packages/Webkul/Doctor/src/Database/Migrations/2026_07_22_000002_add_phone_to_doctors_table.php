<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doctors need a WhatsApp number of their own.
 *
 * ShareMeData only sends their name and email, so the number is loaded by hand
 * in the CRM. It is what lets the WhatsApp module open a conversation with a
 * doctor the same way it does with a patient.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('doctors', 'phone')) {
            Schema::table('doctors', function (Blueprint $table) {
                $table->string('phone')->nullable()->after('email');
            });
        }
    }

    public function down(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
