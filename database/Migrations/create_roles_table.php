<?php

// database/migrations/xxxx_xx_xx_xxxxxx_create_roles_table.php
namespace LaravelAdRbac\Database\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        if (!Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->unsignedInteger('group_id')->nullable()->constrained('groups')->onDelete('set null');
                $table->boolean('is_system')->default(false);
                $table->timestamps();
                $table->softDeletes();

                // Indexes
                $table->index('slug');
                $table->index('group_id');
                $table->index('is_system');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('roles');
    }
};