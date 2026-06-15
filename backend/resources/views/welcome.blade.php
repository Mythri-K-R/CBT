<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ExamSphere — India's Most Realistic CBT Exam Platform</title>
    <meta name="description" content="Give your NEET, JEE & KCET coaching students the exact NTA exam experience. Real CBT interface, instant analytics, batch management — all in one platform.">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600|plus-jakarta-sans:500,600,700,800&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased text-foreground bg-background selection:bg-primary/30 min-h-screen flex flex-col relative overflow-x-hidden" x-data>

    <!-- Background decorative gradients -->
    <div class="absolute inset-0 z-[-1] overflow-hidden pointer-events-none">
        <div class="absolute -top-40 -right-40 w-[800px] h-[800px] rounded-full bg-accent/20 blur-[120px]"></div>
        <div class="absolute top-[30%] -left-40 w-[600px] h-[600px] rounded-full bg-primary/10 blur-[120px]"></div>
        <div class="absolute inset-0 opacity-[0.025]" style="background-image: radial-gradient(circle at 2px 2px, currentColor 1px, transparent 0); background-size: 32px 32px"></div>
    </div>

    <!-- Navigation -->
    <header class="sticky top-0 z-50 w-full border-b border-border/40 bg-background/80 backdrop-blur-md">
        <div class="container mx-auto px-4 h-16 flex items-center justify-between max-w-7xl">
            <a href="/" class="flex items-center gap-2 font-display text-xl font-bold tracking-tight text-primary">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20"/><path d="m17 5-5-3-5 3"/><path d="m17 19-5 3-5-3"/><path d="M2 12h20"/><path d="m5 17-3-5 3-5"/><path d="m19 17 3-5-3-5"/></svg>
                ExamSphere
            </a>

            <nav class="hidden md:flex items-center gap-8 text-sm font-medium text-muted-foreground">
                <a href="#features" class="hover:text-foreground transition-colors">Features</a>
                <a href="#how" class="hover:text-foreground transition-colors">How It Works</a>
                <a href="#faq" class="hover:text-foreground transition-colors">FAQ</a>
                <a href="#contact" class="hover:text-foreground transition-colors">Contact</a>
            </nav>

            <div class="flex items-center gap-3">
                @auth
                    <a href="{{ auth()->user()->role === 'super_admin' ? route('admin.dashboard') : route('institution.dashboard') }}" class="inline-flex items-center justify-center rounded-full bg-primary px-5 py-2 text-sm font-semibold text-primary-foreground shadow-sm hover:bg-primary/90 transition-all active:scale-95">Dashboard</a>
                @else
                    <a href="{{ route('login') }}" class="text-sm font-medium text-muted-foreground hover:text-foreground transition-colors">Login</a>
                    <a href="#contact" class="inline-flex items-center justify-center rounded-full bg-primary px-5 py-2 text-sm font-semibold text-primary-foreground shadow-sm hover:bg-primary/90 transition-all active:scale-95">
                        Request Demo
                    </a>
                @endauth
            </div>
        </div>
    </header>

    <!-- Hero -->
    <main>
    <section class="flex flex-col items-center justify-center w-full max-w-7xl mx-auto px-4 pt-24 pb-20 lg:pt-36 lg:pb-28">
        <div class="inline-flex items-center gap-2 rounded-full border border-accent/40 bg-accent/10 px-4 py-1.5 text-sm font-medium text-accent mb-8">
            <span class="h-1.5 w-1.5 rounded-full bg-accent animate-pulse"></span>
            Trusted by 200+ coaching institutes across India
        </div>

        <div class="text-center max-w-4xl mx-auto space-y-6">
            <h1 class="font-display text-5xl sm:text-6xl lg:text-7xl font-extrabold tracking-tight leading-[1.1] text-foreground">
                The Most Realistic<br class="hidden sm:block" />
                <span style="background: linear-gradient(135deg, hsl(var(--accent)), hsl(var(--primary))); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">CBT Exam Platform</span><br/>
                for Coaching Institutes
            </h1>

            <p class="text-lg sm:text-xl text-muted-foreground max-w-2xl mx-auto leading-relaxed">
                Give your students the exact NEET, JEE &amp; KCET examination experience. Create tests, share links, analyze performance — all from one dashboard.
            </p>

            <div class="flex flex-col sm:flex-row items-center justify-center gap-4 pt-4">
                <a href="#contact" class="w-full sm:w-auto inline-flex items-center justify-center rounded-full bg-primary px-8 py-3.5 text-base font-semibold text-primary-foreground shadow-lg shadow-primary/20 hover:bg-primary/90 transition-all hover:-translate-y-0.5 active:scale-95">
                    Book a Free Demo
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="ml-2"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                </a>
                <a href="{{ route('login') }}" class="w-full sm:w-auto inline-flex items-center justify-center rounded-full border border-border bg-background px-8 py-3.5 text-base font-semibold shadow-sm hover:bg-accent hover:text-accent-foreground transition-all">
                    Login to Portal
                </a>
            </div>
        </div>

        <!-- Stats bar -->
        <div class="mt-20 grid grid-cols-2 sm:grid-cols-4 gap-8 w-full max-w-3xl border border-border rounded-2xl p-8 bg-card/60 backdrop-blur-sm">
            @foreach([['200+', 'Institutes onboarded'], ['50k+', 'Tests conducted'], ['2M+', 'Students served'], ['99.9%', 'Uptime SLA']] as [$stat, $label])
            <div class="text-center">
                <div class="font-display text-3xl font-extrabold text-primary">{{ $stat }}</div>
                <div class="text-sm text-muted-foreground mt-1">{{ $label }}</div>
            </div>
            @endforeach
        </div>
    </section>

    <!-- Features -->
    <section id="features" class="py-24 bg-card/30 border-y border-border/40">
        <div class="container mx-auto px-4 max-w-7xl">
            <div class="text-center max-w-3xl mx-auto mb-16">
                <h2 class="font-display text-3xl sm:text-4xl font-bold tracking-tight mb-4">Everything you need to run CBT exams at scale</h2>
                <p class="text-muted-foreground text-lg">Purpose-built for India's competitive exam coaching landscape.</p>
            </div>

            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach([
                    ['Real NTA Interface', 'Pixel-perfect NEET/JEE exam screen. Timer, question palette, marking scheme — exactly what students see on exam day.', 'text-primary border-primary/30', '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>'],
                    ['One-Click Test Links', 'Create test → generate slug → share on WhatsApp. Students click the link and start immediately — no app install needed.', 'text-accent border-accent/30', '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>'],
                    ['Smart Question Bank', '15,000+ PYQs pre-loaded. Add your own via Excel import. Organized by subject, chapter, difficulty, and topic.', 'text-warning border-warning/30', '<path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/><path d="M12 7v4"/><path d="M12 15h.01"/>'],
                    ['Deep Analytics', 'Student-wise, batch-wise, chapter-wise performance charts. Identify weak areas, track improvement over time.', 'text-success border-success/30', '<path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/>'],
                    ['Anti-Cheating Suite', 'Fullscreen enforcement, browser tab switch detection, single device session, and time-out auto-submit.', 'text-destructive border-destructive/30', '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>'],
                    ['Multi-Role Access', 'Institution Admin controls everything. Faculty can create/view only what they need. Full audit trail.', 'text-info border-info/30', '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'],
                ] as [$title, $desc, $color, $icon])
                <div class="p-6 rounded-2xl bg-background border {{ $color }} hover:shadow-md transition-all group">
                    <div class="h-10 w-10 rounded-xl {{ str_replace('text-', 'bg-', explode(' ', $color)[0]) }}/10 flex items-center justify-center mb-4">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="{{ explode(' ', $color)[0] }}">{!! $icon !!}</svg>
                    </div>
                    <h3 class="font-display text-lg font-bold mb-2">{{ $title }}</h3>
                    <p class="text-muted-foreground text-sm leading-relaxed">{{ $desc }}</p>
                </div>
                @endforeach
            </div>
        </div>
    </section>

    <!-- How It Works -->
    <section id="how" class="py-24">
        <div class="container mx-auto px-4 max-w-5xl">
            <div class="text-center mb-16">
                <h2 class="font-display text-3xl sm:text-4xl font-bold tracking-tight mb-4">Up and running in under a day</h2>
                <p class="text-muted-foreground text-lg">No IT team needed. No complex setup. Just sign up and start.</p>
            </div>

            <div class="relative">
                <!-- Connector line -->
                <div class="absolute left-[27px] top-10 bottom-10 w-0.5 bg-border hidden md:block"></div>

                <div class="space-y-10">
                    @foreach([
                        ['01', 'Sign Up & Onboard', 'We set up your institution portal, create your admin account, and load the question bank for your exam type (NEET/JEE/KCET).', 'bg-primary text-primary-foreground'],
                        ['02', 'Add Batches & Students', 'Create batches like "NEET 2026 Morning Batch", enroll students with roll numbers, and invite faculty with custom permissions.', 'bg-accent text-white'],
                        ['03', 'Schedule a Test', 'Pick a template, set start/end time, assign to batches. The system auto-generates test links for each student.', 'bg-success text-white'],
                        ['04', 'Students Take the Test', 'Students click their unique link, enter their roll number, and get the exact NTA exam interface with countdown timer.', 'bg-warning text-white'],
                        ['05', 'Instant Results & Analytics', 'The moment the test ends, scores, ranks, section-wise breakdowns, and percentiles are available on your dashboard.', 'bg-info text-white'],
                    ] as [$num, $title, $desc, $badge])
                    <div class="flex gap-6 items-start">
                        <div class="h-14 w-14 shrink-0 rounded-full {{ $badge }} flex items-center justify-center font-display text-lg font-bold shadow-md z-10">{{ $num }}</div>
                        <div class="pt-3">
                            <h3 class="font-display text-xl font-bold mb-2">{{ $title }}</h3>
                            <p class="text-muted-foreground leading-relaxed">{{ $desc }}</p>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <!-- Testimonials -->
    <section class="py-24 bg-primary/5 border-y border-border/40">
        <div class="container mx-auto px-4 max-w-7xl">
            <div class="text-center mb-16">
                <h2 class="font-display text-3xl sm:text-4xl font-bold tracking-tight mb-4">Loved by coaching directors</h2>
                <p class="text-muted-foreground">Real feedback from institute admins across Karnataka, Telangana &amp; Maharashtra.</p>
            </div>
            <div class="grid md:grid-cols-3 gap-8">
                @foreach([
                    ['"Our students now get the exact NEET interface during practice. Their actual exam anxiety has dropped dramatically. Results improved by 18% in the first cycle."', 'Rahul Sharma', 'Director, Pinnacle Academy, Bangalore'],
                    ['"Setup took less than a day. We conducted our first mock test the same week — 220 students, zero issues. Support team is excellent."', 'Priya Nair', 'Academic Head, ELITE Coaching, Kochi'],
                    ['"The analytics are gold. We finally know which chapter each student is weak in, not just which subject. It changed how we plan our revision sessions."', 'Aditya Kulkarni', 'Founder, Target Medical, Pune'],
                ] as [$quote, $name, $title])
                <div class="p-8 rounded-3xl bg-background border border-border shadow-sm flex flex-col gap-6">
                    <div class="flex gap-1">
                        @for($i = 0; $i < 5; $i++)
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" class="text-warning"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                        @endfor
                    </div>
                    <p class="text-muted-foreground italic leading-relaxed flex-1">{{ $quote }}</p>
                    <div>
                        <div class="font-semibold text-foreground">{{ $name }}</div>
                        <div class="text-sm text-muted-foreground">{{ $title }}</div>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </section>

    <!-- FAQ -->
    <section id="faq" class="py-24">
        <div class="container mx-auto px-4 max-w-3xl">
            <div class="text-center mb-16">
                <h2 class="font-display text-3xl sm:text-4xl font-bold tracking-tight mb-4">Frequently asked</h2>
            </div>
            <div class="space-y-3" x-data="{ open: null }">
                @foreach([
                    ['How long does setup take?', 'Your portal is ready within 24 hours of onboarding. We set up your admin account, pre-load the question bank for your exam type, and configure your branding. Your first test can be live the same day.'],
                    ['Do you have a pre-loaded question bank?', 'Yes. We include 15,000+ curated PYQs organized by subject, chapter, and difficulty — covering NEET 2015–2024, JEE Main &amp; Advanced, and KCET. You can also import your own questions via Excel.'],
                    ['What\'s the student limit per test?', 'Our Basic plan supports up to 500 concurrent students per test. Professional and Enterprise plans scale to unlimited concurrent test-takers with dedicated infrastructure.'],
                    ['Can students take tests on mobile?', 'The exam interface is optimized for desktop and laptop use (as in actual NTA exams). Mobile support is available for viewing results and checking rankings, but the CBT interface enforces desktop for integrity.'],
                    ['How do students access tests without creating accounts?', 'Students don\'t need an account. They get a unique test link (shared via WhatsApp or email), enter their roll number, and start immediately. No passwords, no app downloads.'],
                    ['Is there a free trial?', 'Yes! We offer a 14-day free trial with all Professional features and up to 50 students. No credit card required. Book a demo and we\'ll activate it for you.'],
                    ['Can faculty have separate logins with limited access?', 'Absolutely. Admins can create faculty accounts and control exactly what each faculty member can access: create questions, schedule tests, view results, manage students, or view analytics — all independently toggleable.'],
                    ['Do you support institute branding?', 'Professional and Enterprise plans include custom branding: your institute logo on the test interface, exam reports, and results pages. Enterprise adds custom domain support (e.g., exams.yourinstitute.com).'],
                ] as $i => [$q, $a])
                <div class="border border-border rounded-xl bg-card/50 overflow-hidden" x-data>
                    <button @click="$dispatch('toggle-faq', { id: {{ $i }} }); open = open === {{ $i }} ? null : {{ $i }}" class="w-full flex items-center justify-between px-6 py-5 text-left gap-4">
                        <h4 class="font-semibold text-base">{{ $q }}</h4>
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="shrink-0 transition-transform" :class="open === {{ $i }} ? 'rotate-180' : ''"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div x-show="open === {{ $i }}" x-collapse class="px-6 pb-5 text-muted-foreground leading-relaxed text-sm border-t border-border/50 pt-4">{!! $a !!}</div>
                </div>
                @endforeach
            </div>
        </div>
    </section>

    <!-- Contact / Demo Form -->
    <section id="contact" class="py-24 bg-card/30 border-t border-border/40">
        <div class="container mx-auto px-4 max-w-5xl">
            <div class="grid md:grid-cols-2 gap-16 items-center">
                <div>
                    <h2 class="font-display text-3xl sm:text-4xl font-bold tracking-tight mb-4">Book a free demo</h2>
                    <p class="text-muted-foreground text-lg mb-8">We'll show you the full platform — live CBT interface, analytics, question bank — in 30 minutes. Zero sales pressure.</p>
                    <ul class="space-y-4">
                        @foreach(['30-minute live walkthrough of the full platform', 'Personalized setup plan for your institute size', 'Free 14-day trial with all features unlocked', 'Dedicated support during onboarding'] as $item)
                        <li class="flex items-start gap-3 text-sm">
                            <div class="h-5 w-5 rounded-full bg-accent/15 flex items-center justify-center shrink-0 mt-0.5">
                                <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" class="text-accent"><path d="M20 6 9 17l-5-5"/></svg>
                            </div>
                            {{ $item }}
                        </li>
                        @endforeach
                    </ul>
                    <div class="mt-10 space-y-3 text-sm text-muted-foreground">
                        <div class="flex items-center gap-3">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                            <a href="mailto:hello@examsphere.in" class="hover:text-foreground transition-colors">hello@examsphere.in</a>
                        </div>
                        <div class="flex items-center gap-3">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13.5a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.64 2.5h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l.77-.77a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 21.5 17"/></svg>
                            <a href="tel:+919876500000" class="hover:text-foreground transition-colors">+91 98765 00000</a>
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl border border-border bg-background p-8 shadow-xl">
                    <h3 class="font-display text-xl font-bold mb-6">Request a Demo</h3>
                    <livewire:landing-lead-form />
                </div>
            </div>
        </div>
    </section>
    </main>

    <!-- Footer -->
    <footer id="footer" class="border-t border-border bg-card/50">
        <div class="container mx-auto px-4 py-12 max-w-7xl">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8 mb-8">
                <div class="md:col-span-2">
                    <div class="flex items-center gap-2 font-display text-xl font-bold text-primary mb-4">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20"/><path d="m17 5-5-3-5 3"/><path d="m17 19-5 3-5-3"/><path d="M2 12h20"/><path d="m5 17-3-5 3-5"/><path d="m19 17 3-5-3-5"/></svg>
                        ExamSphere
                    </div>
                    <p class="text-sm text-muted-foreground max-w-xs mb-4">India's most realistic CBT examination platform for NEET, JEE &amp; KCET coaching institutes.</p>
                    <p class="text-xs text-muted-foreground">Made with care in Bangalore, India 🇮🇳</p>
                </div>
                <div>
                    <h4 class="font-semibold mb-4 text-sm">Platform</h4>
                    <ul class="space-y-2 text-sm text-muted-foreground">
                        <li><a href="#features" class="hover:text-foreground transition-colors">Features</a></li>
                        <li><a href="#how" class="hover:text-foreground transition-colors">How it works</a></li>
                        <li><a href="#faq" class="hover:text-foreground transition-colors">FAQ</a></li>
                        <li><a href="{{ route('student.results') }}" class="hover:text-foreground transition-colors">Results Lookup</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-semibold mb-4 text-sm">Contact</h4>
                    <ul class="space-y-2 text-sm text-muted-foreground">
                        <li><a href="mailto:hello@examsphere.in" class="hover:text-foreground transition-colors">hello@examsphere.in</a></li>
                        <li><a href="tel:+919876500000" class="hover:text-foreground transition-colors">+91 98765 00000</a></li>
                        <li>Indiranagar, Bangalore 560038</li>
                    </ul>
                </div>
            </div>
            <div class="border-t border-border pt-8 flex flex-col md:flex-row items-center justify-between gap-4">
                <p class="text-sm text-muted-foreground">© {{ date('Y') }} ExamSphere. All rights reserved.</p>
                <div class="text-sm text-muted-foreground flex gap-6">
                    <a href="#" class="hover:text-foreground transition-colors">Privacy Policy</a>
                    <a href="#" class="hover:text-foreground transition-colors">Terms of Service</a>
                    <a href="#" class="hover:text-foreground transition-colors">Refund Policy</a>
                </div>
            </div>
        </div>
    </footer>

</body>
</html>
