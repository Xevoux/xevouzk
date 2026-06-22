<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah kolom `type` ke transactions supaya riwayat bisa membedakan
 * transfer biasa, faucet, deposit/withdraw pool, dan transfer privat.
 *
 * Non-destruktif:
 *  - menambah kolom baru (default 'transfer' untuk baris lama),
 *  - melonggarkan sender_wallet_id jadi nullable (faucet = penerima saja, tanpa
 *    sender wallet XevouZK),
 *  - melonggarkan amount jadi nullable (transfer PRIVAT tidak menyimpan nominal —
 *    nominal disembunyikan demi klaim privasi; server hanya tahu tx terjadi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->enum('type', ['transfer', 'faucet', 'deposit', 'withdraw', 'private_transfer'])
                ->default('transfer')
                ->after('id');
        });

        Schema::table('transactions', function (Blueprint $table) {
            // Faucet masuk sebagai RECV tanpa sender wallet XevouZK.
            $table->unsignedBigInteger('sender_wallet_id')->nullable()->change();
            // Transfer privat: nominal disembunyikan (null) demi privasi.
            $table->decimal('amount', 20, 8)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('type');
        });
        // Kolom sender_wallet_id / amount dibiarkan nullable saat rollback —
        // mengembalikan NOT NULL bisa gagal bila sudah ada baris faucet/privat.
    }
};
