<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_crm_staff65', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('role', 24);
            $table->boolean('active')->default(true);
            $table->timestampsTz(6);
        });
        DB::statement("ALTER TABLE checkout_crm_staff65 ADD CONSTRAINT crm65_roles_check CHECK (role IN ('owner','manager','support','finance','fiscal','viewer'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_crm_staff65');
    }
};
