<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah nilai enum `private_receive` ke kolom `type`.
 *
 * Transfer privat punya DUA sisi:
 *  - `private_transfer` → baris PENGIRIM (sudah ada). Pelaku = sender_wallet_id.
 *  - `private_receive`  → baris PENERIMA (baru). Pelaku = receiver_wallet_id;
 *    sender_wallet_id DIBIARKAN null demi privasi (penerima tidak tahu wallet
 *    pengirim — hanya menemukan note terenkripsi via scanIncomingNotes).
 *
 * Non-destruktif: hanya MELEBARKAN himpunan nilai enum, tidak ada data hilang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->enum('type', [
                'transfer', 'faucet', 'deposit', 'withdraw',
                'private_transfer', 'private_receive',
            ])->default('transfer')->change();
        });
    }

    public function down(): void
    {
        // Menyempitkan enum bisa gagal bila sudah ada baris `private_receive`.
        // Biarkan himpunan tetap lebar saat rollback (aman, tidak ada data hilang).
        Schema::table('transactions', function (Blueprint $table) {
            $table->enum('type', [
                'transfer', 'faucet', 'deposit', 'withdraw', 'private_transfer',
            ])->default('transfer')->change();
        });
    }
};
