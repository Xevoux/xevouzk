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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_wallet_id')->constrained('wallets')->onDelete('cascade');
            // Penerima nullable: transfer relay non-custodial bisa ke alamat 0x
            // eksternal yang belum tentu wallet XevouZK. nullOnDelete supaya hapus
            // wallet penerima tidak ikut menghapus riwayat milik pengirim.
            $table->foreignId('receiver_wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
            $table->string('receiver_address', 42)->nullable()->comment('Alamat 0x penerima eksternal (non-XevouZK)');
            $table->decimal('amount', 20, 8);
            $table->string('transaction_hash')->unique();
            $table->string('polygon_tx_hash')->nullable();
            $table->text('zk_proof')->nullable(); // ZK Proof untuk privasi transaksi
            $table->text('zk_public_inputs')->nullable(); // Public inputs untuk verifikasi
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
            $table->string('qr_code')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index(['sender_wallet_id', 'status']);
            $table->index(['receiver_wallet_id', 'status']);
            $table->index(['created_at', 'status']);
            $table->index('polygon_tx_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};

