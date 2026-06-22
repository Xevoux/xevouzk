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
        Schema::create('qr_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code_id', 50)->unique()->comment('Unique QR code identifier');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('wallet_address', 42)->comment('Recipient wallet address');
            $table->decimal('amount', 20, 8)->default(0)->comment('Payment amount');
            $table->string('currency', 10)->default('MATIC');
            $table->text('description')->nullable();
            $table->json('qr_data')->comment('Encrypted QR data');
            $table->string('signature', 64)->comment('HMAC signature for authenticity');
            $table->timestamp('expires_at')->comment('QR code expiration time');
            $table->timestamp('used_at')->nullable()->comment('When QR was scanned and used');
            $table->enum('status', ['active', 'used', 'expired', 'cancelled'])->default('active');
            $table->foreignId('transaction_id')->nullable()->constrained()->onDelete('set null');
            $table->timestamps();
            
            // Indexes
            $table->index(['user_id', 'status']);
            $table->index(['code_id', 'status']);
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qr_codes');
    }
};
