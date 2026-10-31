<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('specialties')) {
            Schema::table('specialties', function (Blueprint $table) {
                if (! Schema::hasColumn('specialties', 'description')) {
                    $table->text('description')->nullable();
                }

                if (! Schema::hasColumn('specialties', 'code')) {
                    $table->string('code')->unique();
                }

                if (! Schema::hasColumn('specialties', 'area_of_knowledge')) {
                    $table->string('area_of_knowledge');
                }

                if (! Schema::hasColumn('specialties', 'prerequisites')) {
                    $table->text('prerequisites')->nullable();
                }

                if (! Schema::hasColumn('specialties', 'estimated_duration_minutes')) {
                    $table->integer('estimated_duration_minutes')->nullable();
                }

                if (! Schema::hasColumn('specialties', 'difficulty_level')) {
                    $table->string('difficulty_level')->nullable();
                }

                if (! Schema::hasColumn('specialties', 'image_path')) {
                    $table->string('image_path')->nullable();
                }

                if (! Schema::hasColumn('specialties', 'learning_objectives')) {
                    $table->json('learning_objectives')->nullable();
                }

                if (! Schema::hasColumn('specialties', 'evaluation_criteria')) {
                    $table->json('evaluation_criteria')->nullable();
                }

                if (! Schema::hasColumn('specialties', 'approval_requirements')) {
                    $table->json('approval_requirements')->nullable();
                }

                if (! Schema::hasColumn('specialties', 'certification_rules')) {
                    $table->json('certification_rules')->nullable();
                }

                if (! Schema::hasColumn('specialties', 'content_structure')) {
                    $table->json('content_structure')->nullable();
                }

                if (! Schema::hasColumn('specialties', 'deadline_start')) {
                    $table->date('deadline_start')->nullable();
                }

                if (! Schema::hasColumn('specialties', 'deadline_end')) {
                    $table->date('deadline_end')->nullable();
                }

                if (! Schema::hasColumn('specialties', 'publication_status')) {
                    $table->string('publication_status')->default('draft')->index();
                }

                if (! Schema::hasColumn('specialties', 'instructor_id')) {
                    $table->unsignedInteger('instructor_id')->nullable();
                    $table->foreign('instructor_id')->references('id')->on('users')->onDelete('set null');
                }
            });
        }

        if (! Schema::hasTable('specialty_activities')) {
            Schema::create('specialty_activities', function (Blueprint $table) {
                $table->unsignedBigInteger('specialty_id');
                $table->unsignedInteger('activity_id');

                $table->foreign('specialty_id')->references('id')->on('specialties')->onDelete('cascade');
                $table->foreign('activity_id')->references('id')->on('activities')->onDelete('cascade');

                $table->primary(['specialty_id', 'activity_id']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('specialty_activities')) {
            Schema::dropIfExists('specialty_activities');
        }

        if (Schema::hasTable('specialties')) {
            Schema::table('specialties', function (Blueprint $table) {
                // Drop foreign keys safely
                if (Schema::hasColumn('specialties', 'instructor_id')) {
                    try {
                        $table->dropForeign(['instructor_id']);
                    } catch (\Throwable $e) {
                        // ignore
                    }
                    $table->dropColumn('instructor_id');
                }

                foreach ([
                    'description',
                    'code',
                    'area_of_knowledge',
                    'prerequisites',
                    'estimated_duration_minutes',
                    'difficulty_level',
                    'image_path',
                    'learning_objectives',
                    'evaluation_criteria',
                    'approval_requirements',
                    'certification_rules',
                    'content_structure',
                    'deadline_start',
                    'deadline_end',
                    'publication_status',
                ] as $column) {
                    if (Schema::hasColumn('specialties', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
