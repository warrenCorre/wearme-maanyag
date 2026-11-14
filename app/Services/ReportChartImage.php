<?php

namespace App\Services;

use GdImage;

class ReportChartImage
{
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
