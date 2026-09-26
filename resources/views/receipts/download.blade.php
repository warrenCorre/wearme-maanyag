<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt {{ $transaction->transaction_no }}</title>
    <style>
        body { background: #f8fafc; margin: 0; padding: 2rem; }
        .receipt-print-area { margin: 0 auto; max-width: 420px; }
    </style>
</head>
<body>
    @include('receipts.partials.document', ['transaction' => $transaction])
</body>
</html>
