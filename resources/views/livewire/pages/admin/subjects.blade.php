<?php

use App\Models\Chapter;
use App\Models\Subject;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] class extends Component {
    public string $search = '';
    public bool $showSubjectModal = false;
    public bool $showChapterModal = false;
    public ?int $editingSubjectId = null;
    public ?int $editingChapterId = null;
    public ?int $selectedSubjectId = null;
    public array $expandedSubjects = [];

    public string $subjectName = '';
    public string $subjectExamType = 'neet';
    public string $subjectDescription = '';

    public string $chapterName = '';
    public string $chapterDescription = '';

    public function toggleExpand(int $subjectId): void
    {
        if (in_array($subjectId, $this->expandedSubjects)) {
            $this->expandedSubjects = array_values(array_filter($this->expandedSubjects, fn($id) => $id !== $subjectId));
        } else {
            $this->expandedSubjects[] = $subjectId;
        }
    }

    public function openSubject(?int $id = null): void
    {
        if ($id) {
            $s = Subject::find($id);
            $this->editingSubjectId = $id;
            $this->subjectName = $s->name;
            $this->subjectExamType = $s->exam_type;
            $this->subjectDescription = $s->description ?? '';
        } else {
            $this->reset(['subjectName','subjectExamType','subjectDescription','editingSubjectId']);
            $this->subjectExamType = 'neet';
        }
        $this->showSubjectModal = true;
    }

    public function saveSubject(): void
    {
        $this->validate(['subjectName' => 'required|string|max:100', 'subjectExamType' => 'required']);
        $data = ['name' => $this->subjectName, 'exam_type' => $this->subjectExamType, 'description' => $this->subjectDescription ?: null];
        $this->editingSubjectId
            ? Subject::find($this->editingSubjectId)?->update($data)
            : Subject::create($data);
        $this->showSubjectModal = false;
        session()->flash('success', 'Subject saved.');
    }

    public function openChapter(int $subjectId, ?int $chapterId = null): void
    {
        $this->selectedSubjectId = $subjectId;
        if ($chapterId) {
            $c = Chapter::find($chapterId);
            $this->editingChapterId = $chapterId;
            $this->chapterName = $c->name;
            $this->chapterDescription = $c->description ?? '';
        } else {
            $this->reset(['chapterName','chapterDescription','editingChapterId']);
        }
        $this->showChapterModal = true;
    }

    public function saveChapter(): void
    {
        $this->validate(['chapterName' => 'required|string|max:150']);
        $data = ['name' => $this->chapterName, 'description' => $this->chapterDescription ?: null, 'subject_id' => $this->selectedSubjectId];
        $this->editingChapterId
            ? Chapter::find($this->editingChapterId)?->update($data)
            : Chapter::create($data);
        $this->showChapterModal = false;
        session()->flash('success', 'Chapter saved.');
    }

    public function deleteSubject(Subject $subject): void { $subject->delete(); session()->flash('success','Subject deleted.'); }
    public function deleteChapter(Chapter $chapter): void { $chapter->delete(); session()->flash('success','Chapter deleted.'); }

    public function with(): array
    {
        $subjects = Subject::query()
            ->withCount('chapters')
            ->with(['chapters' => fn($q) => $q->orderBy('name')])
            ->when($this->search, fn($q) => $q->where('name','like',"%{$this->search}%"))
            ->orderBy('exam_type')
            ->orderBy('name')
            ->get();

        return ['subjects' => $subjects];
    }
}; ?>

<div>
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="font-display text-2xl font-bold">Subject Structure</h1>
            <p class="text-muted-foreground text-sm mt-1">Subjects, chapters, and topics for all exam types</p>
        </div>
        <button wire:click="openSubject" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90 transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
            Add Subject
        </button>
    </div>

    @if(session('success'))
    <div class="rounded-lg bg-success/10 border border-success/20 px-4 py-3 text-sm text-success">{{ session('success') }}</div>
    @endif

    <div class="relative max-w-xs">
        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search subjects..." class="h-9 w-full rounded-md border border-input bg-background pl-9 pr-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
    </div>

    @php $etColors = ['neet'=>'bg-green-50 text-green-700 border-green-200','jee'=>'bg-blue-50 text-blue-700 border-blue-200','kcet'=>'bg-purple-50 text-purple-700 border-purple-200']; @endphp

    <div class="space-y-3">
        @forelse($subjects as $subject)
        <div class="rounded-xl border border-border bg-card overflow-hidden">
            <div class="flex items-center gap-3 px-4 py-3 cursor-pointer hover:bg-muted/20 transition-colors" wire:click="toggleExpand({{ $subject->id }})">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-muted-foreground transition-transform {{ in_array($subject->id, $expandedSubjects) ? 'rotate-90' : '' }}"><path d="m9 18 6-6-6-6"/></svg>
                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-semibold {{ $etColors[$subject->exam_type] ?? 'bg-muted text-muted-foreground border-border' }}">{{ strtoupper($subject->exam_type) }}</span>
                <span class="font-semibold flex-1">{{ $subject->name }}</span>
                <span class="text-xs text-muted-foreground">{{ $subject->chapters_count }} chapters</span>
                <button wire:click.stop="openChapter({{ $subject->id }})" class="h-7 px-2 rounded text-xs font-medium text-primary hover:bg-primary/10 transition-colors">+ Chapter</button>
                <button wire:click.stop="openSubject({{ $subject->id }})" class="h-7 w-7 rounded flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                </button>
                <button wire:click.stop="deleteSubject({{ $subject->id }})" wire:confirm="Delete '{{ $subject->name }}' and all its chapters?" class="h-7 w-7 rounded flex items-center justify-center text-muted-foreground hover:text-destructive hover:bg-destructive/10 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="m19 6-.867 12.142A2 2 0 0 1 16.138 20H7.862a2 2 0 0 1-1.995-1.858L5 6"/></svg>
                </button>
            </div>

            @if(in_array($subject->id, $expandedSubjects))
            <div class="border-t border-border bg-muted/10 divide-y divide-border/50">
                @forelse($subject->chapters as $chapter)
                <div class="flex items-center gap-3 pl-10 pr-4 py-2.5 text-sm hover:bg-muted/20 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-muted-foreground shrink-0"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                    <span class="flex-1">{{ $chapter->name }}</span>
                    <button wire:click="openChapter({{ $subject->id }}, {{ $chapter->id }})" class="h-6 w-6 rounded flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted transition-colors opacity-0 group-hover:opacity-100">
                        <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                    </button>
                    <button wire:click="deleteChapter({{ $chapter->id }})" wire:confirm="Delete chapter '{{ $chapter->name }}'?" class="h-6 w-6 rounded flex items-center justify-center text-muted-foreground hover:text-destructive hover:bg-destructive/10 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="m19 6-.867 12.142A2 2 0 0 1 16.138 20H7.862a2 2 0 0 1-1.995-1.858L5 6"/></svg>
                    </button>
                </div>
                @empty
                <div class="pl-10 pr-4 py-3 text-xs text-muted-foreground italic">No chapters yet. Add one above.</div>
                @endforelse
            </div>
            @endif
        </div>
        @empty
        <div class="text-center py-16 rounded-2xl border border-dashed border-border">
            <p class="text-muted-foreground">No subjects yet. Add your first subject.</p>
        </div>
        @endforelse
    </div>
</div>

<!-- Subject Modal -->
@if($showSubjectModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
    <div class="w-full max-w-md rounded-2xl bg-card border border-border shadow-2xl p-6">
        <div class="flex items-center justify-between mb-6">
            <h2 class="font-display text-xl font-bold">{{ $editingSubjectId ? 'Edit' : 'Add' }} Subject</h2>
            <button wire:click="$set('showSubjectModal',false)" class="h-8 w-8 rounded flex items-center justify-center text-muted-foreground hover:bg-muted">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>
        <form wire:submit="saveSubject" class="space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1.5">Subject Name <span class="text-destructive">*</span></label>
                <input wire:model="subjectName" type="text" placeholder="e.g. Physics" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring @error('subjectName') border-destructive @enderror">
                @error('subjectName')<p class="text-xs text-destructive mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">Exam Type <span class="text-destructive">*</span></label>
                <select wire:model="subjectExamType" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
                    <option value="neet">NEET</option>
                    <option value="jee">JEE</option>
                    <option value="kcet">KCET</option>
                    <option value="common">Common (All)</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">Description</label>
                <textarea wire:model="subjectDescription" rows="2" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring resize-none"></textarea>
            </div>
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="flex-1 rounded-lg bg-primary py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90">Save Subject</button>
                <button type="button" wire:click="$set('showSubjectModal',false)" class="flex-1 rounded-lg border border-border py-2 text-sm font-medium hover:bg-muted">Cancel</button>
            </div>
        </form>
    </div>
</div>
@endif

<!-- Chapter Modal -->
@if($showChapterModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
    <div class="w-full max-w-md rounded-2xl bg-card border border-border shadow-2xl p-6">
        <div class="flex items-center justify-between mb-6">
            <h2 class="font-display text-xl font-bold">{{ $editingChapterId ? 'Edit' : 'Add' }} Chapter</h2>
            <button wire:click="$set('showChapterModal',false)" class="h-8 w-8 rounded flex items-center justify-center text-muted-foreground hover:bg-muted">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>
        <form wire:submit="saveChapter" class="space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1.5">Chapter Name <span class="text-destructive">*</span></label>
                <input wire:model="chapterName" type="text" placeholder="e.g. Laws of Motion" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring @error('chapterName') border-destructive @enderror">
                @error('chapterName')<p class="text-xs text-destructive mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">Description</label>
                <textarea wire:model="chapterDescription" rows="2" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring resize-none"></textarea>
            </div>
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="flex-1 rounded-lg bg-primary py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90">Save Chapter</button>
                <button type="button" wire:click="$set('showChapterModal',false)" class="flex-1 rounded-lg border border-border py-2 text-sm font-medium hover:bg-muted">Cancel</button>
            </div>
        </form>
    </div>
</div>
@endif
</div>
