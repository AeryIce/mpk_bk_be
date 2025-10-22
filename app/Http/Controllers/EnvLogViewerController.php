<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class EnvLogViewerController extends Controller
{
    private function dir(): string
    {
        return storage_path('logs');
    }

    private function safe(string $file): ?string
    {
        $base = realpath($this->dir());
        $path = realpath($this->dir() . DIRECTORY_SEPARATOR . $file);
        if (!$path || !$base) return null;
        if (!str_starts_with($path, $base)) return null;
        if (!str_ends_with($path, '.log')) return null;
        return $path;
    }

    private function latest(): ?string
    {
        $files = collect(glob($this->dir() . '/*.log'))
            ->sortByDesc(fn ($p) => filemtime($p))
            ->values();
        $first = $files->first();
        return $first ? realpath($first) : null;
    }

    private function tail(string $path, int $bytes): string
    {
        $size  = @filesize($path) ?: 0;
        $bytes = max(4096, min($bytes, 4 * 1024 * 1024)); // 4MB max
        $start = max(0, $size - $bytes);

        $fh = @fopen($path, 'rb');
        if (!$fh) return '';
        if ($start > 0) fseek($fh, $start);
        $data = stream_get_contents($fh) ?: '';
        fclose($fh);

        return $data;
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function index(Request $r)
    {
        $file  = (string) $r->query('file', '');
        $bytes = (int) $r->query('bytes', 131072);

        $path = $file !== '' ? $this->safe($file) : $this->latest();
        if (!$path) {
            return response('<h1>Tidak ada log</h1>', 200)
                ->header('Content-Type', 'text/html; charset=utf-8');
        }

        if ($r->boolean('raw')) {
            return response($this->tail($path, $bytes), 200)
                ->header('Content-Type', 'text/plain; charset=utf-8');
        }

        $fname = basename($path);
        $qsDownload = http_build_query(['file' => $fname]);

        $html = <<<HTML
<!doctype html><html><head><meta charset="utf-8"><title>BK Console</title>
<style>
body{font-family:ui-monospace,monospace;background:#0b1220;color:#e2e8f0;margin:0;padding:20px;}
label{margin-right:8px}
input{padding:4px 6px;border-radius:8px;border:1px solid #334155;background:#0f172a;color:#e2e8f0}
button,a{padding:6px 10px;border-radius:8px;border:1px solid #334155;background:#1f2937;color:#e2e8f0;text-decoration:none}
pre{white-space:pre-wrap;background:#0f172a;border:1px solid #334155;border-radius:12px;padding:12px;max-height:78vh;overflow:auto}
</style></head><body>
<h1 style="margin:0 0 12px">BK Console</h1>
<form method="get" style="display:flex;gap:8px;align-items:center;margin-bottom:12px">
  <label>File: <input name="file" value="{$this->esc($file)}" placeholder="kosongkan untuk terbaru"></label>
  <label>Tail (bytes): <input name="bytes" type="number" value="{$bytes}" min="4096" step="4096"></label>
  <button type="submit">Apply</button>
  <a href="?raw=1&file={$this->esc($file)}&bytes={$bytes}">Raw</a>
  <a href="download?{$qsDownload}" style="margin-left:8px">Download</a>
</form>
<div style="margin-bottom:6px">Menampilkan: <b>{$this->esc($fname)}</b></div>
<pre>{$this->esc($this->tail($path, $bytes))}</pre>
</body></html>
HTML;

        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
    }

    public function download(Request $r)
    {
        $file = (string) $r->query('file', '');
        $path = $file !== '' ? $this->safe($file) : $this->latest();
        if (!$path) return response('no log', 404);

        return Response::download($path, basename($path), [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }
}
