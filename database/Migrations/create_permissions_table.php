<?php

// database/migrations/xxxx_xx_xx_xxxxxx_create_permissions_table.php
namespace LaravelAdRbac\Database\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        if (!Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('action'); // create, read, update, delete, approve, assign, etc.
                $table->string('module'); // Model class name
                $table->text('description')->nullable();
                $table->string('category')->nullable();
                $table->boolean('is_system')->default(false);
                $table->timestamps();
                $table->softDeletes();

                // Indexes
                $table->index('slug');
                $table->index('action');
                $table->index('module');
                $table->index('category');
                $table->index('is_system');
                $table->unique(['module', 'action']); // Prevent duplicate permissions per model
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('permissions');
    }
};