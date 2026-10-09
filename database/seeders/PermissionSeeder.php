<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Process 1: Initiation & Registration (Your Scope)
            ['code' => 'projects.register', 'name' => 'Register New Project', 'module' => 'initiation', 'description' => 'Register a project from DICT annual plan'],
            ['code' => 'projects.review', 'name' => 'Review Registered Project', 'module' => 'initiation', 'description' => 'Review initial project submission'],
            ['code' => 'projects.assign_planner', 'name' => 'Assign Project Planner', 'module' => 'initiation', 'description' => 'Assign project to a planner'],
            ['code' => 'projects.reassign_planner', 'name' => 'Reassign Project Planner', 'module' => 'initiation', 'description' => 'Reassign project to another planner'],
            ['code' => 'projects.view_all', 'name' => 'View All Projects', 'module' => 'initiation', 'description' => 'View all registered projects across organization'],
            ['code' => 'documents.upload_initial', 'name' => 'Upload Initial Documents', 'module' => 'initiation', 'description' => 'Upload required initiation documents'],

            // Other Stages (Other Developers)
            ['code' => 'projects.plan', 'name' => 'Prepare Implementation Plan', 'module' => 'planning', 'description' => 'Prepare implementation activities'],
            ['code' => 'requirements.submit', 'name' => 'Submit Requirements', 'module' => 'planning', 'description' => 'Submit SRS requirements'],
            ['code' => 'projects.recommend', 'name' => 'Recommend Project Execution', 'module' => 'coordination', 'description' => 'Recommend execution stage'],
            ['code' => 'projects.approve', 'name' => 'Approve Project', 'module' => 'approval', 'description' => 'Approve or reject project'],
            ['code' => 'projects.close', 'name' => 'Close Project', 'module' => 'closure', 'description' => 'Close a project after readiness gates pass'],
            ['code' => 'admin.manage_users', 'name' => 'Manage Users and Roles', 'module' => 'admin', 'description' => 'Manage accounts, roles, and permission overrides'],
        ];

        foreach ($permissions as $perm) {
            Permission::updateOrCreate(['code' => $perm['code']], $perm);
        }
    }
}
