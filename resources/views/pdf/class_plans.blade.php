<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Class Plans</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; margin: 24px; }
        h1 { font-size: 20px; margin-bottom: 4px; }
        h2 { font-size: 16px; margin: 0 0 12px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { padding: 6px; border: 1px solid #ccc; text-align: left; }
        .meta { margin-bottom: 8px; }
        .meta div { margin-bottom: 2px; }
    </style>
</head>
<body>
    <h1>Class Plans</h1>
    <div class="meta">
        <div><strong>Club:</strong> {{ $workplan->club->club_name ?? 'N/A' }}</div>
        <div><strong>Class:</strong> {{ $class_name }}</div>
        <div><strong>Staff:</strong> {{ $staff_names ?: '—' }}</div>
        <div><strong>Range:</strong> {{ $workplan->start_date }} to {{ $workplan->end_date }}</div>
        @if(request('needs_approval'))
            <div><strong>Filter:</strong> Needs approval only</div>
        @endif
        @if(request('status') && request('status') !== 'all')
            <div><strong>Status filter:</strong> {{ ucfirst(request('status')) }}</div>
        @endif
    </div>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Title</th>
                <th>Type</th>
                <th>Status</th>
                <th>Staff</th>
                <th>Note</th>
                <th>Authorized</th>
            </tr>
        </thead>
        <tbody>
            @forelse($plans as $plan)
                <tr>
                    <td>{{ $plan['date'] ?? $plan['requested_date'] ?? '' }}</td>
                    <td>{{ $plan['title'] }}</td>
                    <td>{{ ucfirst($plan['type']) }}</td>
                    <td>{{ $plan['status'] }}</td>
                    <td>{{ $plan['staff_name'] ?? '—' }}</td>
                    <td>{{ $plan['note'] ?? '' }}</td>
                    <td>{{ $plan['authorized_at'] ?? '' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" style="text-align:center;">No class plans.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
