<?php

namespace App\Console\Commands;

use App\Models\Sekolah;
use App\Models\Yayasan;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportMasterData extends Command
{
    protected $signature = 'master:import 
        {--yayasan= : Path Excel yayasan (default: storage/app/master/nama yayasan mpk fix.xlsx)} 
        {--sekolah= : Path Excel sekolah (default: storage/app/master/Raw_Data Yayasan dan Sekolahnya_linkedID.xlsx)}';

    protected $description = 'Import master Yayasan & Sekolah dari Excel (upsert, idempotent, FK-safe)';

    public function handle(): int
    {
        $yayasanPath = $this->option('yayasan') ?: storage_path('app/master/nama yayasan mpk fix.xlsx');
        $sekolahPath = $this->option('sekolah') ?: storage_path('app/master/Raw_Data Yayasan dan Sekolahnya_linkedID.xlsx');

        /** ========================
         *  YAYASAN
         *  ======================== */
        $this->info("Import Yayasan dari: {$yayasanPath}");
        $yWb = IOFactory::load($yayasanPath);
        $yRows = $yWb->getActiveSheet()->toArray(null, true, true, true); // keys A,B,C...
        if (count($yRows) < 2) { $this->error('Sheet Yayasan kosong.'); return self::FAILURE; }

        // header baris-1, normalisasi (lowercase + trim spasi)
        $yHeader = $this->normalizeHeaderRow($yRows[1]);
        $yIndex  = $this->buildIndexNormalized($yHeader); // ex: 'id' => 'A', 'nama yayasan' => 'B'

        $countY = 0;
        for ($i = 2; $i <= count($yRows); $i++) {
            $row = $yRows[$i] ?? [];

            // coba ambil by nama header (dengan sinonim), fallback huruf kolom
            $rawId   = $this->cellN($row, $yIndex, ['id','kode','yayasan id'], 'B');
            $rawName = $this->cellN($row, $yIndex, ['nama yayasan','nama','yayasan'], 'A');

            $id   = trim((string)$rawId);
            $name = trim((string)$rawName);

            // kalau kebalik (id berisi teks panjang & name angka) → swap
            if (($id !== '' && !preg_match('/^\d+$/', $id)) && preg_match('/^\d+$/', $name)) {
                [$id, $name] = [$name, $id];
            }

            if (!$id || !$name) continue;

            Yayasan::updateOrCreate(['id' => $id], ['name' => $name]);
            $countY++;
        }
        $this->info("Yayasan diimport: {$countY}");

        // cache yayasan untuk validasi FK & fallback by-name
        $yayasanAll = Yayasan::all(['id','name']);
        $yayasanIds = $yayasanAll->pluck('id')->map(fn($v) => (string)$v)->flip();
        $yayasanByName = $yayasanAll->mapWithKeys(fn($y) => [ strtolower(trim($y->name)) => (string)$y->id ]);

        /** ========================
         *  SEKOLAH
         *  ======================== */
        $this->info("Import Sekolah dari: {$sekolahPath}");
        $sWb   = IOFactory::load($sekolahPath);
        $sRows = $sWb->getActiveSheet()->toArray(null, true, true, true);
        if (count($sRows) < 3) { $this->error('Sheet Sekolah kosong / header tidak di baris 2.'); return self::FAILURE; }

        // header baris-2
        $sHeader = $this->normalizeHeaderRow($sRows[2]);
        $sIndex  = $this->buildIndexNormalized($sHeader);

        $countS = 0; $skipped = 0; $skippedRows = [];
        for ($i = 3; $i <= count($sRows); $i++) {
            $row = $sRows[$i] ?? [];

            $yayasanIdRaw   = $this->cellN($row, $sIndex, ['yayasan'], 'A'); // biasanya kolom id yayasan
            $yayasanNameRaw = $this->cellN($row, $sIndex, ['nama yayasan','yayasan nama'], null);

            $npsnRaw   = $this->cellN($row, $sIndex, ['npsn'], 'B');
            $name      = trim($this->cellN($row, $sIndex, ['nama'], 'C'));
            $jenjang   = trim($this->cellN($row, $sIndex, ['jenjang'], 'D')) ?: null;
            $kecamatan = trim($this->cellN($row, $sIndex, ['kecamatan'], 'E')) ?: null;
            $kabupaten = trim($this->cellN($row, $sIndex, ['kabupaten','kota','kota/kabupaten'], 'F')) ?: null;
            $provinsi  = trim($this->cellN($row, $sIndex, ['provinsi'], 'G')) ?: null;

            if (!$name) continue;

            $yayasanId   = trim((string)$yayasanIdRaw);
            $yayasanName = trim((string)$yayasanNameRaw);

            // Validasi FK: pakai id kalau valid; kalau tidak, coba by-name
            $resolvedYid = ($yayasanId !== '' && $yayasanIds->has($yayasanId)) ? $yayasanId : null;
            if (!$resolvedYid && $yayasanName !== '') {
                $key = strtolower($yayasanName);
                if (isset($yayasanByName[$key])) $resolvedYid = $yayasanByName[$key];
            }
            if (!$resolvedYid) {
                $skipped++; 
                $skippedRows[] = ['row'=>$i,'yayasanId'=>$yayasanId,'yayasanName'=>$yayasanName,'sekolah'=>$name];
                continue;
            }

            $npsn = preg_replace('/\s+/', '', (string)$npsnRaw) ?: null;
            $id   = $npsn ?: $this->fallbackId($resolvedYid, $name, $kecamatan, $kabupaten);

            Sekolah::updateOrCreate(
                ['id' => $id],
                [
                    'yayasan_id' => $resolvedYid,
                    'name'       => $name,
                    'jenjang'    => $jenjang,
                    'kecamatan'  => $kecamatan,
                    'kabupaten'  => $kabupaten,
                    'provinsi'   => $provinsi,
                    'npsn'       => $npsn,
                ]
            );
            $countS++;
        }

        $this->info("Sekolah diimport: {$countS}");
        if ($skipped) {
            $this->warn("Dilewati karena FK Yayasan tidak ketemu: {$skipped} baris");
            foreach (array_slice($skippedRows, 0, 5) as $s) {
                $this->line("- Row {$s['row']}: yayasanId='{$s['yayasanId']}', yayasanName='{$s['yayasanName']}', sekolah='{$s['sekolah']}'");
            }
        }

        return self::SUCCESS;
    }

    /** normalize satu baris header (lowercase + trim spasi ganda) */
    private function normalizeHeaderRow(array $row): array {
        $norm = [];
        foreach ($row as $k => $v) {
            $label = is_string($v) ? strtolower(trim(preg_replace('/\s+/', ' ', $v))) : $v;
            $norm[$k] = $label;
        }
        return $norm;
    }

    /** build map: headerName(normalized) -> columnLetter(A,B,...) */
    private function buildIndexNormalized(array $header): array {
        $idx = [];
        foreach ($header as $colLetter => $label) {
            if (!is_string($label) || $label === '') continue;
            $idx[$label] = $colLetter;
        }
        return $idx;
    }

    /** baca cell by list nama header (normalized), fallback huruf kolom */
    private function cellN(array $row, array $index, array $names, ?string $fallback): string {
        foreach ($names as $n) {
            $key = strtolower(trim(preg_replace('/\s+/', ' ', $n)));
            if (isset($index[$key])) {
                $col = $index[$key];
                return (string)($row[$col] ?? '');
            }
        }
        if ($fallback && isset($row[$fallback])) return (string)$row[$fallback];
        return '';
    }

    private function fallbackId(?string $yayasanId, ?string $name, ?string $kecamatan, ?string $kabupaten): string {
        $basis = implode('|', [
            strtolower(trim($yayasanId ?? '')),
            strtolower(trim($name ?? '')),
            strtolower(trim($kecamatan ?? '')),
            strtolower(trim($kabupaten ?? '')),
        ]);
        return 's_' . substr(sha1($basis), 0, 10);
    }
}
