<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 64)->unique()->comment('Machine name, e.g. cashier');
            $table->string('display_name');
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('level')->default(0)->comment('Higher = more privileged');
            $table->timestampsTz();
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 96)->unique()->comment('e.g. orders.void');
            $table->string('group', 64);
            $table->string('description')->nullable();
            $table->timestampsTz();
        });

        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['role_id', 'model_id', 'model_type']);
            $table->index(['model_id', 'model_type'], 'idx_model_has_roles_model');
        });

        Schema::create('user_outlets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();

            $table->unique(['user_id', 'outlet_id'], 'uq_user_outlets');
            $table->index('outlet_id', 'idx_user_outlets_outlet');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_outlets');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
