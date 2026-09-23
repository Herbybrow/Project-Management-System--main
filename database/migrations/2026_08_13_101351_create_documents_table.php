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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->foreignId('activity_id')->nullable()->constrained('implementation_activities')->onDelete('set null');
            $table->foreignId('requirement_id')->nullable()->constrained('requirements')->onDelete('set null');
            $table->string('document_type')->nullable();
            $table->string('phase')->nullable();
            $table->string('file_name');
            $table->string('file_url');
            $table->string('file_type')->nullable();
            $table->integer('file_size')->nullable();
            $table->integer('version_number')->default(1);
            $table->boolean('is_current')->default(true);
            $table->string('review_status')->nullable();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamp('uploaded_at')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
