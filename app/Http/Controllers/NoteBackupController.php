<?php
namespace App\Http\Controllers;

use App\Models\NoteBackup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Backup note terenkripsi (lintas device + anti-hilang). Server menyimpan
 * ciphertext OPAQUE + ref OPAQUE per-user — tak pernah plaintext/salt/nominal/
 * commitment. Kebenaran spendable tetap on-chain.
 */
class NoteBackupController extends Controller
{
    private const MAX_CIPHERTEXT = 4096; // base64 AES-GCM dari JSON note kecil
    private const MAX_BATCH = 200;

    public function store(Request $request)
    {
        $validated = $request->validate([
            'notes' => ['required', 'array', 'max:'.self::MAX_BATCH],
            'notes.*.ref' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'notes.*.ciphertext' => ['required', 'string', 'max:'.self::MAX_CIPHERTEXT],
        ]);

        $userId = Auth::id();
        $stored = 0;
        foreach ($validated['notes'] as $note) {
            NoteBackup::updateOrCreate(
                ['user_id' => $userId, 'ref' => $note['ref']],
                ['ciphertext' => $note['ciphertext']],
            );
            $stored++;
        }

        return response()->json(['success' => true, 'stored' => $stored]);
    }

    public function index()
    {
        $notes = NoteBackup::where('user_id', Auth::id())->get(['ref', 'ciphertext']);

        return response()->json(['success' => true, 'notes' => $notes]);
    }
}
