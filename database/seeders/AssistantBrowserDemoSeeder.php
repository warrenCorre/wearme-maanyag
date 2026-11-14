<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\SalesTransaction;
use App\Models\User;
use App\Services\InventoryAdjustmentService;
use App\Services\SalesTransactionService;
use App\Services\UnpaidPaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class AssistantBrowserDemoSeeder extends Seeder
{
    private const IMAGE_PATH = 'products/demo-browser-fixture.png';

    private const CATEGORY_NAMES = ['DEMO Outerwear', 'DEMO Tops', 'DEMO Accessories'];

    private const PRODUCT_CODES = ['DEMO-JACKET', 'DEMO-BLOUSE', 'DEMO-TEE', 'DEMO-SCARF', 'DEMO-VEST'];

    private const TRANSACTION_NUMBERS = [
        'WM-DEMO-LASTMONTH', 'WM-DEMO-WALKIN', 'WM-DEMO-ONLINE',
        'WM-DEMO-UNPAID', 'WM-DEMO-SETTLED', 'WM-DEMO-PARTIAL',
    ];

    public function run(
        SalesTransactionService $sales,
        UnpaidPaymentService $unpaid,
        InventoryAdjustmentService $adjustments,
    ): void {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('Demo browser fixtures may only be seeded in local or testing environments.');
        }

        $createdImage = false;

        try {
            DB::transaction(function () use ($sales, $unpaid, $adjustments, &$createdImage): void {
                $owner = User::query()->where('role', 'store_owner')->where('status', 'active')
                    ->lockForUpdate()->sole();

                if (Category::query()->whereIn('category_name', self::CATEGORY_NAMES)->exists()
                    || Product::withTrashed()->whereIn('product_code', self::PRODUCT_CODES)->exists()
                    || SalesTransaction::query()->whereIn('transaction_no', self::TRANSACTION_NUMBERS)->exists()
                    || Storage::disk('public')->exists(self::IMAGE_PATH)) {
                    throw new RuntimeException('DEMO browser fixtures already exist or are incomplete; nothing was changed.');
                }

                $outerwear = Category::query()->create(['category_name' => 'DEMO Outerwear', 'status' => 'active']);
                $tops = Category::query()->create(['category_name' => 'DEMO Tops', 'status' => 'active']);
                $accessories = Category::query()->create(['category_name' => 'DEMO Accessories', 'status' => 'active']);

                $jacket = $this->product($owner, $outerwear, 'DEMO-JACKET', 'DEMO Denim Jacket', '500.00', 10, 3, 14);
                $blouse = $this->product($owner, $tops, 'DEMO-BLOUSE', 'DEMO Linen Blouse', '250.00', 8, 3, 7);
                $tee = $this->product($owner, $tops, 'DEMO-TEE', 'DEMO Cotton Tee', '150.00', 4, 2, 2);
                $scarf = $this->product($owner, $accessories, 'DEMO-SCARF', 'DEMO Vintage Scarf', '100.00', 2, 1, 1);
                $vest = $this->product($owner, $outerwear, 'DEMO-VEST', 'DEMO Archived Vest', '400.00', 3, 1, 0);
                $vest->update(['status' => 'archived']);
                $vest->delete();

                $lastMonth = $sales->createPaidSale($this->paidSale('walk_in', [[$jacket, 1]]), $owner);
                $this->labelSale($lastMonth, 'WM-DEMO-LASTMONTH', CarbonImmutable::today()->subMonthNoOverflow()->startOfMonth()->addDays(14)->setTime(12, 0));

                $walkIn = $sales->createPaidSale($this->paidSale('walk_in', [[$jacket, 2], [$blouse, 1]]), $owner);
                $this->labelSale($walkIn, 'WM-DEMO-WALKIN');

                $online = $sales->createPaidSale($this->paidSale('online', [[$blouse, 1], [$tee, 1]]), $owner);
                $this->labelSale($online, 'WM-DEMO-ONLINE');

                $pending = $unpaid->recordPendingItems($this->pendingSale('walk_in', [[$tee, 2]], 'DEMO Unpaid One', '0.00'), $owner);
                $this->labelSale($pending, 'WM-DEMO-UNPAID', CarbonImmutable::yesterday()->setTime(13, 0));

                $settled = $unpaid->recordPendingItems($this->pendingSale('walk_in', [[$scarf, 1]], 'DEMO Settled One', '0.00'), $owner);
                $this->labelSale($settled, 'WM-DEMO-SETTLED');
                $unpaid->updateCumulativePayment($settled->unpaidPayment, 10000, $owner);

                $partial = $unpaid->recordPendingItems($this->pendingSale('online', [[$scarf, 1], [$blouse, 1]], 'DEMO Partial One', '100.00'), $owner);
                $this->labelSale($partial, 'WM-DEMO-PARTIAL');

                $adjustments->adjust($jacket, $owner, [
                    'direction' => 'increase', 'quantity' => 2, 'reason_kind' => 'other',
                    'reason' => 'DEMO stock-count restock.',
                ]);
                $adjustments->adjust($tee, $owner, [
                    'direction' => 'decrease', 'quantity' => 1, 'reason_kind' => 'damaged',
                    'reason' => 'DEMO torn fabric.',
                ]);

                if (! $this->writePlaceholderImage()) {
                    throw new RuntimeException('Could not write the DEMO product placeholder; fixture records were rolled back.');
                }

                $createdImage = true;
            });
        } catch (Throwable $exception) {
            if ($createdImage) {
                Storage::disk('public')->delete(self::IMAGE_PATH);
            }

            throw $exception;
        }
    }

    private function product(
        User $owner,
        Category $category,
        string $code,
        string $name,
        string $price,
        int $quantity,
        int $threshold,
        int $daysAgo,
    ): Product {
        return Product::query()->create([
            'product_code' => $code,
            'product_name' => $name,
            'category_id' => $category->id,
            'description' => 'Fictional product for browser testing only.',
            'collection_date' => CarbonImmutable::today()->subDays($daysAgo)->toDateString(),
            'price' => $price,
            'quantity' => $quantity,
            'low_stock_threshold' => $threshold,
            'image' => self::IMAGE_PATH,
            'status' => 'active',
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);
    }

    /**
     * @param  array<int, array{0: Product, 1: int}>  $items
     * @return array{sale_type: string, online_platform: string, payment_status: string, items: array<int, array{product_id: int, quantity: int}>}
     */
    private function paidSale(string $saleType, array $items): array
    {
        return [
            'sale_type' => $saleType,
            'online_platform' => $saleType === 'online' ? 'Facebook' : '',
            'payment_status' => 'paid',
            'items' => array_map(fn (array $item): array => [
                'product_id' => $item[0]->id, 'quantity' => $item[1],
            ], $items),
        ];
    }

    /**
     * @param  array<int, array{0: Product, 1: int}>  $items
     * @return array{sale_type: string, online_platform: string, products: array<int, array{product_id: int, quantity: int}>, customer_name: string, customer_contact: string, delivery_address: string, amount_paid: string}
     */
    private function pendingSale(string $saleType, array $items, string $customer, string $amountPaid): array
    {
        return [
            'sale_type' => $saleType,
            'online_platform' => $saleType === 'online' ? 'Messenger' : '',
            'products' => array_map(fn (array $item): array => [
                'product_id' => $item[0]->id, 'quantity' => $item[1],
            ], $items),
            'customer_name' => $customer,
            'customer_contact' => '0000000000',
            'delivery_address' => 'DEMO fictional address',
            'amount_paid' => $amountPaid,
        ];
    }

    private function labelSale(SalesTransaction $sale, string $number, ?CarbonImmutable $date = null): void
    {
        $sale->update([
            'transaction_no' => $number,
            ...($date !== null ? ['transaction_date' => $date] : []),
        ]);
    }

    private function writePlaceholderImage(): bool
    {
        $image = imagecreatetruecolor(96, 96);
        $pink = imagecolorallocate($image, 251, 207, 232);
        $ink = imagecolorallocate($image, 131, 24, 67);
        imagefill($image, 0, 0, $pink);
        imagestring($image, 5, 22, 40, 'DEMO', $ink);

        ob_start();

        try {
            if (! imagepng($image)) {
                return false;
            }

            $contents = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        return is_string($contents) && Storage::disk('public')->put(self::IMAGE_PATH, $contents);
    }
}
