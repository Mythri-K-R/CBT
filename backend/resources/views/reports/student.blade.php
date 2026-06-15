<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; }
  .page-header { background: #1e3a5f; color: #fff; padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; }
  .page-header h1 { font-size: 18px; font-weight: 700; }
  .page-header .meta { font-size: 10px; opacity: 0.8; text-align: right; }
  .student-info { background: #f4f7fb; margin: 12px 20px; padding: 12px 16px; border-radius: 6px; display: flex; gap: 30px; }
  .info-item label { font-size: 9px; color: #666; text-transform: uppercase; display: block; }
  .info-item span { font-size: 12px; font-weight: 600; }
  .section { margin: 16px 20px; }
  .section-title { font-size: 13px; font-weight: 700; color: #1e3a5f; border-bottom: 2px solid #1e3a5f; padding-bottom: 4px; margin-bottom: 10px; }
  .stats-grid { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }
  .stat-card { flex: 1; min-width: 90px; background: #f4f7fb; border-radius: 6px; padding: 10px; text-align: center; }
  .stat-card .value { font-size: 18px; font-weight: 700; color: #1e3a5f; }
  .stat-card .label { font-size: 9px; color: #666; margin-top: 2px; text-transform: uppercase; }
  table { width: 100%; border-collapse: collapse; font-size: 10px; }
  th { background: #1e3a5f; color: #fff; padding: 6px 8px; text-align: left; }
  td { padding: 5px 8px; border-bottom: 1px solid #eee; }
  tr:nth-child(even) td { background: #f9fafc; }
  .correct { color: #16a34a; font-weight: 600; }
  .wrong { color: #dc2626; font-weight: 600; }
  .bar-wrap { background: #e5e7eb; border-radius: 3px; height: 8px; width: 100%; min-width: 80px; }
  .bar-fill { background: #1e3a5f; border-radius: 3px; height: 8px; }
  .weak { color: #dc2626; }
  .strong { color: #16a34a; }
  .footer { text-align: center; font-size: 9px; color: #999; margin: 20px; padding-top: 10px; border-top: 1px solid #eee; }
</style>
</head>
<body>

<div class="page-header">
  <div>
    <h1>Student Performance Report</h1>
    <div style="font-size:11px;margin-top:2px;opacity:0.9;">{{ $institution->name }}</div>
  </div>
  <div class="meta">
    Individual Report<br>
    {{ now()->format('d M Y, h:i A') }}
  </div>
</div>

<div class="student-info">
  <div class="info-item">
    <label>Student Name</label>
    <span>{{ $student->name }}</span>
  </div>
  <div class="info-item">
    <label>Roll Number</label>
    <span>{{ $student->roll_number }}</span>
  </div>
  @if($student->batch)
  <div class="info-item">
    <label>Batch</label>
    <span>{{ $student->batch->name }}</span>
  </div>
  @endif
  <div class="info-item">
    <label>Exam Type</label>
    <span>{{ strtoupper($student->exam_type ?? '—') }}</span>
  </div>
</div>

<div class="section">
  <div class="section-title">Overall Performance</div>
  <div class="stats-grid">
    <div class="stat-card">
      <div class="value">{{ $overview['totalAttempts'] }}</div>
      <div class="label">Tests Taken</div>
    </div>
    <div class="stat-card">
      <div class="value">{{ $overview['avgScore'] }}</div>
      <div class="label">Avg Score</div>
    </div>
    <div class="stat-card">
      <div class="value">{{ $overview['avgAccuracy'] }}%</div>
      <div class="label">Avg Accuracy</div>
    </div>
    <div class="stat-card">
      <div class="value">{{ $overview['bestScore'] }}</div>
      <div class="label">Best Score</div>
    </div>
    @if($overview['bestRank'])
    <div class="stat-card">
      <div class="value">#{{ $overview['bestRank'] }}</div>
      <div class="label">Best Rank</div>
    </div>
    @endif
  </div>
</div>

@if(!empty($subjectStats) && $subjectStats->count())
<div class="section">
  <div class="section-title">Subject-wise Performance</div>
  <table>
    <thead>
      <tr>
        <th>Subject</th>
        <th>Attempted</th>
        <th>Correct</th>
        <th>Wrong</th>
        <th>Accuracy</th>
        <th style="width:120px;">Progress</th>
      </tr>
    </thead>
    <tbody>
      @foreach($subjectStats as $ss)
      <tr>
        <td>{{ $ss->subject->name ?? '—' }}</td>
        <td>{{ $ss->total_questions }}</td>
        <td class="correct">{{ $ss->total_correct }}</td>
        <td class="wrong">{{ $ss->total_wrong }}</td>
        <td @class(['weak' => $ss->accuracy < 40, 'strong' => $ss->accuracy >= 70])>{{ $ss->accuracy }}%</td>
        <td>
          <div class="bar-wrap">
            <div class="bar-fill" style="width:{{ min(100, $ss->accuracy) }}%;"></div>
          </div>
        </td>
      </tr>
      @endforeach
    </tbody>
  </table>
</div>
@endif

@if(!empty($recentTests) && $recentTests->count())
<div class="section">
  <div class="section-title">Recent Test History</div>
  <table>
    <thead>
      <tr>
        <th>Test Name</th>
        <th>Score</th>
        <th>Max Marks</th>
        <th>Rank</th>
        <th>Percentile</th>
        <th>Date</th>
      </tr>
    </thead>
    <tbody>
      @foreach($recentTests as $attempt)
      <tr>
        <td>{{ $attempt->test->title ?? '—' }}</td>
        <td><strong>{{ $attempt->total_score }}</strong></td>
        <td>{{ $attempt->max_possible_score }}</td>
        <td>{{ $attempt->rank_in_test ? '#'.$attempt->rank_in_test : '—' }}</td>
        <td>{{ $attempt->percentile ? number_format($attempt->percentile, 1) : '—' }}</td>
        <td>{{ $attempt->submitted_at ? \Carbon\Carbon::parse($attempt->submitted_at)->format('d M Y') : '—' }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>
</div>
@endif

@if(!empty($weakChapters) && $weakChapters->count())
<div class="section">
  <div class="section-title">Chapters Needing Improvement (Accuracy &lt; 40%)</div>
  <table>
    <thead>
      <tr>
        <th>Chapter</th>
        <th>Subject</th>
        <th>Attempted</th>
        <th>Accuracy</th>
      </tr>
    </thead>
    <tbody>
      @foreach($weakChapters as $cs)
      <tr>
        <td>{{ $cs->chapter->name ?? '—' }}</td>
        <td>{{ $cs->chapter->subject->name ?? '—' }}</td>
        <td>{{ $cs->total_questions }}</td>
        <td class="weak">{{ $cs->accuracy }}%</td>
      </tr>
      @endforeach
    </tbody>
  </table>
</div>
@endif

<div class="footer">
  Generated by ExamSphere &bull; {{ $institution->name }} &bull; {{ now()->format('d M Y') }}
</div>

</body>
</html>
