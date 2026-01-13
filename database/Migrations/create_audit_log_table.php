<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table) {
                $table->id();
                $table->string('event');

                // These already create indexes internally
                $table->morphs('auditable'); // Polymorphic relation to the model
                $table->nullableMorphs('causer'); // Who performed the action (user, system)

                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent')->nullable();
                $table->string('url')->nullable();
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->json('changed_fields')->nullable();
                $table->text('description')->nullable();
                $table->string('action'); // CRUD operation
                $table->string('model_type'); // Model class
                $table->json('properties')->nullable(); // Additional metadata
                $table->timestamps();

                // These are fine
                // $table->index(['auditable_type', 'auditable_id']);
                // $table->index(['causer_type', 'causer_id']);
                $table->index('event');
                $table->index('action');
                $table->index('model_type');
                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};