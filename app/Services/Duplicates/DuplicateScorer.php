<?php

namespace App\Services\Duplicates;

use App\Support\Normalize;

class DuplicateScorer
{
    /**
     * @param array $incoming  payload registrasi baru (instansi, sekolahId, email, wa, alamat, kota, provinsi)
     * @param array $candidate payload kandidat yang sudah ada (field sama di atas)
     * @return array ['score'=>int, 'reason'=>string]
     */
    public function score(array $incoming, array $candidate): array
    {
        $score = 0;
        $reasons = [];

        // Hard match
        if (!empty($incoming['sekolahId']) && !empty($candidate['sekolahId'])
            && $incoming['sekolahId'] === $candidate['sekolahId']) {
            $score += 100;
            $reasons[] = 'sekolahId';
        } else {
            // instansi + kota + provinsi
            $i1 = Normalize::text($incoming['instansi'] ?? '');
            $i2 = Normalize::text($candidate['instansi'] ?? '');
            $k1 = Normalize::text($incoming['kota'] ?? '');
            $k2 = Normalize::text($candidate['kota'] ?? '');
            $p1 = Normalize::text($incoming['provinsi'] ?? '');
            $p2 = Normalize::text($candidate['provinsi'] ?? '');

            if ($i1 !== '' && $i1 === $i2 && $k1 !== '' && $k1 === $k2 && $p1 !== '' && $p1 === $p2) {
                $score += 90;
                $reasons[] = 'instansi+kota+provinsi';
            }
        }

        // Soft match boosters
        $e1 = Normalize::text($incoming['email'] ?? '');
        $e2 = Normalize::text($candidate['email'] ?? '');
        if ($e1 !== '' && $e1 === $e2) {
            $score += 20; $reasons[] = 'email';
        }

        $w1 = Normalize::phone($incoming['wa'] ?? '');
        $w2 = Normalize::phone($candidate['wa'] ?? '');
        if ($w1 !== '' && $w1 === $w2) {
            $score += 20; $reasons[] = 'wa';
        }

        $score += Normalize::substringOverlapScore($incoming['alamat'] ?? '', $candidate['alamat'] ?? '');
        if (end($reasons) !== 'alamat' && Normalize::substringOverlapScore($incoming['alamat'] ?? '', $candidate['alamat'] ?? '') > 0) {
            $reasons[] = 'alamat';
        }

        return [
            'score'  => $score,
            'reason' => implode(', ', $reasons) ?: 'none',
        ];
    }
}
