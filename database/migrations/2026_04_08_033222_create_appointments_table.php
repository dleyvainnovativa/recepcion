<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();

            $table->string('client_name');
            $table->string('client_phone');

            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();

            $table->dateTime('start_time');
            $table->dateTime('end_time');

            $table->enum('status', ['scheduled', 'cancelled', 'completed'])->default('scheduled');

            $table->boolean('reminder_sent')->default(false);

            $table->timestamps();

            $table->index(['employee_id', 'start_time']);
            $table->index(['branch_id', 'start_time']);
            $table->index('client_phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
