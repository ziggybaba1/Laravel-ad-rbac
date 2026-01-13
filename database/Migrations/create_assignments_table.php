<?php

// database/migrations/xxxx_xx_xx_xxxxxx_create_assignments_table.php
namespace LaravelAdRbac\Database\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        if (!Schema::hasTable('assignments')) {
            Schema::create('assignments', function (Blueprint $table) {
                $table->id();

                // Employee reference
                $table->unsignedInteger('employee_id')->constrained('employees')->onDelete('cascade');

                // Polymorphic relationships for assignable items
                $table->morphs('assignable'); // Can be Group, Role, or Permission

                // Assignment type: group, role, permission
                // $table->enum('assignable_type', ['group', 'role', 'permission']);

                // Assignment details
                $table->string('assignment_reason')->nullable();
                $table->unsignedInteger('assigned_by')->nullable()->constrained('employees')->onDelete('set null');
                $table->timestamp('assigned_at')->useCurrent();
                $table->timestamp('expires_at')->nullable();
                $table->boolean('is_active')->default(true);

                // Timestamps
                $table->timestamps();

                // Indexes
                $table->index(['employee_id', 'assignable_type']);
                $table->index(['assignable_id', 'assignable_type']);
                $table->index('assigned_by');
                $table->index('expires_at');
                $table->index('is_active');
                $table->unique(['employee_id', 'assignable_id', 'assignable_type'], 'unique_assignment');
            });
        }

        if (!Schema::hasTable('assignment_history')) {
            // Create history table for tracking changes
            Schema::create('assignment_history', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('assignment_id')->constrained('assignments')->onDelete('cascade');
                $table->json('changes');
                $table->unsignedInteger('changed_by')->nullable()->constrained('employees')->onDelete('set null');
                $table->timestamps();
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('assignment_history');
        Schema::dropIfExists('assignments');
    }
};