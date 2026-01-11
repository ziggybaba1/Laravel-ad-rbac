<?php

// database/migrations/xxxx_xx_xx_xxxxxx_create_groups_table.php
namespace LaravelAdRbac\Database\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('parent_id')->nullable()->constrained('groups')->onDelete('cascade');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('slug');
            $table->index('parent_id');
            $table->index('is_system');
        });
    }

    public function down()
    {
        Schema::dropIfExists('groups');
    }
};