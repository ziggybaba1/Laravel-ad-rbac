<?php

// database/migrations/xxxx_xx_xx_xxxxxx_create_employees_table.php
namespace LaravelAdRbac\Database\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        if (!Schema::hasTable('employees')) {
            Schema::create('employees', function (Blueprint $table) {
                $table->id();
                $table->string('ad_username')->unique();
                $table->string('employee_id')->unique()->nullable();
                $table->string('email')->unique();
                $table->string('first_name');
                $table->string('last_name');
                $table->string('department')->nullable();
                $table->string('position')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_login_at')->nullable();
                $table->timestamp('ad_sync_at')->nullable();
                $table->rememberToken();
                $table->timestamps();
                $table->softDeletes();

                // Indexes
                $table->index('ad_username');
                $table->index('email');
                $table->index('department');
                $table->index('is_active');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('employees');
    }
};