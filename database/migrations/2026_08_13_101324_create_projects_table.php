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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('annual_plan_reference')->nullable();
            $table->string('category')->nullable();
            $table->string('project_type')->nullable();
            $table->string('activity_name')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->float('budget', 12, 2)->nullable();
            $table->string('team_type')->nullable();
            $table->foreignId('planner_id')->nullable()->constrained('users');
            $table->foreignId('reviewer_id')->nullable()->constrained('users');
            $table->foreignId('coordinator_id')->nullable()->constrained('users');
            $table->string('status')->default('draft');
            $table->string('phase')->nullable();
            $table->date('planned_start_date')->nullable();
            $table->date('planned_end_date')->nullable();
            $table->date('actual_start_date')->nullable();
            $table->date('actual_end_date')->nullable();
            $table->decimal('overall_implementation_score', 5, 2)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
