<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Email preferences · {{ $businessName }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f4f4f5; color: #18181b; margin: 0; }
        main { max-width: 480px; margin: 64px auto; background: #fff; border-radius: 12px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        h1 { font-size: 20px; margin: 0 0 12px; }
        p { line-height: 1.5; margin: 0 0 16px; color: #3f3f46; }
        button { background: #18181b; color: #fff; border: 0; border-radius: 8px; padding: 10px 18px; font-size: 15px; cursor: pointer; }
    </style>
</head>
<body>
<main>
    @if ($unsubscribed)
        <h1>You have been unsubscribed</h1>
        <p>{{ $businessName }} will no longer send you broadcast emails.</p>
        <p>You will still receive emails about your quotes, invoices and receipts.</p>
    @else
        <h1>Unsubscribe from {{ $businessName }} emails?</h1>
        <p>You will stop receiving broadcast emails from {{ $businessName }}. Emails about your quotes, invoices and receipts are not affected.</p>
        <form method="POST" action="{{ $actionUrl }}">
            <button type="submit">Unsubscribe</button>
        </form>
    @endif
</main>
</body>
</html>
