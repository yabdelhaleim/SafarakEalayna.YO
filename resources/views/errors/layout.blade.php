<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title') - سفرك علينا</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800;900&family=Noto+Sans+Arabic:wght@400;600;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            200: '#bfdbfe',
                            300: '#93c5fd',
                            400: '#60a5fa',
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8',
                            800: '#1e40af',
                            900: '#1e3a8a',
                        }
                    },
                    fontFamily: {
                        sans: ['Outfit', 'Noto Sans Arabic', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    <style>
        @keyframes plane-fly {
            0% { transform: translate(0, 0) rotate(0deg); }
            25% { transform: translate(10px, -10px) rotate(2deg); }
            50% { transform: translate(0, 0) rotate(0deg); }
            75% { transform: translate(-10px, 10px) rotate(-2deg); }
            100% { transform: translate(0, 0) rotate(0deg); }
        }
        .animate-plane {
            animation: plane-fly 6s ease-in-out infinite;
        }
        .glass {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
    </style>
</head>
<body class="bg-[#f8fafc] text-slate-900 min-h-screen flex items-center justify-center p-6 overflow-hidden">
    {{-- Background Elements --}}
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
        <div class="absolute top-0 right-0 w-[600px] h-[600px] bg-primary-100/50 rounded-full blur-[120px] -translate-y-1/2 translate-x-1/3"></div>
        <div class="absolute bottom-0 left-0 w-[600px] h-[600px] bg-primary-50 rounded-full blur-[120px] translate-y-1/2 -translate-x-1/3"></div>
        <div class="absolute top-1/2 left-1/2 w-full h-full bg-grid-slate-100 [mask-image:radial-gradient(white,transparent)] -translate-x-1/2 -translate-y-1/2 opacity-20"></div>
    </div>

    <div class="relative z-10 w-full max-w-xl mx-auto">
        <div class="glass rounded-[2.5rem] p-8 md:p-12 shadow-2xl shadow-primary-200/50 text-center">
            {{-- Logo Section --}}
            <div class="mb-10 flex flex-col items-center">
                <div class="w-20 h-20 bg-primary-600 rounded-3xl flex items-center justify-center shadow-xl shadow-primary-500/20 mb-6 animate-plane">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="white" class="w-10 h-10">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" />
                    </svg>
                </div>
                <h1 class="text-7xl font-black text-primary-900/10 mb-[-2.5rem] select-none">@yield('code')</h1>
            </div>

            <div class="space-y-6">
                <h2 class="text-3xl font-extrabold text-slate-900 tracking-tight">@yield('message')</h2>
                <p class="text-slate-500 text-lg leading-relaxed max-w-md mx-auto">@yield('description')</p>
                
                <div class="pt-6 flex flex-col sm:flex-row items-center justify-center gap-4">
                    <a href="{{ url('/') }}" class="w-full sm:w-auto px-10 py-4 bg-primary-600 hover:bg-primary-700 text-white font-bold rounded-2xl transition-all duration-300 shadow-lg shadow-primary-500/25 hover:-translate-y-1">
                        العودة للرئيسية
                    </a>
                    <button onclick="window.location.reload()" class="w-full sm:w-auto px-10 py-4 bg-white hover:bg-slate-50 text-slate-700 font-bold rounded-2xl transition-all duration-300 border border-slate-200 hover:border-slate-300">
                        تحديث الصفحة
                    </button>
                </div>
            </div>

            <div class="mt-12 pt-8 border-t border-slate-100 flex flex-col items-center gap-4">
                <div class="flex items-center gap-2 text-slate-400 font-medium">
                    <span class="w-2 h-2 rounded-full bg-primary-400"></span>
                    سفرك علينا - رحلة آمنة دائماً
                </div>
                <div class="text-slate-400 text-sm">
                    &copy; {{ date('Y') }} Safarak Ealayna. جميع الحقوق محفوظة.
                </div>
            </div>
        </div>
    </div>
</body>
</html>
