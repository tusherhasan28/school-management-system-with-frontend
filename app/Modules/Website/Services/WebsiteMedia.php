<?php

namespace App\Modules\Website\Services;

use App\Support\Tenancy\TenantStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Images for the public website, stored under schools/{id}/website/.
 *
 * Every upload is decoded and re-encoded with GD: this strips EXIF metadata
 * (including GPS location from phone photos), limits the size, and means a
 * file that is not a real image is rejected.
 */
class WebsiteMedia
{
    public const ROOT = 'website';

    public function __construct(private readonly TenantStorage $storage) {}

    /**
     * @return array{path: string, thumb_path: string, width: int, height: int}
     */
    public function storeImage(UploadedFile $file, string $folder, int $maxSide = 1600, int $thumbSide = 480): array
    {
        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if ($source === false) {
            throw ValidationException::withMessages(['image' => __('website.image_unreadable')]);
        }

        $source = $this->orient($source, $file);
        $name = Str::random(24);
        [$main, $w, $h] = $this->resize($source, $maxSide);
        [$thumb] = $this->resize($source, $thumbSide);

        $path = self::ROOT."/{$folder}/{$name}.jpg";
        $thumbPath = self::ROOT."/{$folder}/{$name}-thumb.jpg";
        $this->storage->disk()->put($this->storage->path($path), $this->jpeg($main));
        $this->storage->disk()->put($this->storage->path($thumbPath), $this->jpeg($thumb));

        imagedestroy($source);

        return ['path' => $path, 'thumb_path' => $thumbPath, 'width' => $w, 'height' => $h];
    }

    public function delete(?string ...$paths): void
    {
        foreach (array_filter($paths) as $path) {
            $this->storage->disk()->delete($this->storage->path($path));
        }
    }

    /** Public URL served by the media route on the school's own host. */
    public static function url(?string $path): ?string
    {
        return $path ? url('media/'.$path) : null;
    }

    /** @return array{0: \GdImage, 1: int, 2: int} */
    private function resize(\GdImage $src, int $maxSide): array
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, $maxSide / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // flatten transparency onto white
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        return [$dst, $nw, $nh];
    }

    private function jpeg(\GdImage $img): string
    {
        ob_start();
        imagejpeg($img, null, 82);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    /** Apply the camera's rotation before EXIF is discarded. */
    private function orient(\GdImage $img, UploadedFile $file): \GdImage
    {
        if (! function_exists('exif_read_data') || ! in_array($file->getMimeType(), ['image/jpeg', 'image/jpg'], true)) {
            return $img;
        }

        $orientation = (int) (@exif_read_data($file->getRealPath())['Orientation'] ?? 1);

        return match ($orientation) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => $img,
        };
    }
}
