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
        Schema::create('faucet_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('wallet_address');
            $table->decimal('amount', 20, 8)->default(0);
            $table->string('tx_hash')->nullable();
            $table->boolean('is_simulation')->default(false);
            $table->timestamp('created_at');
            
            // Indexes untuk query performa
            $table->index(['user_id', 'created_at']);
            $table->index('wallet_address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('faucet_requests');
    }
};

