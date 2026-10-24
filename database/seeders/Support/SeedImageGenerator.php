<?php

namespace Database\Seeders\Support;

/**
 * Generates themed placeholder images using PHP GD.
 *
 * If a real image exists in database/seeders/images/{theme}/, it is returned instead.
 * Otherwise a gradient image with a label is generated on the fly.
 */
class SeedImageGenerator
{
    private const THEME_COLORS = [
        'cats'     => [[255, 183, 77],  [255, 138, 101]],
        'nature'   => [[76, 175, 80],   [46, 125, 50]],
        'cars'     => [[66, 66, 66],    [33, 33, 33]],
        'fantasy'  => [[123, 31, 162],  [49, 27, 146]],
        'abstract' => [[0, 188, 212],   [0, 131, 143]],
        'digital'  => [[233, 30, 99],   [136, 14, 79]],
        'general'  => [[96, 125, 139],  [55, 71, 79]],
        'portrait' => [[121, 85, 72],   [78, 52, 46]],
    ];

    private string $localDir;
    private string $tmpDir;

    public function __construct()
    {
        $this->localDir = database_path('seeders/images');
        $this->tmpDir = storage_path('app/seed-temp');

        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0755, true);
        }
    }

    /**
     * Get a path to an image for the given theme and index.
     * Uses a local file if available, otherwise generates a placeholder.
     */
    public function get(string $theme, int $index, string $label = ''): string
    {
        $local = $this->findLocal($theme, $index);
        if ($local !== null) {
            return $local;
        }

        return $this->generate($theme, $index, $label);
    }

    /**
     * Get a path to a square avatar image.
     */
    public function avatar(int $index, string $name = ''): string
    {
        $dir = $this->localDir . '/avatars';
        if (is_dir($dir)) {
            $files = $this->listImages($dir);
            if (isset($files[$index % count($files)])) {
                return $files[$index % count($files)];
            }
        }

        return $this->generateAvatar($index, $name);
    }

    /**
     * Count available images for a theme (local files + unlimited generated).
     */
    public function countLocal(string $theme): int
    {
        $dir = $this->localDir . '/' . $theme;
        if (! is_dir($dir)) {
            return 0;
        }

        return count($this->listImages($dir));
    }

    public function cleanup(): void
    {
        if (is_dir($this->tmpDir)) {
            $files = glob($this->tmpDir . '/*');
            foreach ($files as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            @rmdir($this->tmpDir);
        }
    }

    private function findLocal(string $theme, int $index): ?string
    {
        $dir = $this->localDir . '/' . $theme;
        if (! is_dir($dir)) {
            return null;
        }

        $files = $this->listImages($dir);
        if (empty($files)) {
            return null;
        }

        return $files[$index % count($files)] ?? null;
    }

    private function listImages(string $dir): array
    {
        $exts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $files = [];
        foreach (scandir($dir) as $f) {
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (in_array($ext, $exts) && is_file($dir . '/' . $f)) {
                $files[] = $dir . '/' . $f;
            }
        }
        sort($files);

        return $files;
    }

    private function generate(string $theme, int $index, string $label): string
    {
        $width = 800;
        $height = 600;
        $img = imagecreatetruecolor($width, $height);

        $colors = self::THEME_COLORS[$theme] ?? self::THEME_COLORS['general'];
        $c1 = $colors[0];
        $c2 = $colors[1];

        // Diagonal gradient
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $ratio = ($x + $y) / ($width + $height);
                $r = (int) ($c1[0] + ($c2[0] - $c1[0]) * $ratio);
                $g = (int) ($c1[1] + ($c2[1] - $c1[1]) * $ratio);
                $b = (int) ($c1[2] + ($c2[2] - $c1[2]) * $ratio);
                $color = imagecolorallocate($img, $r, $g, $b);
                imagesetpixel($img, $x, $y, $color);
            }
        }

        // Decorative shapes based on theme+index
        $this->drawShapes($img, $theme, $index, $width, $height);

        // Label text
        $textColor = imagecolorallocate($img, 255, 255, 255);
        $shadowColor = imagecolorallocate($img, 0, 0, 0);
        $displayLabel = $label ?: ucfirst($theme) . ' #' . ($index + 1);

        $fontSize = 5;
        $textWidth = imagefontwidth($fontSize) * strlen($displayLabel);
        $textX = ($width - $textWidth) / 2;
        $textY = $height - 30;

        imagestring($img, $fontSize, $textX + 1, $textY + 1, $displayLabel, $shadowColor);
        imagestring($img, $fontSize, $textX, $textY, $displayLabel, $textColor);

        $path = $this->tmpDir . "/seed_{$theme}_{$index}.jpg";
        imagejpeg($img, $path, 85);
        imagedestroy($img);

        return $path;
    }

    private function drawShapes(\GdImage $img, string $theme, int $index, int $w, int $h): void
    {
        mt_srand($index * 1000 + crc32($theme));

        $alpha = imagecolorallocatealpha($img, 255, 255, 255, 100);

        for ($i = 0; $i < 5 + ($index % 4); $i++) {
            $cx = mt_rand(50, $w - 50);
            $cy = mt_rand(50, $h - 80);
            $size = mt_rand(40, 150);

            match ($theme) {
                'cats' => imageellipse($img, $cx, $cy, $size, $size, $alpha),
                'nature' => imagearc($img, $cx, $cy, $size, $size * 2, 180, 360, $alpha),
                'cars' => imagerectangle($img, $cx, $cy, $cx + $size, $cy + (int) ($size * 0.5), $alpha),
                'fantasy' => imagepolygon($img, [
                    $cx, $cy - $size / 2,
                    $cx - $size / 2, $cy + $size / 2,
                    $cx + $size / 2, $cy + $size / 2,
                ], $alpha),
                'abstract' => imageline($img, $cx, $cy, $cx + mt_rand(-100, 100), $cy + mt_rand(-100, 100), $alpha),
                default => imageellipse($img, $cx, $cy, $size, (int) ($size * 0.7), $alpha),
            };
        }

        mt_srand();
    }

    private function generateAvatar(int $index, string $name): string
    {
        $size = 256;
        $img = imagecreatetruecolor($size, $size);

        $hue = ($index * 47) % 360;
        $rgb = $this->hslToRgb($hue, 0.5, 0.6);
        $bg = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
        imagefill($img, 0, 0, $bg);

        // Draw initials
        $initials = mb_strtoupper(mb_substr($name ?: "U{$index}", 0, 1));
        $textColor = imagecolorallocate($img, 255, 255, 255);
        $fontSize = 5;
        $textWidth = imagefontwidth($fontSize) * strlen($initials);
        $textX = ($size - $textWidth) / 2;
        $textY = ($size - imagefontheight($fontSize)) / 2;
        imagestring($img, $fontSize, (int) $textX, (int) $textY, $initials, $textColor);

        $path = $this->tmpDir . "/avatar_{$index}.jpg";
        imagejpeg($img, $path, 90);
        imagedestroy($img);

        return $path;
    }

    private function hslToRgb(int $h, float $s, float $l): array
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;

        [$r, $g, $b] = match (true) {
            $h < 60  => [$c, $x, 0],
            $h < 120 => [$x, $c, 0],
            $h < 180 => [0, $c, $x],
            $h < 240 => [0, $x, $c],
            $h < 300 => [$x, 0, $c],
            default  => [$c, 0, $x],
        };

        return [
            (int) (($r + $m) * 255),
            (int) (($g + $m) * 255),
            (int) (($b + $m) * 255),
        ];
    }
}
