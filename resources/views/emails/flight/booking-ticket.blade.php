<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>تذكرة سفر</title>
</head>
<body style="font-family: sans-serif; line-height: 1.6; color: #111; max-width: 600px; margin: 0 auto; padding: 24px;">
    <h1 style="font-size: 1.25rem;">تذكرة سفر</h1>
    <p><strong>رقم الحجز:</strong> {{ $booking->booking_number ?? $booking->id }}</p>
    @if($booking->customer)
        <p><strong>العميل:</strong> {{ $booking->customer->full_name }}</p>
    @endif
    @if($booking->passengers && $booking->passengers->isNotEmpty())
        <p><strong>المسافرون:</strong></p>
        <ul>
            @foreach($booking->passengers as $p)
                <li>{{ trim(($p->first_name ?? '').' '.($p->last_name ?? '')) ?: '—' }}</li>
            @endforeach
        </ul>
    @endif
    <p style="margin-top: 32px; font-size: 0.875rem; color: #555;">هذه رسالة آلية من نظام الحجوزات. للاستفسار تواصل مع المكتب.</p>
</body>
</html>
