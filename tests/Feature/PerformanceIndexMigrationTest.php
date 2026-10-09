<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PerformanceIndexMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_migration_can_be_repeated_with_existing_indexes(): void
    {
        $migration = require database_path('migrations/2026_09_09_170000_add_more_performance_indexes.php');
        $before = Schema::getIndexes('projects');
        $migration->up();
        $this->assertSame($before, Schema::getIndexes('projects'));
        $this->assertTrue(Schema::hasIndex('projects', ['lifecycle_stage']));
        $this->assertTrue(Schema::hasIndex('reviews', ['reviewed_at']));
        $this->assertTrue(Schema::hasIndex('implementation_activities', ['planned_start_date', 'actual_start_date']));
    }
}
