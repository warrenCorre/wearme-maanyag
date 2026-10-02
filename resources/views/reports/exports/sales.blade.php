<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Sales Report</title>
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
                <p class="tagline">Thrift Store &middot; Sales Report</p>
            </td>
            <td width="35%" class="header-meta">
                Generated<br>
                {{ now()->format('M j, Y &#8226; H:i') }}
            </td>
        </tr>
    </table>

    <p class="context-line">
        <span class="label">Period</span><br>
        {{ ucfirst($period) }} &middot; {{ $rangeLabel }}
    </p>

    <table class="cards" cellspacing="8" cellpadding="0">
        <tr>
            <td width="33%">
                <p class="card-label">Total Sales</p>
                <p class="card-value">PHP {{ number_format($report['summary']['total_sales'], 2) }}</p>
                <p class="card-note">for the selected period</p>
            </td>
            <td width="33%">
                <p class="card-label">Transactions</p>
                <p class="card-value">{{ $report['summary']['transaction_count'] }}</p>
                <p class="card-note">completed sales</p>
            </td>
            <td width="34%">
                <p class="card-label">Average Sale</p>
                <p class="card-value">PHP {{ number_format($report['summary']['average_sale'], 2) }}</p>
                <p class="card-note">per transaction</p>
            </td>
        </tr>
    </table>

    <p class="section-title">Sales Breakdown</p>

    @if (empty($report['rows']))
        <div class="note">No sales recorded for this period.</div>
    @else
        <table class="report" cellpadding="0" cellspacing="0">
            <thead>
                <tr>
                    <th width="50%">Period</th>
                    <th width="20%" class="num">Transactions</th>
                    <th width="30%" class="num">Total (PHP)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['rows'] as $row)
                    <tr class="{{ $loop->index % 2 === 1 ? 'alt' : '' }}">
                        <td>{{ $row['label'] }}</td>
                        <td class="num">{{ $row['transactions'] }}</td>
                        <td class="num">{{ number_format($row['total'], 2) }}</td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <td>TOTAL</td>
                    <td class="num">{{ $report['summary']['transaction_count'] }}</td>
                    <td class="num">{{ number_format($report['summary']['total_sales'], 2) }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    <p class="footer">
        This report was generated automatically by Wear Me Maanyag &middot;
        {{ now()->format('M j, Y H:i') }} &middot;
        {{ ucfirst($period) }} period
    </p>
</body>
</html>
