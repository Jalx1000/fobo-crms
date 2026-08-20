<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $now = Carbon::now();

        // Check if attribute already exists for persons
        $exists = DB::table('attributes')
            ->where('code', 'sucursal')
            ->where('entity_type', 'persons')
            ->exists();

        if ($exists) {
            return;
        }

        // Try to copy definition from leads attribute
        $leadAttribute = DB::table('attributes')
            ->where('code', 'sucursal')
            ->where('entity_type', 'leads')
            ->first();

        if ($leadAttribute) {
            DB::table('attributes')->insert([
                'code'            => 'sucursal',
                'name'            => $leadAttribute->name,
                'type'            => $leadAttribute->type,
                'entity_type'     => 'persons',
                'lookup_type'     => $leadAttribute->lookup_type,
                'validation'      => $leadAttribute->validation,
                'sort_order'      => $leadAttribute->sort_order,
                'is_required'     => 0,
                'is_unique'       => 0,
                'quick_add'       => 1,
                'is_user_defined' => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        } else {
            // Fallback: Create a text attribute
            DB::table('attributes')->insert([
                'code'            => 'sucursal',
                'name'            => 'Sucursal',
                'type'            => 'text',
                'entity_type'     => 'persons',
                'lookup_type'     => null,
                'validation'      => null,
                'sort_order'      => 10,
                'is_required'     => 0,
                'is_unique'       => 0,
                'quick_add'       => 1,
                'is_user_defined' => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::table('attributes')
            ->where('code', 'sucursal')
            ->where('entity_type', 'persons')
            ->delete();
    }
};
