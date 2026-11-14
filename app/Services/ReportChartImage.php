<?php

namespace App\Services;

use App\Models\Product;
use GdImage;
use Illuminate\Support\Collection;

class ReportChartImage
{
    /**
     * @param  Collection<int, Product>  $products
     * @return Collection<int, Product>
     */
    public static function topStockProducts(Collection $products): Collection
    {
        return $products
            ->filter(fn (Product $product): bool => $product->status === 'active' && ! $product->trashed())
            ->sortBy([['quantity', 'desc'], ['product_name', 'asc'], ['id', 'asc']])
            ->take(10)
            ->values();
    }

    /**
     * @param  array<int, string>  $labels
     * @param  array<int, float|int>  $values
     */
    public function verticalBars(array $labels, array $values, string $unit): string
    {
        $image = imagecreatetruecolor(1000, 300);
        $white = imagecolorallocate($image, 255, 255, 255);
        $ink = imagecolorallocate($image, 15, 23, 42);
        $grid = imagecolorallocate($image, 203, 213, 225);
        $rose = imagecolorallocate($image, 225, 29, 72);
        imagefill($image, 0, 0, $white);

        $font = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
        $max = max(1.0, ...$values);
        $left = 85;
        $right = 980;
        $top = 28;
        $bottom = 250;

        foreach ([0, 0.5, 1] as $fraction) {
            $y = (int) round($bottom - ($bottom - $top) * $fraction);
            imageline($image, $left, $y, $right, $y, $grid);
            imagettftext($image, 10, 0, 4, $y + 4, $ink, $font, $unit.' '.number_format($max * $fraction, 0));
        }

        $bucketWidth = ($right - $left) / max(1, count($labels));
        $labelInterval = max(1, (int) ceil(count($labels) / 9));

        foreach ($labels as $index => $label) {
            $value = max(0, (float) ($values[$index] ?? 0));
            $x = (int) round($left + $bucketWidth * $index);
            $barHeight = (int) round(($bottom - $top) * $value / $max);

            if ($barHeight > 0) {
                imagefilledrectangle($image, $x + 2, $bottom - $barHeight, (int) round($x + $bucketWidth - 3), $bottom - 1, $rose);
            }

            if ($index % $labelInterval === 0 || $index === count($labels) - 1) {
                imagettftext($image, 10, 0, $x + 2, 274, $ink, $font, $label);
            }
        }

        return $this->encode($image);
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    public function stockBars(Collection $products): string
    {
        $height = max(160, 70 + $products->count() * 46);
        $image = imagecreatetruecolor(1000, $height);
        $white = imagecolorallocate($image, 255, 255, 255);
        $ink = imagecolorallocate($image, 15, 23, 42);
        $rose = imagecolorallocate($image, 225, 29, 72);
        $grid = imagecolorallocate($image, 203, 213, 225);
        imagefill($image, 0, 0, $white);
        $font = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');

        if ($products->isEmpty()) {
            imagettftext($image, 18, 0, 275, (int) ($height / 2), $ink, $font, 'No active products to graph.');

            return $this->encode($image);
        }

        imagettftext($image, 11, 0, 420, 28, $ink, $font, 'Units in stock');
        $max = max(1, (int) $products->max('quantity'));
        imageline($image, 420, 40, 420, $height - 20, $grid);

        foreach ($products as $index => $product) {
            $y = 50 + $index * 46;
            $label = mb_strimwidth($product->product_code.' - '.$product->product_name, 0, 39, '…');
            imagettftext($image, 11, 0, 10, $y + 20, $ink, $font, $label);
            $width = (int) round(470 * $product->quantity / $max);

            if ($width > 0) {
                imagefilledrectangle($image, 420, $y, 420 + $width, $y + 28, $rose);
            }

            imagettftext($image, 11, 0, 900, $y + 20, $ink, $font, (string) $product->quantity);
        }

        return $this->encode($image);
    }

    private function encode(GdImage $image): string
    {
        ob_start();

        try {
            imagepng($image);

            return base64_encode((string) ob_get_contents());
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
    }
}
