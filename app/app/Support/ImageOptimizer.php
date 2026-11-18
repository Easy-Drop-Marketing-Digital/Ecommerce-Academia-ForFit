<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Converte imagens enviadas (banners, coleções, produtos) para WebP redimensionado, com GD.
 * Funciona na hospedagem compartilhada da Hostinger (GD vem habilitado, sem dependências extras).
 */
class ImageOptimizer
{
    /** Larguras geradas para fotos de produto (arquivos "-r{largura}.webp" lado a lado) */
    public const WIDTHS = [200, 400, 600, 900, 1200];

    /**
     * Foto de produto em vários tamanhos WebP, para o navegador baixar só o necessário
     * (card no celular ~400px, página do produto 1200px). img_url() escolhe o tamanho pelo nome.
     *
     * @param  string  $binary  conteúdo da imagem (JPG/PNG/WebP/GIF)
     * @param  string  $base  caminho sem extensão no disco public (ex.: products/12/345)
     * @return array{path:string,width:int,height:int}|null  path = maior tamanho (…-r1200.webp)
     */
    public static function responsive(string $binary, string $base): ?array
    {
        if (! function_exists('imagewebp')) {
            return null;
        }
        $src = @imagecreatefromstring($binary);
        if (! $src) {
            return null;
        }
        imagepalettetotruecolor($src);
        $w = imagesx($src);
        $h = imagesy($src);
        $disk = Storage::disk('public');

        foreach (self::WIDTHS as $target) {
            $tw = min($target, $w); // nunca amplia
            $th = (int) round($h * $tw / $w);
            $dst = imagecreatetruecolor($tw, $th);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
            ob_start();
            imagewebp($dst, null, $target <= 400 ? 78 : 82);
            $disk->put("{$base}-r{$target}.webp", ob_get_clean());
            imagedestroy($dst);
        }
        imagedestroy($src);
        $max = max(self::WIDTHS);

        return ['path' => "{$base}-r{$max}.webp", 'width' => min($max, $w), 'height' => (int) round($h * min($max, $w) / $w)];
    }

    /** Caminho do tamanho certo para a largura pedida (só para fotos geradas por responsive()) */
    public static function sized(string $path, ?int $width): string
    {
        if (! $width || ! preg_match('/-r\d+\.webp$/', $path)) {
            return $path;
        }
        $pick = collect(self::WIDTHS)->first(fn ($w) => $w >= $width) ?? max(self::WIDTHS);

        return preg_replace('/-r\d+\.webp$/', "-r{$pick}.webp", $path);
    }

    /** Apaga todos os tamanhos de uma foto */
    public static function deleteSet(string $path): void
    {
        if (preg_match('/-r\d+\.webp$/', $path)) {
            Storage::disk('public')->delete(array_map(fn ($w) => preg_replace('/-r\d+\.webp$/', "-r{$w}.webp", $path), self::WIDTHS));
        }
    }

    /**
     * @param  string  $path  caminho no disco public (ex.: banners/1114.png)
     * @return string caminho do .webp gerado (ou o original, se não foi possível converter)
     */
    public static function toWebp(string $path, int $maxWidth = 1920, int $quality = 82): string
    {
        $disk = Storage::disk('public');
        if (! $disk->exists($path) || ! function_exists('imagewebp')) {
            return $path;
        }
        $dest = preg_replace('/\.(png|jpe?g|gif|webp)$/i', '', $path)."-w{$maxWidth}.webp";
        if ($disk->exists($dest)) {
            return $dest;
        }

        $src = @imagecreatefromstring($disk->get($path));
        if (! $src) {
            return $path;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        if ($w > $maxWidth) {
            $nh = (int) round($h * $maxWidth / $w);
            $dst = imagecreatetruecolor($maxWidth, $nh);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $maxWidth, $nh, $w, $h);
            imagedestroy($src);
            $src = $dst;
        } else {
            imagepalettetotruecolor($src);
            imagealphablending($src, false);
            imagesavealpha($src, true);
        }

        ob_start();
        imagewebp($src, null, $quality);
        $disk->put($dest, ob_get_clean());
        imagedestroy($src);

        return $dest;
    }
}
