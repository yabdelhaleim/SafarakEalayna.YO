<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>غير مسموح بالوصول | 403</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&family=Outfit:wght@400;700;900&family=Noto+Sans+Arabic:wght@400;700;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Outfit', 'Noto Sans Arabic', sans-serif; }
    </style>
</head>
<body class="bg-[#110a16] text-white min-h-screen flex items-center justify-center p-6 overflow-hidden">
    <!-- Decorative background -->
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
        <div class="absolute top-0 right-0 w-[500px] h-[500px] bg-amber-500/10 rounded-full blur-[120px] -translate-y-1/2 translate-x-1/3"></div>
        <div class="absolute bottom-0 left-0 w-[500px] h-[500px] bg-red-500/10 rounded-full blur-[120px] translate-y-1/2 -translate-x-1/3"></div>
    </div>

    <div class="relative z-10 text-center max-w-2xl mx-auto">
        <div class="mb-8">
            <h1 class="text-[150px] font-black leading-none tracking-tighter text-transparent bg-clip-text bg-gradient-to-b from-white to-white/10 opacity-20">403</h1>
            <div class="mt-[-80px]">
                <div class="inline-flex h-20 w-20 items-center justify-center rounded-3xl bg-amber-500/20 border border-amber-500/30 mb-6">
                    <svg class="w-10 h-10 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                </div>
                <h2 class="text-3xl sm:text-4xl font-black mb-4">وصول مقيد</h2>
                <p class="text-gray-400 text-lg mb-10 leading-relaxed">ليس لديك الصلاحيات الكافية للوصول إلى هذه الصفحة. يرجى التواصل مع مسؤول النظام إذا كنت تعتقد أن هذا خطأ.</p>
                
                <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                    <a href="/dashboard" class="w-full sm:w-auto px-8 py-4 bg-amber-600 hover:bg-amber-500 text-white font-bold rounded-2xl transition-all hover:scale-105 shadow-xl shadow-amber-600/20">
                        العودة للوحة التحكم
                    </a>
                    <button onclick="window.history.back()" class="w-full sm:w-auto px-8 py-4 bg-white/5 hover:bg-white/10 border border-white/10 text-white font-bold rounded-2xl transition-all">
                        رجوع للخلف
                    </button>
                </div>
            </div>
        </div>
        
        <div class="pt-12 border-t border-white/5 text-gray-500 text-xs uppercase tracking-widest">
            Safarak Ealayna &copy; {{ date('Y') }} • Security Access Control
        </div>
    </div>
</body>
</html>
