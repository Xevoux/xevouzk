<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('note_backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('ref', 64); // sha256("xevou-note-backup-v1:"+commitment+":"+salt) — opaque
            $table->text('ciphertext'); // base64 AES-GCM, server tak bisa baca
            $table->timestamps();
            $table->unique(['user_id', 'ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('note_backups');
    }
};
