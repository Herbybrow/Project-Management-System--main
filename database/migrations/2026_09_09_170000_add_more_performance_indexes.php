<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing(
            'projects',
            'projects_lifecycle_stage_idx',
            ['lifecycle_stage']
        );

        $this->addIndexIfMissing(
            'projects',
            'projects_plan_status_idx',
            ['plan_status']
        );

        $this->addIndexIfMissing(
            'projects',
            'projects_actual_start_idx',
            ['actual_start_date']
        );

        $this->addIndexIfMissing(
            'projects',
            'projects_actual_end_idx',
            ['actual_end_date']
        );

        $this->addIndexIfMissing(
            'requirements',
            'requirements_review_decision_idx',
            ['review_decision']
        );

        $this->addIndexIfMissing(
            'requirements',
            'requirements_impl_status_idx',
            ['implementation_status']
        );

        $this->addIndexIfMissing(
            'requirements',
            'requirements_test_result_idx',
            ['test_result']
        );

        $this->addIndexIfMissing(
            'implementation_activities',
            'activities_planned_actual_idx',
            ['planned_start_date', 'actual_start_date']
        );

        $this->addIndexIfMissing(
            'reviews',
            'reviews_reviewed_at_idx',
            ['reviewed_at']
        );
    }

    public function down(): void
    {
        $this->dropIndexIfExists(
            'projects',
            'projects_lifecycle_stage_idx'
        );

        $this->dropIndexIfExists(
            'projects',
            'projects_plan_status_idx'
        );

        $this->dropIndexIfExists(
            'projects',
            'projects_actual_start_idx'
        );

        $this->dropIndexIfExists(
            'projects',
            'projects_actual_end_idx'
        );

        $this->dropIndexIfExists(
            'requirements',
            'requirements_review_decision_idx'
        );

        $this->dropIndexIfExists(
            'requirements',
            'requirements_impl_status_idx'
        );

        $this->dropIndexIfExists(
            'requirements',
            'requirements_test_result_idx'
        );

        $this->dropIndexIfExists(
            'implementation_activities',
            'activities_planned_actual_idx'
        );

        $this->dropIndexIfExists(
            'reviews',
            'reviews_reviewed_at_idx'
        );
    }

    private function addIndexIfMissing(
        string $table,
        string $indexName,
        array $columns
    ): void {
        if (Schema::hasIndex($table, $indexName) || Schema::hasIndex($table, $columns)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use (
            $indexName,
            $columns
        ) {
            $blueprint->index($columns, $indexName);
        });
    }

    private function dropIndexIfExists(
        string $table,
        string $indexName
    ): void {
        if (! Schema::hasIndex($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use (
            $indexName
        ) {
            $blueprint->dropIndex($indexName);
        });
    }

};
