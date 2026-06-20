<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'ExamSphere') }} - Student Portal</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|outfit:500,600,700&display=swap" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased bg-background text-foreground h-screen flex flex-col overflow-hidden">
    <!-- Header -->
    <header class="h-16 border-b border-border bg-card/50 backdrop-blur shrink-0 flex items-center px-6 justify-between">
        <div class="flex items-center gap-4">
            <div class="font-outfit font-bold text-xl tracking-tight text-primary">
                Exam<span class="text-foreground">Sphere</span>
            </div>
            @if(isset($testTitle))
                <div class="h-6 w-px bg-border hidden sm:block"></div>
                <h1 class="text-sm font-medium text-muted-foreground hidden sm:block">
                    {{ $testTitle }}
                </h1>
            @endif
        </div>
        
        <div class="flex items-center gap-4">
            @if(isset($studentName))
                <div class="flex items-center gap-2">
                    <div class="h-8 w-8 rounded-full bg-primary/10 flex items-center justify-center text-primary font-medium text-sm">
                        {{ substr($studentName, 0, 1) }}
                    </div>
                    <span class="text-sm font-medium hidden sm:block">{{ $studentName }}</span>
                </div>
            @endif
        </div>
    </header>

    <!-- Main Content -->
    <main class="flex-1 overflow-hidden relative">
        {{ $slot }}
    </main>

    <!-- Livewire Scripts -->
    @livewireScripts
</body>
</html>
