<?php

// database/migrations/xxxx_xx_xx_xxxxxx_create_role_permission_table.php
namespace LaravelAdRbac\Database\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('role_permission', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('role_id')->constrained()->onDelete('cascade');
            $table->unsignedInteger('permission_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            // Indexes
            $table->index(['role_id', 'permission_id']);
            $table->unique(['role_id', 'permission_id']); // Prevent duplicates
        });
    }

    public function down()
    {
        Schema::dropIfExists('role_permission');
    }
};