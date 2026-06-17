<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 36px 42px; }
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #111827; font-size: 12px; line-height: 1.45; }
        .evidence-title { font-size: 18px; font-weight: 700; margin-bottom: 4px; }
        .evidence-meta { margin-bottom: 16px; color: #4b5563; }
        .evidence-image-wrap { width: 100%; height: 850px; text-align: center; }
        .evidence-image { max-width: 100%; max-height: 830px; }
        .evidence-note { border: 1px solid #d1d5db; padding: 18px; background: #f9fafb; font-size: 14px; line-height: 1.6; }
    </style>
</head>
<body>
    <div class="evidence-title">{{ $page['title'] }}</div>
    <div class="evidence-meta">
        <strong>{{ $page['file_name'] }}</strong><br>
        {{ $page['mime_type'] }}
        @if(!empty($page['size']))
            · {{ number_format($page['size'] / 1024, 1) }} KB
        @endif
    </div>

    @if($page['type'] === 'image' && !empty($page['data_uri']))
        <div class="evidence-image-wrap">
            <img class="evidence-image" src="{{ $page['data_uri'] }}" alt="{{ $page['file_name'] }}">
        </div>
    @else
        <div class="evidence-note">
            {{ $page['note'] }}
        </div>
    @endif
</body>
</html>
