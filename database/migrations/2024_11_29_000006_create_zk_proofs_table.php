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
        Schema::create('zk_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('transaction_id')->nullable()->constrained()->onDelete('set null');
            $table->enum('proof_type', ['login', 'balance', 'transaction', 'membership'])->default('transaction');
            $table->json('proof_data')->comment('ZK proof components (pi_a, pi_b, pi_c)');
            $table->json('public_inputs')->comment('Public inputs for verification');
            $table->enum('verification_status', ['pending', 'verified', 'failed'])->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->string('nullifier', 66)->nullable()->unique()->comment('Prevent double-spending');
            $table->string('commitment', 66)->nullable()->comment('Pedersen commitment');
            $table->timestamps();
            
            // Indexes
            $table->index(['user_id', 'proof_type']);
            $table->index(['verification_status', 'created_at']);
            $table->index('nullifier');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zk_proofs');
    }
};
