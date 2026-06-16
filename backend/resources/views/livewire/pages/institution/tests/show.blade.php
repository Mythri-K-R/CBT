<?php

use App\Models\Test;
use App\Models\TestAttempt;
use App\Models\TestLink;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Illuminate\Support\Str;

new #[Layout('layouts.institution')] class extends Component {
    public Test $test;

    #[Url]
    public string $tab = 'overview';

    // Link Management
    public ?TestLink $activeLink = null;
    public bool $showGenerateModal = false;
    public ?string $linkCopied = null;
    public string $expiresOption = 'never';
    public string $expiresAt = '';
    public bool $generatingLink = false;

    public function mount(Test $test): void
    {
        abort_if($test->institution_id !== auth()->user()->institution_id, 403);
        $this->test = $test->load(['template', 'batches', 'sections.testQuestions']);

        $this->activeLink = TestLink::where('test_id', $test->id)
            ->where('is_active', true)
            ->latest()
            ->first();
    }

    public function generateLink(): void
    {
        $expires = null;
        if ($this->expiresOption !== 'never' && $this->expiresAt) {
            $expires = \Carbon\Carbon::parse($this->expiresAt);
        } elseif ($this->expiresOption === '24h') {
            $expires = now()->addHours(24);
        } elseif ($this->expiresOption === '7d') {
            $expires = now()->addDays(7);
        }

        $slug = Str::lower(Str::slug($this->test->title).'-'.Str::random(6));

        $link = TestLink::create([
            'test_id'        => $this->test->id,
            'institution_id' => $this->test->institution_id,
            'slug'           => $slug,
            'is_active'      => true,
            'expires_at'     => $expires,
        ]);

        $this->activeLink       = $link;
        $this->showGenerateModal = false;
        $this->tab               = 'links';

        session()->flash('linkGenerated', true);
    }

    public function deactivateLink(int $linkId): void
    {
        $link = TestLink::where('id', $linkId)
            ->where('institution_id', $this->test->institution_id)
            ->first();

        if ($link) {
            $link->update(['is_active' => false]);
            if ($this->activeLink?->id === $linkId) {
                $this->activeLink = null;
            }
        }
    }

    public function with(): array
    {
        $attempts = TestAttempt::with('student')
            ->where('test_id', $this->test->id)
            ->where('status', 'submitted')
            ->orderByDesc('total_score')
            ->get();

        $total       = $attempts->count();
        $avgScore    = $total ? round($attempts->avg('total_score'), 1) : 0;
        $topScore    = $attempts->max('total_score') ?? 0;
        $avgAccuracy = $total
            ? round($attempts->avg(fn($a) => $a->total_correct / max(1, ($a->total_correct + $a->total_wrong + $a->total_unattempted)) * 100), 1)
            : 0;

        $allLinks = TestLink::where('test_id', $this->test->id)
            ->orderByDesc('created_at')
            ->get();

        return compact('attempts', 'total', 'avgScore', 'topScore', 'avgAccuracy', 'allLinks');
    }
}; ?>

<div>
<div class="space-y-6">

    {{-- Page header --}}
    <div class="flex items-center gap-4">
        <a href="{{ route('institution.tests') }}" wire:navigate class="h-9 w-9 rounded-lg border border-border flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
        </a>
        <div class="flex-1 min-w-0">
            <h1 class="font-display text-2xl font-bold truncate">{{ $test->title }}</h1>
            <div class="flex items-center gap-3 mt-1 flex-wrap text-sm text-muted-foreground">
                <span>{{ strtoupper($test->exam_type) }}</span>
                <span>·</span>
                <span>{{ $test->duration_minutes }} min</span>
                <span>·</span>
                @php
                    $statusColors = ['draft'=>'bg-muted text-muted-foreground','scheduled'=>'bg-info/10 text-info','live'=>'bg-success/10 text-success','completed'=>'bg-muted text-muted-foreground'];
                    $sc = $statusColors[$test->status] ?? 'bg-muted text-muted-foreground';
                @endphp
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $sc }}">
                    @if($test->status === 'live')<span class="h-1.5 w-1.5 rounded-full bg-success mr-1.5 animate-pulse"></span>@endif
                    {{ ucfirst($test->status) }}
                </span>
            </div>
        </div>
        <button wire:click="$set('showGenerateModal', true)"
                class="h-9 rounded-lg bg-primary text-primary-foreground px-4 text-sm font-medium hover:bg-primary/90 transition-colors flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
            Generate Link
        </button>
    </div>

    {{-- Stat Cards --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach([
            ['Submissions', $total, 'text-primary'],
            ['Avg Score', $avgScore, 'text-info'],
            ['Top Score', $topScore, 'text-success'],
            ['Avg Accuracy', $avgAccuracy.'%', 'text-warning'],
        ] as [$label, $val, $color])
        <div class="rounded-xl border border-border bg-card p-4">
            <p class="text-sm text-muted-foreground">{{ $label }}</p>
            <p class="text-3xl font-bold {{ $color }} mt-1">{{ $val }}</p>
        </div>
        @endforeach
    </div>

    {{-- Tabs --}}
    <div class="border-b border-border flex gap-0">
        @foreach(['overview'=>'Overview','results'=>'Results','questions'=>'Questions','links'=>'Link Management'] as $key => $label)
        <button wire:click="$set('tab','{{ $key }}')"
                class="px-4 py-2.5 text-sm font-medium border-b-2 transition-colors {{ $tab === $key ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}">
            {{ $label }}
        </button>
        @endforeach
    </div>

    {{-- ── OVERVIEW ────────────────────────────────────────────────────────── --}}
    @if($tab === 'overview')
    <div class="grid lg:grid-cols-2 gap-6">
        <div class="rounded-xl border border-border bg-card p-5">
            <h3 class="font-semibold mb-4">Test Details</h3>
            <dl class="space-y-3 text-sm">
                @foreach([
                    ['Template',  $test->template->name ?? '—'],
                    ['Exam Type', strtoupper($test->exam_type)],
                    ['Duration',  $test->duration_minutes.' minutes'],
                    ['Total Marks', $test->total_marks ?? '—'],
                    ['Sections',  $test->sections->count()],
                    ['Questions', $test->sections->sum(fn($s) => $s->testQuestions->count())],
                    ['Start',     $test->scheduled_start?->format('d M Y, h:i A') ?? 'Manual'],
                    ['End',       $test->scheduled_end?->format('d M Y, h:i A') ?? 'Manual'],
                ] as [$lbl, $val])
                <div class="flex justify-between">
                    <dt class="text-muted-foreground">{{ $lbl }}</dt>
                    <dd class="font-medium">{{ $val }}</dd>
                </div>
                @endforeach
            </dl>
        </div>

        <div class="rounded-xl border border-border bg-card p-5">
            <h3 class="font-semibold mb-4">Assigned Batches</h3>
            @if($test->batches->isEmpty())
            <p class="text-sm text-muted-foreground">No batches assigned.</p>
            @else
            <div class="space-y-2">
                @foreach($test->batches as $batch)
                <div class="flex items-center justify-between text-sm">
                    <span class="font-medium">{{ $batch->name }}</span>
                    <span class="text-muted-foreground">{{ strtoupper($batch->exam_type) }}</span>
                </div>
                @endforeach
            </div>
            @endif
        </div>
    </div>
    @endif

    {{-- ── RESULTS ─────────────────────────────────────────────────────────── --}}
    @if($tab === 'results')
    <div class="rounded-xl border border-border bg-card overflow-hidden">
        <div class="px-4 py-3 border-b border-border bg-muted/30 flex items-center justify-between">
            <h3 class="font-semibold text-sm">Student Rankings</h3>
            <button class="text-xs font-medium text-primary hover:underline">Export CSV</button>
        </div>
        <table class="w-full text-sm">
            <thead class="border-b border-border">
                <tr>
                    <th class="text-left px-4 py-3 font-semibold text-muted-foreground">Rank</th>
                    <th class="text-left px-4 py-3 font-semibold text-muted-foreground">Student</th>
                    <th class="text-right px-4 py-3 font-semibold text-muted-foreground">Score</th>
                    <th class="text-right px-4 py-3 font-semibold text-muted-foreground hidden sm:table-cell">Correct</th>
                    <th class="text-right px-4 py-3 font-semibold text-muted-foreground hidden sm:table-cell">Wrong</th>
                    <th class="text-right px-4 py-3 font-semibold text-muted-foreground hidden md:table-cell">Accuracy</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse($attempts as $i => $a)
                @php $accuracy = ($a->total_correct + $a->total_wrong) > 0 ? round($a->total_correct / ($a->total_correct + $a->total_wrong) * 100) : 0; @endphp
                <tr class="hover:bg-muted/20 transition-colors">
                    <td class="px-4 py-3 font-bold {{ $i < 3 ? 'text-warning' : 'text-muted-foreground' }}">#{{ $i + 1 }}</td>
                    <td class="px-4 py-3">
                        <div class="font-medium">{{ $a->student->name }}</div>
                        <div class="text-xs text-muted-foreground">{{ $a->student->roll_number }}</div>
                    </td>
                    <td class="px-4 py-3 text-right font-bold">{{ $a->total_score }}</td>
                    <td class="px-4 py-3 text-right text-green-600 hidden sm:table-cell">{{ $a->total_correct }}</td>
                    <td class="px-4 py-3 text-right text-red-500 hidden sm:table-cell">{{ $a->total_wrong }}</td>
                    <td class="px-4 py-3 text-right hidden md:table-cell">{{ $accuracy }}%</td>
                </tr>
                @empty
                <tr><td colspan="6" class="px-4 py-12 text-center text-muted-foreground">No submissions yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @endif

    {{-- ── QUESTIONS ────────────────────────────────────────────────────────── --}}
    @if($tab === 'questions')
    <div class="rounded-xl border border-border bg-card overflow-hidden">
        @foreach($test->sections as $section)
        <div class="border-b border-border last:border-0">
            <div class="px-4 py-3 bg-muted/30 font-semibold text-sm">{{ $section->name }}</div>
            <table class="w-full text-sm">
                <thead class="border-b border-border">
                    <tr>
                        <th class="text-left px-4 py-2.5 font-semibold text-muted-foreground">Q#</th>
                        <th class="text-left px-4 py-2.5 font-semibold text-muted-foreground">Question</th>
                        <th class="text-right px-4 py-2.5 font-semibold text-muted-foreground">Marks</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach($section->testQuestions as $tq)
                    <tr class="hover:bg-muted/20">
                        <td class="px-4 py-3 font-mono text-muted-foreground">{{ $tq->question_number }}</td>
                        <td class="px-4 py-3 max-w-md">
                            <div class="line-clamp-2 text-sm">{!! strip_tags($tq->question->question_text ?? '—') !!}</div>
                        </td>
                        <td class="px-4 py-3 text-right text-xs">
                            <span class="text-green-600">+{{ $tq->positive_marks }}</span> /
                            <span class="text-red-500">-{{ $tq->negative_marks }}</span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endforeach
    </div>
    @endif

    {{-- ── LINK MANAGEMENT ─────────────────────────────────────────────────── --}}
    @if($tab === 'links')
    <div class="space-y-4">

        {{-- Flash --}}
        @if(session('linkGenerated'))
        <div class="rounded-lg bg-success/10 border border-success/30 text-success px-4 py-3 text-sm font-medium flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
            Test link generated successfully!
        </div>
        @endif

        @forelse($allLinks as $link)
        @php
            $url     = route('student.test.landing', ['slug' => $link->slug]);
            $waText  = urlencode("Join the test: {$test->title}\n\nClick here: {$url}");
            $isValid = $link->is_active && (!$link->expires_at || $link->expires_at->isFuture());
        @endphp
        <div class="rounded-xl border border-border bg-card overflow-hidden">
            <div class="px-5 py-4 border-b border-border bg-muted/20 flex items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold
                        {{ $isValid ? 'bg-success/10 text-success' : 'bg-muted text-muted-foreground' }}">
                        @if($isValid)<span class="h-1.5 w-1.5 rounded-full bg-success mr-1 animate-pulse inline-block"></span>@endif
                        {{ $isValid ? 'Active' : 'Inactive' }}
                    </span>
                    <span class="text-xs text-muted-foreground">
                        Created {{ $link->created_at->diffForHumans() }}
                    </span>
                    @if($link->expires_at)
                    <span class="text-xs text-muted-foreground">
                        · Expires {{ $link->expires_at->format('d M Y, h:i A') }}
                    </span>
                    @endif
                </div>
                @if($link->is_active)
                <button wire:click="deactivateLink({{ $link->id }})"
                        wire:confirm="Deactivate this link? Students will no longer be able to access the test via this link."
                        class="text-xs font-medium text-destructive hover:underline">
                    Deactivate
                </button>
                @endif
            </div>

            <div class="p-5 space-y-4">
                {{-- URL copy --}}
                <div>
                    <label class="text-xs font-semibold text-muted-foreground uppercase tracking-wide">Test Link URL</label>
                    <div class="mt-1.5 flex gap-2">
                        <input type="text" readonly value="{{ $url }}"
                               class="flex-1 rounded-lg border border-border bg-muted/30 px-3 py-2 text-sm font-mono text-foreground"
                               onclick="this.select()">
                        <button onclick="navigator.clipboard.writeText('{{ $url }}').then(() => { this.textContent='Copied!'; setTimeout(() => this.textContent='Copy', 2000); })"
                                class="shrink-0 rounded-lg border border-border bg-background hover:bg-muted px-3 py-2 text-sm font-medium transition-colors">
                            Copy
                        </button>
                    </div>
                </div>

                {{-- Share buttons --}}
                <div class="flex flex-wrap gap-2">
                    <a href="https://wa.me/?text={{ $waText }}" target="_blank" rel="noopener"
                       class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-white transition-opacity hover:opacity-90"
                       style="background:#25D366;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                        Share on WhatsApp
                    </a>

                    <button onclick="navigator.clipboard.writeText('{{ $url }}')"
                            class="inline-flex items-center gap-2 rounded-lg border border-border bg-background hover:bg-muted px-3 py-2 text-sm font-medium transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                        Copy Link
                    </button>
                </div>

                {{-- Analytics --}}
                <div class="grid grid-cols-3 gap-3 pt-2 border-t border-border">
                    @foreach([
                        ['Clicks',       $link->total_clicks       ?? 0, 'text-muted-foreground'],
                        ['Started',      $link->total_starts       ?? 0, 'text-info'],
                        ['Completed',    $link->total_completions  ?? 0, 'text-success'],
                    ] as [$lbl, $val, $clr])
                    <div class="text-center">
                        <div class="text-2xl font-bold {{ $clr }}">{{ $val }}</div>
                        <div class="text-xs text-muted-foreground mt-0.5">{{ $lbl }}</div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
        @empty
        <div class="rounded-xl border border-dashed border-border bg-card p-12 text-center">
            <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="mx-auto text-muted-foreground mb-3"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
            <h3 class="font-semibold text-muted-foreground">No test links yet</h3>
            <p class="text-sm text-muted-foreground mt-1 mb-4">Generate a link to share this test with your students.</p>
            <button wire:click="$set('showGenerateModal', true)"
                    class="rounded-lg bg-primary text-primary-foreground px-4 py-2 text-sm font-medium hover:bg-primary/90 transition-colors">
                Generate Test Link
            </button>
        </div>
        @endforelse
    </div>
    @endif

</div>{{-- /space-y-6 --}}

{{-- ── GENERATE LINK MODAL ─────────────────────────────────────────────────── --}}
@if($showGenerateModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
    <div class="bg-card border border-border rounded-xl shadow-xl w-full max-w-md">
        <div class="flex items-center justify-between px-5 py-4 border-b border-border">
            <h2 class="font-semibold text-base">Generate Test Link</h2>
            <button wire:click="$set('showGenerateModal', false)" class="h-8 w-8 rounded-lg hover:bg-muted flex items-center justify-center text-muted-foreground transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="p-5 space-y-4">
            <div class="p-3 bg-muted/40 rounded-lg text-sm text-muted-foreground">
                <p class="font-medium text-foreground mb-1">{{ $test->title }}</p>
                <p>{{ strtoupper($test->exam_type) }} · {{ $test->duration_minutes }} min</p>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1.5">Link Expiry</label>
                <select wire:model="expiresOption"
                        class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm">
                    <option value="never">Never expires</option>
                    <option value="24h">Expires in 24 hours</option>
                    <option value="7d">Expires in 7 days</option>
                    <option value="custom">Custom date &amp; time</option>
                </select>
            </div>

            @if($expiresOption === 'custom')
            <div>
                <label class="block text-sm font-medium mb-1.5">Expiry Date &amp; Time</label>
                <input wire:model="expiresAt" type="datetime-local"
                       class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm">
            </div>
            @endif

            <div class="flex gap-3 pt-2">
                <button wire:click="$set('showGenerateModal', false)"
                        class="flex-1 rounded-lg border border-border bg-background hover:bg-muted px-4 py-2 text-sm font-medium transition-colors">
                    Cancel
                </button>
                <button wire:click="generateLink"
                        class="flex-1 rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 px-4 py-2 text-sm font-medium transition-colors">
                    Generate Link →
                </button>
            </div>
        </div>
    </div>
</div>
@endif

</div>
