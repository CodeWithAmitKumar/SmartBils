<?php
// Image upload + auto-resize helper. Needs GD.
declare(strict_types=1);

const IMG_MAX_BYTES = 512_000;   // 500 KB
const IMG_MAX_DIM   = 800;        // longest side after resize
const IMG_JPEG_Q    = 85;

/**
 * Save an uploaded image and return the stored relative path (e.g. "uploads/shops/abc.jpg"),
 * or throw. If size exceeds IMG_MAX_BYTES, it is resized + re-encoded as JPEG until it fits.
 *
 * @param array  $file   One slot of $_FILES
 * @param string $subdir e.g. "uploads/shops"
 * @param int    $ownerId Used to prefix the filename
 * @return string Stored relative path (web-servable)
 */
function image_store(array $file, string $subdir, int $ownerId): string {
    if (!extension_loaded('gd')) {
        throw new RuntimeException('GD extension is not enabled on this server.');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed (code ' . (int)$file['error'] . ').');
    }
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Invalid upload.');
    }

    // Hard cap: refuse anything > 10 MB before we even look at it.
    if ($file['size'] > 10 * 1024 * 1024) {
        throw new RuntimeException('Image too large (max 10 MB before resize).');
    }

    $info = @getimagesize($file['tmp_name']);
    if (!$info) throw new RuntimeException('File is not a valid image.');

    [$w, $h, $type] = $info;
    $src = null;
    switch ($type) {
        case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($file['tmp_name']); break;
        case IMAGETYPE_PNG:  $src = @imagecreatefrompng($file['tmp_name']);  break;
        case IMAGETYPE_WEBP:
            if (function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($file['tmp_name']);
            break;
    }
    if (!$src) throw new RuntimeException('Unsupported image format (use JPG, PNG or WEBP).');

    $absDir = dirname(__DIR__) . '/' . trim($subdir, '/');
    if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
        imagedestroy($src);
        throw new RuntimeException('Cannot create upload directory.');
    }

    $basename = $ownerId . '-' . bin2hex(random_bytes(6)) . '.jpg';
    $relPath  = trim($subdir, '/') . '/' . $basename;
    $absPath  = $absDir . '/' . $basename;

    // Decide dimensions: downscale if any side > IMG_MAX_DIM.
    $scale = min(1.0, IMG_MAX_DIM / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));

    // Resize/convert attempt
    $quality = IMG_JPEG_Q;
    $ok = image_write_scaled($src, $nw, $nh, $absPath, $quality);
    if (!$ok) { imagedestroy($src); throw new RuntimeException('Failed to write image.'); }

    // Loop: if still over the cap, shrink + lower quality.
    while (filesize($absPath) > IMG_MAX_BYTES) {
        if ($quality > 55) {
            $quality -= 10;
        } else {
            $nw = (int)round($nw * 0.85);
            $nh = (int)round($nh * 0.85);
            if ($nw < 200 || $nh < 200) break; // give up gracefully
        }
        image_write_scaled($src, $nw, $nh, $absPath, $quality);
    }

    imagedestroy($src);
    return $relPath;
}

/** Internal: scale $src into $nw × $nh and write as JPEG. */
function image_write_scaled($src, int $nw, int $nh, string $absPath, int $quality): bool {
    $dst = imagecreatetruecolor($nw, $nh);
    // White background for transparent PNGs when exporting to JPEG
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, imagesx($src), imagesy($src));
    $ok = imagejpeg($dst, $absPath, $quality);
    imagedestroy($dst);
    return (bool)$ok;
}

/** Delete a stored image file. Silently no-ops on missing / bad paths. */
function image_delete(?string $relPath): void {
    if (!$relPath) return;
    // Guard against path traversal.
    if (str_contains($relPath, '..')) return;
    $abs = dirname(__DIR__) . '/' . ltrim($relPath, '/');
    if (is_file($abs)) @unlink($abs);
}

/** URL for a stored image, with a tiny fingerprint for cache-busting. */
function image_url(?string $relPath): ?string {
    if (!$relPath) return null;
    $abs = dirname(__DIR__) . '/' . ltrim($relPath, '/');
    $v   = is_file($abs) ? '?v=' . substr((string)filemtime($abs), -6) : '';
    return url($relPath) . $v;
}
