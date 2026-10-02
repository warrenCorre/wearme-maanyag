<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Inventory Report</title>
    @include('reports.exports._style')
</head>
<body>
    <table class="topbar" cellpadding="0" cellspacing="0">
        <tr><td>&nbsp;</td></tr>
    </table>

    <table class="header" cellpadding="0" cellspacing="0">
        <tr>
            <td width="65%">
                <h1>Wear Me Maanyag</h1>
                <p class="tagline">Thrift Store &middot; Inventory Report</p>
            </td>
            <td width="35%" class="header-meta">
                Generated<br>
                {{ now()->format('M j, Y &#8226; H:i') }}
            </td>
        </tr>
    </table>

    <p class="context-line">
        <span class="label">Scope</span><br>
        All active products with reorder levels
    </p>

    <table class="cards" cellspacing="8" cellpadding="0">
        <tr>
            <td width="25%">
                <p class="card-label">Active Products</p>
                <p class="card-value">{{ number_format($stats['product_count']) }}</p>
            </td>
            <td width="25%">
                <p class="card-label">Units in Stock</p>
                <p class="card-value">{{ number_format($stats['total_units']) }}</p>
            </td>
            <td width="25%">
                <p class="card-label">Stock Value</p>
                <p class="card-value">PHP {{ number_format($stats['stock_value'], 2) }}</p>
            </td>
            <td width="25%" class="{{ $lowStockCount > 0 ? 'warn' : '' }}">
                <p class="card-label">Low Stock</p>
                <p class="card-value">{{ $lowStockCount }}</p>
                <p class="card-note">at or below reorder level</p>
            </td>
        </tr>
    </table>

    <p class="section-title">All Active Products</p>

    @if ($products->isEmpty())
        <div class="note">No active products yet.</div>
    @else
        <table class="report" cellpadding="0" cellspacing="0">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Product</th>
                    <th>Category</th>
                    <th class="num">Price (PHP)</th>
                    <th class="num">Qty</th>
                    <th class="num">Reorder</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($products as $product)
                    <tr class="{{ $loop->index % 2 === 1 ? 'alt' : '' }}">
                        <td>{{ $product->product_code }}</td>
                        <td>{{ $product->product_name }}</td>
                        <td>{{ $product->category?->name ?? 'Uncategorized' }}</td>
                        <td class="num">{{ number_format((float) $product->price, 2) }}</td>
                        <td class="num">{{ $product->quantity }}</td>
                        <td class="num">{{ $product->low_stock_threshold }}</td>
                        <td>{{ $product->quantity <= $product->low_stock_threshold ? 'Low Stock' : 'OK' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="footer">
        This report was generated automatically by Wear Me Maanyag &middot;
        {{ now()->format('M j, Y H:i') }}
    </p>
</body>
</html>
