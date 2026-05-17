<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>غير مصرح بالدخول | 401</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&family=Outfit:wght@400;700;900&family=Noto+Sans+Arabic:wght@400;700;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Outfit', 'Noto Sans Arabic', sans-serif; }
    </style>
</head>
<body class="bg-[#0f172a] text-white min-h-screen flex items-center justify-center p-6 overflow-hidden">
    <!-- Decorative background -->
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
        <div class="absolute top-0 right-0 w-[500px] h-[500px] bg-slate-500/10 rounded-full blur-[120px] -translate-y-1/2 translate-x-1/3"></div>
        <div class="absolute bottom-0 left-0 w-[500px] h-[500px] bg-slate-500/10 rounded-full blur-[120px] translate-y-1/2 -translate-x-1/3"></div>
    </div>

    <div class="relative z-10 text-center max-w-2xl mx-auto">
        <div class="mb-8">
            <h1 class="text-[150px] font-black leading-none tracking-tighter text-transparent bg-clip-text bg-gradient-to-b from-white to-white/10 opacity-20 uppercase">401</h1>
            <div class="mt-[-80px]">
                <div class="inline-flex h-20 w-20 items-center justify-center rounded-3xl bg-slate-500/20 border border-slate-500/30 mb-6">
                    <svg class="w-10 h-10 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path></svg>
                </div>
                <h2 class="text-3xl sm:text-4xl font-black mb-4">يجب تسجيل الدخول أولاً</h2>
                <p class="text-gray-400 text-lg mb-10 leading-relaxed">تحاول الوصول إلى صفحة تتطلب مصادقة. يرجى تسجيل الدخول للوصول إلى بياناتك.</p>
                
                <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                    <a href="/login" class="w-full sm:w-auto px-8 py-4 bg-white text-slate-900 font-bold rounded-2xl transition-all hover:scale-105 shadow-xl shadow-white/10">
                        تسجيل الدخول الآن
                    </a>
                    <a href="/dashboard" class="w-full sm:w-auto px-8 py-4 bg-white/5 hover:bg-white/10 border border-white/10 text-white font-bold rounded-2xl transition-all">
                        العودة للرئيسية
                    </a>
                </div>
            </div>
        </div>
        
        <div class="pt-12 border-t border-white/5 text-gray-500 text-xs uppercase tracking-widest">
            Safarak Ealayna &copy; {{ date('Y') }} • Authentication Control
        </div>
    </div>
</body>
</html>
