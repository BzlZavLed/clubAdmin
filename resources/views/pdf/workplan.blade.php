@php
    use Carbon\Carbon;
    $weekdayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; color: #111; margin: 20px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        h2 { font-size: 16px; margin: 16px 0 6px; }
        .meta { font-size: 11px; color: #444; margin-bottom: 12px; }
        .calendar { width: 100%; border-collapse: collapse; margin-bottom: 18px; page-break-inside: avoid; }
        .calendar th { background: #f2f2f2; padding: 6px; font-size: 11px; text-align: center; }
        .calendar td { border: 1px solid #ddd; vertical-align: top; width: 14.28%; height: 110px; padding: 6px; }
        .day { font-weight: bold; font-size: 11px; margin-bottom: 4px; }
        .event { font-size: 10px; margin-bottom: 4px; padding: 4px; border-radius: 4px; border: 1px solid #e5e5e5; }
        .event.sabbath { background: #eef2ff; border-color: #c7d2fe; }
        .event.sunday { background: #e6fffb; border-color: #b2f5ea; }
        .event.special { background: #fff7e6; border-color: #fcd9a7; }
        .time { display: block; font-size: 9px; color: #555; margin-top: 2px; }
        .tag { display: inline-block; font-size: 9px; padding: 1px 4px; border-radius: 10px; border: 1px solid #aaa; margin-left: 4px; }
        .grid-title { display: flex; justify-content: space-between; align-items: baseline; }
        .month-block { page-break-inside: avoid; page-break-after: always; }
    </style>
</head>
<body>
    <h1>Club Workplan</h1>
    <div class="meta">
        Club: {{ $workplan->club->club_name ?? 'Club' }}<br>
        Range: {{ Carbon::parse($start)->toDateString() }} to {{ Carbon::parse($end)->toDateString() }}
    </div>

    @foreach($months as $month)
        @php
            $first = Carbon::create($month['year'], $month['month'], 1);
            $daysInMonth = $first->daysInMonth;
            $startDay = $first->dayOfWeek; // 0=Sun
        @endphp
        <div class="month-block" style="{{ $loop->last ? 'page-break-after: auto;' : '' }}">
            <div class="grid-title">
                <h2>{{ $month['label'] }}</h2>
            </div>
            <table class="calendar">
                <thead>
                    <tr>
                        @foreach($weekdayLabels as $label)
                            <th>{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @php $cell = 0; @endphp
                    @while ($cell < $startDay)
                        @if ($cell % 7 === 0)<tr>@endif
                        <td></td>
                        @php $cell++; @endphp
                        @if ($cell % 7 === 0)</tr>@endif
                    @endwhile

                    @for ($day = 1; $day <= $daysInMonth; $day++, $cell++)
                        @if ($cell % 7 === 0)<tr>@endif
                        @php
                            $dateStr = Carbon::create($month['year'], $month['month'], $day)->toDateString();
                            $dayEvents = $eventsByDate[$dateStr] ?? [];
                        @endphp
                        <td>
                            <div class="day">{{ $day }}</div>
                            @foreach($dayEvents as $ev)
                                <div class="event {{ $ev->meeting_type }}">
                                    <div>
                                        {{ $ev->title }}
                                        @if($ev->is_generated)
                                            <span class="tag">A</span>
                                        @endif
                                    </div>
                                    @if($ev->start_time || $ev->end_time)
                                        <span class="time">
                                            {{ $ev->start_time ? substr($ev->start_time, 0, 5) : '' }}
                                            @if($ev->end_time) - {{ substr($ev->end_time, 0, 5) }} @endif
                                        </span>
                                    @endif
                                    @if($ev->location)
                                        <span class="time">{{ $ev->location }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </td>
                        @if ($cell % 7 === 6)</tr>@endif
                    @endfor

                    @while ($cell % 7 !== 0)
                        <td></td>
                        @php $cell++; @endphp
                        @if ($cell % 7 === 0)</tr>@endif
                    @endwhile
                </tbody>
            </table>
        </div>
    @endforeach
</body>
</html>
