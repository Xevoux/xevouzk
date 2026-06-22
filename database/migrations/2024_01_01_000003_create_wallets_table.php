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
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->onDelete('cascade');
            
            // Internal wallet address (for app reference only)
            $table->string('wallet_address')->unique()->comment('Internal app reference: ZKWALLET...');
            
            // Blockchain wallet (real Polygon address)
            $table->string('polygon_address', 42)->nullable()->unique()->comment('Real Polygon blockchain address: 0x...');
            
            // Cryptographic keys — public-only (non-custodial)
            $table->string('public_key')->comment('Uncompressed public key (130 hex chars)');

            // Shielded (Poseidon) public key untuk catatan terenkripsi penerima
            $table->string('shield_pub')->nullable()->comment('Poseidon shielded public key (decimal string)');

            // Single balance - fetched from blockchain (no local manipulation)
            $table->decimal('balance', 20, 18)->default(0)->comment('Balance in MATIC (synced from blockchain)');
            
            // ZK proof commitment for privacy
            $table->text('zk_proof_commitment')->nullable();
            
            // Sync tracking
            $table->timestamp('last_sync_at')->nullable()->comment('Last time balance was synced from blockchain');
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();

            // Indexes for performance
            $table->index('wallet_address');
            $table->index('polygon_address');
            $table->index(['user_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
