<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('username', 100)->unique();
            $table->string('email', 255)->nullable()->unique();
            $table->string('password');
            $table->string('role', 30)->default('viewer')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE admin_users ADD CONSTRAINT admin_users_role_check CHECK (role IN ('super_admin', 'sla_manager', 'viewer'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_users');
    }
};
