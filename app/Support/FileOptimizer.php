<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Optimize + compress file sebelum disimpan.
 *
 * - Image (JPEG/PNG/WEBP/GIF) → resize max 1600px + compress JPEG quality 82.
 * - PNG dengan alpha channel (mis. tanda tangan) → dipertahankan sebagai PNG.
 * - Non-image (PDF, dll) → disimpan apa adanya.
 */
class FileOptimizer
{
    /**
     * Simpan file dengan optimisasi otomatis.
     *
     * @param  UploadedFile  $file
     * @param  string  $directory  Folder tujuan di disk
     * @param  string  $disk  Filesystem disk (default: public)
     * @param  int  $maxWidth  Lebar maksimal image (px). Aspect ratio dijaga.
     * @param  int  $quality  JPEG quality 0-100
     * @return string  Relative path (siap disimpan di DB)
     */
    public static function storeOptimized(
        UploadedFile $file,
        string $directory,
        string $disk = 'public',
        int $maxWidth = 1600,
        int $quality = 82,
    ): string {
        $mime = $file->getMimeType();

        // Non-image → simpan apa adanya (PDF, dsb)
        if (! str_starts_with((string) $mime, 'image/')) {
            return $file->store($directory, $disk);
        }

        // GD extension wajib ada untuk optimize image; fallback simpan asli.
        if (! extension_loaded('gd')) {
            return $file->store($directory, $disk);
        }

        $srcPath = $file->getRealPath();
        if (! $srcPath || ! is_readable($srcPath)) {
            return $file->store($directory, $disk);
        }

        $preservePng = $mime === 'image/png' && self::pngHasAlpha($srcPath);

        $img = self::createImageResource($srcPath, $mime);
        if (! $img) {
            return $file->store($directory, $disk);
        }

        // Resize kalau lebih lebar dari batas
        $srcW = imagesx($img);
        $srcH = imagesy($img);
        if ($srcW > $maxWidth) {
            $ratio = $maxWidth / $srcW;
            $newW = $maxWidth;
            $newH = (int) round($srcH * $ratio);
            $resized = imagecreatetruecolor($newW, $newH);
            if ($preservePng) {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
                imagefilledrectangle($resized, 0, 0, $newW, $newH, $transparent);
            }
            imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);
            imagedestroy($img);
            $img = $resized;
        }

        // Encode ke temp file
        $ext = $preservePng ? 'png' : 'jpg';
        $tempPath = tempnam(sys_get_temp_dir(), 'opt_');

        if ($preservePng) {
            imagepng($img, $tempPath, 6);
        } else {
            imagejpeg($img, $tempPath, $quality);
        }
        imagedestroy($img);

        // Simpan ke disk
        $filename = trim($directory, '/').'/'.Str::random(24).'.'.$ext;
        Storage::disk($disk)->put($filename, file_get_contents($tempPath));
        @unlink($tempPath);

        return $filename;
    }

    private static function createImageResource(string $path, string $mime)
    {
        return match ($mime) {
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null,
            'image/gif' => @imagecreatefromgif($path),
            default => null,
        };
    }

    /**
     * Cek PNG punya alpha channel (colorType 4 grayscale+alpha atau 6 rgb+alpha).
     */
    private static function pngHasAlpha(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if (! $handle) {
            return false;
        }
        fseek($handle, 25); // colorType byte pada IHDR (offset 25 dari awal file)
        $colorType = ord((string) fread($handle, 1));
        fclose($handle);

        return in_array($colorType, [4, 6], true);
    }
}
