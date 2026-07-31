<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use thiagoalessio\TesseractOCR\TesseractOCR;
use thiagoalessio\TesseractOCR\TesseractOcrException;
use Throwable;

/**
 * KTP Indonesia OCR Service.
 *
 * Mendukung 2 provider:
 * - 'gemini'    : Google Gemini Vision API (akurasi tinggi ~95%+, berbayar/free tier)
 * - 'tesseract' : Tesseract OCR local (akurasi medium, gratis, offline-able)
 *
 * Provider dipilih via config `services.ocr.provider`. Kalau Gemini gagal,
 * otomatis fallback ke Tesseract.
 *
 * Hasil return shape (semua field nullable):
 *  ok, nik, nama, tempat_lahir, tanggal_lahir, jenis_kelamin,
 *  alamat, rt_rw, kelurahan, kecamatan, kota_kabupaten, provinsi,
 *  agama, status_perkawinan, pekerjaan, raw, error, provider
 */
class KtpOcrService
{
    protected ?string $tesseractPath;

    protected string $provider;

    protected ?string $geminiKey;

    protected string $geminiModel;

    public function __construct()
    {
        $this->tesseractPath = config('services.tesseract.path');
        $this->provider = config('services.ocr.provider', 'tesseract');
        $this->geminiKey = config('services.gemini.api_key');
        $this->geminiModel = config('services.gemini.model', 'gemini-2.0-flash');
    }

    /**
     * Baca KTP sesuai provider yang di-config. NO silent fallback —
     * kalau provider yang dipilih gagal, error-nya di-return jelas ke caller.
     */
    public function read(string $absoluteImagePath): array
    {
        if (! file_exists($absoluteImagePath)) {
            return $this->fail('File foto tidak ditemukan.', 'none');
        }

        if ($this->provider === 'gemini') {
            if (! $this->geminiKey) {
                return $this->fail(
                    'GEMINI_API_KEY belum di-set di .env. Daftar di https://aistudio.google.com/apikey',
                    'gemini',
                );
            }
            return $this->readWithGemini($absoluteImagePath);
        }

        return $this->readWithTesseract($absoluteImagePath);
    }

    // ========================================================================
    // GEMINI VISION API
    // ========================================================================

    protected function readWithGemini(string $imagePath): array
    {
        try {
            $imageBytes = file_get_contents($imagePath);
            if ($imageBytes === false) {
                return $this->fail('Gagal baca file foto.', 'gemini');
            }

            $mimeType = mime_content_type($imagePath) ?: 'image/jpeg';
            $imageBase64 = base64_encode($imageBytes);

            $prompt = $this->buildGeminiPrompt();

            $url = sprintf(
                'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
                $this->geminiModel,
            );

            $response = Http::timeout(45)
                ->withHeaders(['x-goog-api-key' => $this->geminiKey])
                ->post($url, [
                    'contents' => [[
                        'parts' => [
                            ['inline_data' => [
                                'mime_type' => $mimeType,
                                'data' => $imageBase64,
                            ]],
                            ['text' => $prompt],
                        ],
                    ]],
                    'generationConfig' => [
                        'temperature' => 0.1,
                        'response_mime_type' => 'application/json',
                    ],
                ]);

            if (! $response->successful()) {
                return $this->fail(
                    'Gemini API error ('.$response->status().'): '.$response->body(),
                    'gemini',
                );
            }

            $data = $response->json();
            $jsonText = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

            if (! $jsonText) {
                return $this->fail('Gemini tidak return text.', 'gemini');
            }

            $parsed = json_decode($jsonText, true);
            if (! is_array($parsed)) {
                return $this->fail('Gemini return invalid JSON: '.$jsonText, 'gemini');
            }

            return $this->normalizeResult($parsed, $jsonText, 'gemini');
        } catch (Throwable $e) {
            return $this->fail('Gemini error: '.$e->getMessage(), 'gemini');
        }
    }

    protected function buildGeminiPrompt(): string
    {
        return <<<'PROMPT'
You are a KTP (Kartu Tanda Penduduk, Indonesian ID card) OCR system.
Extract structured data from this KTP photo. Return ONLY valid JSON, no markdown, no explanation.

JSON schema:
{
  "nik": "string of exactly 16 digits, or null if unclear",
  "nama": "Full name in Title Case (e.g., 'Budi Santoso'), or null",
  "tempat_lahir": "Birth city in Title Case (e.g., 'Jakarta'), or null",
  "tanggal_lahir": "Date format DD-MM-YYYY, or null",
  "jenis_kelamin": "Either 'Laki-laki' or 'Perempuan', or null",
  "alamat": "Street address only, NO RT/RW (e.g., 'Jl. Mawar No. 12'), or null",
  "rt_rw": "Format NNN/NNN (e.g., '003/005'), or null",
  "kelurahan": "Kelurahan/desa name in Title Case, or null",
  "kecamatan": "Kecamatan name in Title Case, or null",
  "kota_kabupaten": "City or kabupaten name in Title Case WITHOUT 'KOTA' or 'KABUPATEN' prefix (e.g., 'Depok'), or null",
  "provinsi": "Province name in Title Case (e.g., 'Jawa Barat'), or null",
  "agama": "One of: 'Islam', 'Kristen', 'Katholik', 'Hindu', 'Buddha', 'Konghucu', or null",
  "status_perkawinan": "One of: 'Belum Kawin', 'Kawin', 'Cerai Hidup', 'Cerai Mati', or null",
  "pekerjaan": "Job title in Title Case, or null"
}

Rules:
- If a field is illegible, blurred, or not visible, set it to null.
- Do NOT invent or guess data.
- For NIK, double-check it has exactly 16 digits.
- Names should be Title Case, not ALL CAPS.
- Return raw JSON only — no ```json fence, no commentary.
PROMPT;
    }

    // ========================================================================
    // TESSERACT LOCAL
    // ========================================================================

    protected function readWithTesseract(string $absoluteImagePath): array
    {
        try {
            $rawText1 = $this->runTesseract($absoluteImagePath, 6);
            $rawText2 = $this->runTesseract($absoluteImagePath, 4);
            $rawText = $rawText1."\n---PASS2---\n".$rawText2;

            $parsed = $this->parseTesseract($rawText);

            return $this->normalizeResult($parsed, $rawText, 'tesseract');
        } catch (TesseractOcrException $e) {
            return $this->fail('Tesseract error: '.$e->getMessage(), 'tesseract');
        } catch (Throwable $e) {
            return $this->fail('Gagal membaca KTP: '.$e->getMessage(), 'tesseract');
        }
    }

    protected function runTesseract(string $imagePath, int $psm): string
    {
        $ocr = new TesseractOCR($imagePath);
        if ($this->tesseractPath) {
            $ocr->executable($this->tesseractPath);
        }

        return $ocr->lang('ind', 'eng')
            ->psm($psm)
            ->configFile('preserve_interword_spaces=1')
            ->run();
    }

    protected function parseTesseract(string $raw): array
    {
        return [
            'nik' => $this->extractNik($raw),
            'nama' => $this->extractNama($raw),
            'tempat_lahir' => $this->extractTempatLahir($raw),
            'tanggal_lahir' => $this->extractTanggalLahir($raw),
            'jenis_kelamin' => $this->extractJenisKelamin($raw),
            'alamat' => $this->extractByLabel($raw, ['Alamat']),
            'rt_rw' => $this->extractRtRw($raw),
            'kelurahan' => $this->extractByLabel($raw, ['Kel/Desa', 'Kel\\Desa', 'Kelurahan', 'Desa', 'KeUDesa'], asTitleCase: true),
            'kecamatan' => $this->extractByLabel($raw, ['Kecamatan'], asTitleCase: true),
            'kota_kabupaten' => $this->extractHeader($raw, 'kota'),
            'provinsi' => $this->extractHeader($raw, 'provinsi'),
            'agama' => $this->extractByLabel($raw, ['Agama'], asTitleCase: true),
            'status_perkawinan' => $this->extractByLabel($raw, ['Status Perkawinan', 'Status Pemikahan'], asTitleCase: true),
            'pekerjaan' => $this->extractByLabel($raw, ['Pekerjaan'], asTitleCase: true),
        ];
    }

    protected function extractNik(string $raw): ?string
    {
        if (preg_match('/\b(\d{16})\b/', $raw, $m)) return $m[1];

        if (preg_match('/NIK\s*[:.]?\s*([\d\s]+)/i', $raw, $m)) {
            $digits = preg_replace('/[^0-9]/', '', $m[1]);
            if (strlen($digits) >= 16) return substr($digits, 0, 16);
        }

        if (preg_match_all('/\d{4,}/', $raw, $matches)) {
            $combined = implode('', $matches[0]);
            if (preg_match('/(\d{16})/', $combined, $m)) return $m[1];
        }

        return null;
    }

    protected function extractNama(string $raw): ?string
    {
        $labels = ['Nama', 'Nara', 'Mama', 'Hama', 'Narna', 'Narma', 'Nema', 'Nima'];
        foreach ($labels as $label) {
            $pattern = '/'.preg_quote($label, '/').'\s*[:.]?\s*([^\r\n]{2,100})/i';
            if (preg_match($pattern, $raw, $m)) {
                $val = $this->cleanValue($m[1]);
                if ($this->looksLikeName($val)) return $this->toTitleCase($val);
            }
        }

        $lines = preg_split('/\r?\n/', $raw);
        $nikLineIdx = -1;
        foreach ($lines as $i => $line) {
            if (preg_match('/\d{15,16}/', $line) || stripos($line, 'NIK') !== false) {
                $nikLineIdx = $i;
                break;
            }
        }
        if ($nikLineIdx >= 0) {
            for ($offset = 1; $offset <= 3; $offset++) {
                $line = $lines[$nikLineIdx + $offset] ?? null;
                if (! $line) continue;

                $line = preg_replace('/^[A-Za-z\/]+\s*[:.]?\s*/', '', trim($line));
                $clean = $this->cleanValue($line);

                if ($this->looksLikeName($clean)) return $this->toTitleCase($clean);
            }
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            if (preg_match('/\d/', $line)) continue;
            if (preg_match('/PROVINSI|KABUPATEN|KOTA|JAKARTA|REPUBLIK|INDONESIA/i', $line)) continue;
            if (preg_match('/^([A-Z][A-Z\s.,\']{4,80})$/', $line, $m)) {
                $clean = $this->cleanValue($m[1]);
                if ($this->looksLikeName($clean)) return $this->toTitleCase($clean);
            }
        }

        return null;
    }

    protected function looksLikeName(string $val): bool
    {
        $val = trim($val);
        if (strlen($val) < 3 || strlen($val) > 100) return false;

        $wordCount = str_word_count($val);
        if ($wordCount < 1 || $wordCount > 6) return false;

        $blacklist = ['NIK', 'ALAMAT', 'JENIS', 'KELAMIN', 'AGAMA', 'STATUS', 'PEKERJAAN',
                      'KEWARGANEGARAAN', 'BERLAKU', 'HINGGA', 'PROVINSI', 'KABUPATEN', 'KOTA',
                      'REPUBLIK', 'INDONESIA', 'GOL', 'DARAH', 'TEMPAT', 'TGL', 'LAHIR',
                      'RT', 'RW', 'KEL', 'DESA', 'KECAMATAN'];
        $upper = strtoupper($val);
        foreach ($blacklist as $bad) {
            if ($upper === $bad || str_starts_with($upper, $bad.' ')) return false;
        }

        $letters = preg_match_all('/[A-Za-z]/', $val);
        if (strlen($val) > 0 && ($letters / strlen($val)) < 0.6) return false;

        return true;
    }

    protected function extractByLabel(string $raw, array $labels, bool $asTitleCase = false): ?string
    {
        foreach ($labels as $label) {
            $pattern = '/'.preg_quote($label, '/').'\s*[:.]?\s*(.+?)(?:\r?\n|$)/i';
            if (preg_match($pattern, $raw, $m)) {
                $val = $this->cleanValue($m[1]);
                if (strlen($val) >= 2) {
                    return $asTitleCase ? $this->toTitleCase($val) : $val;
                }
            }
        }

        return null;
    }

    protected function extractTempatLahir(string $raw): ?string
    {
        if (preg_match('/Tempat\s*\/?\s*Tgl\s*Lahir\s*[:.]?\s*([^,\r\n]+),/i', $raw, $m)) {
            return $this->toTitleCase($this->cleanValue($m[1]));
        }
        if (preg_match('/TempaUTgl\s*Lahir\s*[:.]?\s*([^,\r\n]+),/i', $raw, $m)) {
            return $this->toTitleCase($this->cleanValue($m[1]));
        }

        return null;
    }

    protected function extractTanggalLahir(string $raw): ?string
    {
        if (preg_match('/Tempat\s*\/?\s*Tgl\s*Lahir\s*[:.]?\s*[^,\r\n]+,\s*(\d{1,2}[-\/.]\d{1,2}[-\/.]\d{2,4})/i', $raw, $m)) {
            return $this->normalizeTanggal($m[1]);
        }
        if (preg_match('/TempaUTgl\s*Lahir\s*[:.]?\s*[^,\r\n]+,\s*(\d{1,2}[-\/.]\d{1,2}[-\/.]\d{2,4})/i', $raw, $m)) {
            return $this->normalizeTanggal($m[1]);
        }
        if (preg_match('/(\d{1,2}[-\/]\d{1,2}[-\/]\d{4})/', $raw, $m)) {
            return $this->normalizeTanggal($m[1]);
        }

        return null;
    }

    protected function normalizeTanggal(string $tgl): string
    {
        return str_replace(['/', '.'], '-', $tgl);
    }

    protected function extractJenisKelamin(string $raw): ?string
    {
        if (preg_match('/Jenis\s*Kelamin\s*[:.]?\s*(LAKI[\s\-]?LAKI|PEREMPUAN|PRIA|WANITA)/i', $raw, $m)) {
            $v = strtoupper(trim($m[1]));
            return str_contains($v, 'LAKI') || str_contains($v, 'PRIA') ? 'Laki-laki' : 'Perempuan';
        }

        return null;
    }

    protected function extractRtRw(string $raw): ?string
    {
        if (preg_match('/RT\s*\/?\s*RW\s*[:.]?\s*(\d{1,3})\s*[\/\\\\\.\-]?\s*(\d{1,3})/i', $raw, $m)) {
            return sprintf('%03d/%03d', (int) $m[1], (int) $m[2]);
        }

        return null;
    }

    protected function extractHeader(string $raw, string $type): ?string
    {
        $lines = preg_split('/\r?\n/', $raw);
        $top = array_slice($lines, 0, 6);

        $keyword = $type === 'provinsi' ? 'PROVINSI' : '(KABUPATEN|KOTA)';

        foreach ($top as $line) {
            if (preg_match('/^\s*'.$keyword.'\s+(.+?)\s*$/i', $line, $m)) {
                $val = $type === 'provinsi' ? $m[1] : ($m[2] ?? $m[1]);
                $clean = $this->cleanValue($val);
                if (strlen($clean) >= 3) return $this->toTitleCase($clean);
            }
        }

        return null;
    }

    protected function cleanValue(string $val): string
    {
        $val = trim($val);
        $val = preg_split(
            '/(Tempat|Jenis|Alamat|RT|Kel|Kecamatan|Agama|Status|Pekerjaan|Kewarganegaraan|Berlaku|Gol|NIK|Nama|TempaU|KeU)/i',
            $val,
        )[0] ?? $val;

        return trim($val);
    }

    protected function toTitleCase(string $val): string
    {
        $clean = preg_replace('/[^A-Za-z0-9\s.,\'\-\/]/', '', $val);
        $clean = preg_replace('/\s+/', ' ', trim($clean));

        return mb_convert_case(mb_strtolower($clean), MB_CASE_TITLE, 'UTF-8');
    }

    // ========================================================================
    // SHARED HELPERS
    // ========================================================================

    /**
     * Normalize hasil supaya konsisten shape (semua key ada, default null).
     */
    protected function normalizeResult(array $parsed, ?string $raw, string $provider): array
    {
        $keys = [
            'nik', 'nama', 'tempat_lahir', 'tanggal_lahir', 'jenis_kelamin',
            'alamat', 'rt_rw', 'kelurahan', 'kecamatan', 'kota_kabupaten', 'provinsi',
            'agama', 'status_perkawinan', 'pekerjaan',
        ];

        $out = ['ok' => true, 'raw' => $raw, 'error' => null, 'provider' => $provider];
        foreach ($keys as $k) {
            $val = $parsed[$k] ?? null;
            // Trim string, null kalau kosong
            if (is_string($val)) {
                $val = trim($val);
                if ($val === '' || strtolower($val) === 'null') {
                    $val = null;
                }
            }
            $out[$k] = $val;
        }

        return $out;
    }

    protected function fail(string $msg, string $provider): array
    {
        return [
            'ok' => false,
            'nik' => null, 'nama' => null,
            'tempat_lahir' => null, 'tanggal_lahir' => null, 'jenis_kelamin' => null,
            'alamat' => null, 'rt_rw' => null,
            'kelurahan' => null, 'kecamatan' => null, 'kota_kabupaten' => null, 'provinsi' => null,
            'agama' => null, 'status_perkawinan' => null, 'pekerjaan' => null,
            'raw' => null,
            'error' => $msg,
            'provider' => $provider,
        ];
    }
}
