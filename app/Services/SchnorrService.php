<?php

namespace App\Services;

use BN\BN;
use Elliptic\EC;

/**
 * Schnorr signature service atas secp256k1.
 *
 * Variant: non-interaktif (Fiat-Shamir). Mengikuti
 * - private_key = SHA-256("schnorr_v1:" || lc(email) || ":" || password) mod n
 * - public_key = private_key · G (compressed point hex, 66 char)
 * - sign(msg)
 * k = SHA-256(privBytes || msg) mod n (deterministic)
 * R = k · G (compressed hex)
 * e = SHA-256(R || pubKey || msg) mod n
 * s = (k + e · privKey) mod n
 * signature = R_hex (66) || s_hex (64) → 130 hex chars
 * - verify(pubKey, msg, sig): cek s·G == R + e·pubKey
 *
 * Library: simplito/elliptic-php (secp256k1 + BN).
 */
class SchnorrService
{
    private EC $ec;

    public function __construct()
    {
        $this->ec = new EC('secp256k1');
    }

    public function derivePrivateKey(string $email, string $password): string
    {
        $seedBytes = hash('sha256', 'schnorr_v1:'.strtolower($email).':'.$password, true);
        return $this->bnToHex64($this->reduceToScalar($seedBytes));
    }

    public function derivePublicKey(string $privateKeyHex): string
    {
        $key = $this->ec->keyFromPrivate($privateKeyHex, 'hex');
        return $key->getPublic(true, 'hex');
    }

    public function sign(string $privateKeyHex, string $message): string
    {
        $priv = new BN($privateKeyHex, 16);
        $privBytes = hex2bin(str_pad($privateKeyHex, 64, '0', STR_PAD_LEFT));

        $k = $this->reduceToScalar(hash('sha256', $privBytes.$message, true));

        $R = $this->ec->g->mul($k);
        $rHex = $R->encode('hex', true);

        $pubHex = $this->derivePublicKey($privateKeyHex);

        $eInput = hex2bin($rHex).hex2bin($pubHex).$message;
        $e = $this->reduceToScalar(hash('sha256', $eInput, true));

        $s = $k->add($e->mul($priv))->umod($this->ec->n);

        return $rHex.$this->bnToHex64($s);
    }

    public function verify(string $publicKeyHex, string $message, string $signatureHex): bool
    {
        if (strlen($signatureHex) !== 130 || !ctype_xdigit($signatureHex)) {
            return false;
        }
        if (!preg_match('/^(02|03)[0-9a-f]{64}$/i', $publicKeyHex)) {
            return false;
        }

        try {
            $rHex = substr($signatureHex, 0, 66);
            $sHex = substr($signatureHex, 66, 64);

            $s = new BN($sHex, 16);
            if ($s->isZero() || $s->cmp($this->ec->n) >= 0) {
                return false;
            }

            $R = $this->ec->curve->decodePoint($rHex, 'hex');
            $P = $this->ec->curve->decodePoint($publicKeyHex, 'hex');

            $eInput = hex2bin($rHex).hex2bin($publicKeyHex).$message;
            $e = $this->reduceToScalar(hash('sha256', $eInput, true));

            $lhs = $this->ec->g->mul($s);
            $rhs = $R->add($P->mul($e));

            return $lhs->eq($rhs);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Reduce 32 random bytes ke skalar di [1, n-1].
     */
    private function reduceToScalar(string $bytes): BN
    {
        $bn = new BN(bin2hex($bytes), 16);
        $bn = $bn->umod($this->ec->n);
        if ($bn->isZero()) {
            $bn = new BN(1);
        }
        return $bn;
    }

    private function bnToHex64(BN $bn): string
    {
        return str_pad($bn->toString(16), 64, '0', STR_PAD_LEFT);
    }
}
