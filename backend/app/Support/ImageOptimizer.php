<?php

namespace App\Support;

/**
 * Shrinks large phone photos (often 4–12 MB) before storage and applies EXIF rotation.
 * Leaves the file untouched on any failure or for unsupported formats (HEIC, PDF).
 */
final class ImageOptimizer
{
    public static function optimise(string $path, string $mime, int $maxDim = 1600, int $quality = 85): void
    {
        if (! extension_loaded('gd') || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return;
        }

        try {
            $img = match ($mime) {
                'image/jpeg' => @imagecreatefromjpeg($path),
                'image/png' => @imagecreatefrompng($path),
                'image/webp' => @imagecreatefromwebp($path),
            };
            if (! $img) {
                return;
            }

            $rotated = false;
            if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
                $orientation = (int) (@exif_read_data($path)['Orientation'] ?? 1);
                $angle = match ($orientation) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
                if ($angle !== 0) {
                    $img = imagerotate($img, $angle, 0) ?: $img;
                    $rotated = true;
                }
            }

            $w = imagesx($img);
            $h = imagesy($img);
            $scale = min(1, $maxDim / max($w, $h));
            if ($scale >= 1 && ! $rotated) {
                imagedestroy($img);

                return;
            }

            $nw = (int) round($w * $scale);
            $nh = (int) round($h * $scale);
            $out = imagecreatetruecolor($nw, $nh);
            if ($mime !== 'image/jpeg') {
                imagealphablending($out, false);
                imagesavealpha($out, true);
            }
            imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

            match ($mime) {
                'image/jpeg' => imagejpeg($out, $path, $quality),
                'image/png' => imagepng($out, $path, 6),
                'image/webp' => imagewebp($out, $path, $quality),
            };
            imagedestroy($img);
            imagedestroy($out);
        } catch (\Throwable) {
            // keep original
        }
    }
}
