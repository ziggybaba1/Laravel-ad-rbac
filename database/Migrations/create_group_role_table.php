<?php

// database/migrations/xxxx_xx_xx_xxxxxx_create_group_role_table.php
namespace LaravelAdRbac\Database\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('group_role', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('group_id')->constrained()->onDelete('cascade');
            $table->unsignedInteger('role_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            // Indexes
            $table->index(['group_id', 'role_id']);
            $table->unique(['group_id', 'role_id']); // Prevent duplicates
        });
    }

    public function down()
    {
        Schema::dropIfExists('group_role');
    }
};