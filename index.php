<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard/index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management and Demand Forecasting System</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                    },
                    colors: {
                        navy: '#1E293B',
                        primary: '#2563EB',
                        accent: '#F59E0B',
                    },
                },
            },
        };
    </script>
    <style>
        /* Slow cinematic zoom on the cover image */
        @keyframes kenburns {
            0%   { transform: scale(1) translateY(0); }
            50%  { transform: scale(1.08) translateY(-8px); }
            100% { transform: scale(1) translateY(0); }
        }

        .kenburns {
            animation: kenburns 28s ease-in-out infinite;
            will-change: transform;
        }

        @keyframes fade-up {
            from { opacity: 0; transform: translateY(24px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .fade-up {
            opacity: 0;
            animation: fade-up 0.9s cubic-bezier(0.22, 1, 0.36, 1) forwards;
        }

        /* Hand-drawn underline sweep under the accent words */
        @keyframes draw-line {
            from { stroke-dashoffset: 260; }
            to   { stroke-dashoffset: 0; }
        }

        .underline-sweep {
            stroke-dasharray: 260;
            stroke-dashoffset: 260;
            animation: draw-line 1s ease-out 1.1s forwards;
        }

        /* Soft pulsing glow behind the button */
        @keyframes glow-pulse {
            0%, 100% {
                box-shadow: 0 0 0 0 rgba(37, 99, 235, 0.45), 0 12px 32px -8px rgba(37, 99, 235, 0.55);
            }
            50% {
                box-shadow: 0 0 0 10px rgba(37, 99, 235, 0), 0 16px 40px -8px rgba(37, 99, 235, 0.7);
            }
        }

        .glow-pulse {
            animation: glow-pulse 2.6s ease-in-out infinite;
        }

        /* Layered overlay: vignette + darkening so text always pops */
        .cover-overlay {
            background:
                radial-gradient(ellipse 90% 65% at 50% 42%, rgba(15, 23, 42, 0.18) 0%, rgba(15, 23, 42, 0.62) 100%),
                linear-gradient(to bottom, rgba(15, 23, 42, 0.35) 0%, rgba(15, 23, 42, 0.25) 40%, rgba(15, 23, 42, 0.75) 100%);
        }

        /* Subtle grid texture */
        .grid-texture {
            background-image:
                linear-gradient(to right, rgba(255, 255, 255, 0.05) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(255, 255, 255, 0.05) 1px, transparent 1px);
            background-size: 48px 48px;
            mask-image: radial-gradient(ellipse 70% 60% at 50% 45%, black 30%, transparent 75%);
            -webkit-mask-image: radial-gradient(ellipse 70% 60% at 50% 45%, black 30%, transparent 75%);
        }

        /* Gradient sheen on the title text */
        .title-shine {
            background: linear-gradient(105deg, #ffffff 55%, #c7d9ff 78%, #ffffff 100%);
            -webkit-background-clip: text;
            background-clip: text;
        }
    </style>
</head>

<body class="font-sans antialiased bg-navy">

    <!-- ==================== COVER ==================== -->
    <section class="relative h-screen w-full overflow-hidden bg-navy">

        <!-- Cover image with slow zoom -->
        <img src="img/inventory.png" alt="" class="kenburns absolute inset-0 w-full h-full object-cover">

        <!-- Overlays -->
        <div class="absolute inset-0 cover-overlay pointer-events-none"></div>
        <div class="absolute inset-0 grid-texture pointer-events-none" aria-hidden="true"></div>

        <!-- Floating ambient orbs -->
        <div class="absolute -top-24 -left-24 w-96 h-96 rounded-full bg-primary/25 blur-3xl pointer-events-none animate-pulse" style="animation-duration: 6s;" aria-hidden="true"></div>
        <div class="absolute -bottom-28 -right-20 w-[26rem] h-[26rem] rounded-full bg-accent/20 blur-3xl pointer-events-none animate-pulse" style="animation-duration: 8s;" aria-hidden="true"></div>

        <!-- Content -->
        <div class="relative z-10 flex flex-col items-center justify-center h-full px-4 sm:px-6">

            <!-- Decorative icon badge -->
            <div class="fade-up mb-7 inline-flex items-center justify-center w-16 h-16 sm:w-20 sm:h-20 rounded-2xl bg-white/10 backdrop-blur-md border border-white/25 shadow-xl shadow-black/20 rotate-3 hover:rotate-0 transition-transform duration-500" style="animation-delay: 0.05s;">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 sm:w-10 sm:h-10 text-white drop-shadow" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z" />
                    <path d="m3.3 7 8.7 5 8.7-5" />
                    <path d="M12 22V12" />
                </svg>
            </div>

            <!-- Title -->
            <h1 class="fade-up max-w-4xl text-center font-black tracking-tight leading-[1.12] title-shine text-transparent drop-shadow-2xl text-3xl sm:text-5xl lg:text-[3.6rem]" style="animation-delay: 0.25s;">
                Inventory Management and
                <span class="relative inline-block">
                    <span class="text-accent">Demand Forecasting</span>
                    <svg class="absolute -bottom-2 sm:-bottom-3 left-0 w-full" height="10" viewBox="0 0 240 10" fill="none" preserveAspectRatio="none" aria-hidden="true">
                        <path class="underline-sweep" d="M3 7C60 2 180 2 237 7" stroke="#F59E0B" stroke-width="4.5" stroke-linecap="round" />
                    </svg>
                </span>
                System
            </h1>

            <!-- Get Started button -->
            <div class="fade-up mt-11 flex flex-col items-center gap-5" style="animation-delay: 0.5s;">
                <a href="login.php"
                   class="glow-pulse group relative inline-flex items-center justify-center gap-2.5 overflow-hidden px-10 py-4 text-base sm:text-lg font-bold text-white rounded-2xl bg-gradient-to-r from-blue-600 to-blue-500 hover:-translate-y-1 active:translate-y-0 focus:outline-none focus:ring-4 focus:ring-blue-400/40 transition-transform duration-300">
                    <!-- Shine sweep on hover -->
                    <span class="absolute inset-0 -translate-x-full group-hover:translate-x-full bg-gradient-to-r from-transparent via-white/30 to-transparent transition-transform duration-700 ease-out" aria-hidden="true"></span>
                    Get Started
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 group-hover:translate-x-1 transition-transform duration-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 12h14" />
                        <path d="m12 5 7 7-7 7" />
                    </svg>
                </a>
            </div>
        </div>

        <!-- Bottom edge fade into navy -->
        <div class="absolute bottom-0 left-0 right-0 h-28 bg-gradient-to-t from-navy/80 to-transparent pointer-events-none" aria-hidden="true"></div>
    </section>

</body>

</html>
