<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>النظام قيد الصيانة | سفرك علينا</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&family=Tajawal:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Cairo', 'Tajawal', sans-serif;
            min-height: 100vh;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            overflow-x: hidden;
            position: relative;
        }

        /* Animated background particles */
        .bg-particles {
            position: absolute;
            inset: 0;
            overflow: hidden;
            pointer-events: none;
        }
        .particle {
            position: absolute;
            background: rgba(56, 189, 248, 0.15);
            border-radius: 50%;
            animation: float 15s infinite ease-in-out;
        }
        .particle:nth-child(1) { width: 300px; height: 300px; top: -100px; right: -100px; animation-delay: 0s; }
        .particle:nth-child(2) { width: 400px; height: 400px; bottom: -150px; left: -150px; animation-delay: 3s; background: rgba(251, 191, 36, 0.1); }
        .particle:nth-child(3) { width: 200px; height: 200px; top: 40%; left: 10%; animation-delay: 6s; background: rgba(168, 85, 247, 0.1); }

        @keyframes float {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(30px, -30px) scale(1.1); }
            66% { transform: translate(-20px, 20px) scale(0.95); }
        }

        /* Logo */
        .logo {
            width: 80px;
            height: 80px;
            margin: 0 auto 2rem;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            border-radius: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 20px 60px rgba(59, 130, 246, 0.4);
            animation: pulse-glow 3s infinite;
        }
        @keyframes pulse-glow {
            0%, 100% { box-shadow: 0 20px 60px rgba(59, 130, 246, 0.4); }
            50% { box-shadow: 0 20px 80px rgba(59, 130, 246, 0.7); }
        }

        /* Maintenance Illustration */
        .illustration {
            max-width: 420px;
            width: 100%;
            height: auto;
            margin: 0 auto 2rem;
            display: block;
            filter: drop-shadow(0 20px 40px rgba(59, 130, 246, 0.2));
            animation: float-soft 6s ease-in-out infinite;
        }
        @keyframes float-soft {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-12px); }
        }

        /* Spinning gear */
        .gear-rotate {
            transform-origin: center;
            animation: spin 8s linear infinite;
        }
        .gear-rotate-reverse {
            transform-origin: center;
            animation: spin-reverse 6s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        @keyframes spin-reverse { to { transform: rotate(-360deg); } }

        /* Title */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: rgba(251, 191, 36, 0.15);
            border: 1px solid rgba(251, 191, 36, 0.3);
            border-radius: 999px;
            color: #fbbf24;
            font-size: 0.875rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
        }
        .badge .dot {
            width: 8px;
            height: 8px;
            background: #fbbf24;
            border-radius: 50%;
            animation: ping 1.5s infinite;
        }
        @keyframes ping {
            0% { transform: scale(1); opacity: 1; }
            75%, 100% { transform: scale(2); opacity: 0; }
        }

        h1 {
            font-size: clamp(1.75rem, 4vw, 2.5rem);
            font-weight: 900;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, #fff 0%, #94a3b8 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1.3;
        }
        p {
            font-size: 1.125rem;
            color: #94a3b8;
            line-height: 1.8;
            max-width: 500px;
            margin: 0 auto 2.5rem;
        }

        /* Progress bar */
        .progress {
            max-width: 360px;
            margin: 0 auto 2rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 999px;
            padding: 4px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            overflow: hidden;
        }
        .progress-bar {
            height: 8px;
            background: linear-gradient(90deg, #3b82f6, #8b5cf6, #3b82f6);
            background-size: 200% 100%;
            border-radius: 999px;
            width: 75%;
            animation: shimmer 2s linear infinite;
        }
        @keyframes shimmer {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        /* Contact */
        .contact-card {
            margin-top: 2.5rem;
            padding: 1.5rem;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 1.5rem;
            backdrop-filter: blur(10px);
        }
        .contact-card h3 {
            font-size: 0.875rem;
            font-weight: 700;
            color: #cbd5e1;
            margin-bottom: 1rem;
        }
        .contact-item {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            color: #94a3b8;
            font-size: 0.95rem;
            margin-bottom: 0.5rem;
        }
        .contact-item svg {
            width: 18px;
            height: 18px;
            color: #3b82f6;
        }

        footer {
            margin-top: 2.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            color: #475569;
            font-size: 0.85rem;
        }

        @media (max-width: 640px) {
            .logo { width: 64px; height: 64px; }
            p { font-size: 1rem; }
        }
    </style>
</head>
<body>
    <div class="bg-particles">
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
    </div>

    <main style="position: relative; z-index: 10; max-width: 720px; width: 100%; text-align: center;">

        {{-- Logo --}}
        <div class="logo">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="white" style="width: 44px; height: 44px;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" />
            </svg>
        </div>

        {{-- Maintenance Illustration --}}
        <svg class="illustration" viewBox="0 0 500 350" xmlns="http://www.w3.org/2000/svg">
            <defs>
                <linearGradient id="grad-blue" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" style="stop-color:#3b82f6;stop-opacity:0.9" />
                    <stop offset="100%" style="stop-color:#1d4ed8;stop-opacity:0.7" />
                </linearGradient>
                <linearGradient id="grad-amber" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" style="stop-color:#fbbf24;stop-opacity:1" />
                    <stop offset="100%" style="stop-color:#f59e0b;stop-opacity:0.8" />
                </linearGradient>
                <linearGradient id="grad-purple" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" style="stop-color:#a855f7;stop-opacity:0.8" />
                    <stop offset="100%" style="stop-color:#7e22ce;stop-opacity:0.6" />
                </linearGradient>
            </defs>

            {{-- Background circle glow --}}
            <circle cx="250" cy="175" r="130" fill="url(#grad-blue)" opacity="0.1"/>
            <circle cx="250" cy="175" r="100" fill="url(#grad-blue)" opacity="0.15"/>

            {{-- Server/Maintenance Box --}}
            <g transform="translate(170, 90)">
                {{-- Server body --}}
                <rect x="0" y="20" width="160" height="120" rx="12" fill="url(#grad-blue)" stroke="#60a5fa" stroke-width="2"/>
                {{-- Server lines --}}
                <rect x="15" y="40" width="80" height="6" rx="3" fill="#fff" opacity="0.7"/>
                <rect x="15" y="55" width="60" height="6" rx="3" fill="#fff" opacity="0.4"/>
                <rect x="15" y="75" width="100" height="4" rx="2" fill="#fff" opacity="0.5"/>
                <rect x="15" y="85" width="70" height="4" rx="2" fill="#fff" opacity="0.3"/>
                {{-- LED lights --}}
                <circle cx="135" cy="43" r="4" fill="#10b981">
                    <animate attributeName="opacity" values="1;0.3;1" dur="1.5s" repeatCount="indefinite"/>
                </circle>
                <circle cx="120" cy="43" r="4" fill="#fbbf24">
                    <animate attributeName="opacity" values="0.3;1;0.3" dur="2s" repeatCount="indefinite"/>
                </circle>
                {{-- Vent slots --}}
                <rect x="15" y="100" width="130" height="3" rx="1.5" fill="#fff" opacity="0.3"/>
                <rect x="15" y="110" width="130" height="3" rx="1.5" fill="#fff" opacity="0.3"/>
                <rect x="15" y="120" width="130" height="3" rx="1.5" fill="#fff" opacity="0.3"/>
            </g>

            {{-- Big Gear (top right, rotating) --}}
            <g class="gear-rotate" transform="translate(380, 100)">
                <path d="M 0,-40 L 8,-38 L 12,-30 L 22,-32 L 28,-22 L 22,-12 L 28,-4 L 22,4 L 28,12 L 22,22 L 12,30 L 8,38 L 0,40 L -8,38 L -12,30 L -22,32 L -28,22 L -22,12 L -28,4 L -22,-4 L -28,-12 L -22,-22 L -12,-30 L -8,-38 Z"
                      fill="url(#grad-amber)" stroke="#f59e0b" stroke-width="2"/>
                <circle cx="0" cy="0" r="14" fill="#0f172a" stroke="#fbbf24" stroke-width="2"/>
            </g>

            {{-- Small Gear (bottom left, reverse rotating) --}}
            <g class="gear-rotate-reverse" transform="translate(110, 230)">
                <path d="M 0,-28 L 6,-26 L 8,-22 L 16,-23 L 20,-15 L 16,-8 L 20,-3 L 16,3 L 20,8 L 16,15 L 8,22 L 6,26 L 0,28 L -6,26 L -8,22 L -16,23 L -20,15 L -16,8 L -20,3 L -16,-3 L -20,-8 L -16,-15 L -8,-22 L -6,-26 Z"
                      fill="url(#grad-purple)" stroke="#7e22ce" stroke-width="2"/>
                <circle cx="0" cy="0" r="10" fill="#0f172a" stroke="#a855f7" stroke-width="2"/>
            </g>

            {{-- Wrench --}}
            <g transform="translate(380, 240) rotate(45)">
                <rect x="-4" y="-30" width="8" height="60" rx="2" fill="url(#grad-amber)"/>
                <path d="M -10,-30 L -10,-40 L -4,-44 L 4,-44 L 10,-40 L 10,-30 L 4,-34 L -4,-34 Z" fill="url(#grad-amber)"/>
                <circle cx="0" cy="32" r="6" fill="#0f172a" stroke="#fbbf24" stroke-width="2"/>
            </g>

            {{-- Sparkles --}}
            <g fill="#fbbf24">
                <circle cx="80" cy="80" r="2">
                    <animate attributeName="opacity" values="0;1;0" dur="2s" repeatCount="indefinite"/>
                </circle>
                <circle cx="420" cy="180" r="2">
                    <animate attributeName="opacity" values="0;1;0" dur="2.5s" repeatCount="indefinite"/>
                </circle>
                <circle cx="60" cy="180" r="2">
                    <animate attributeName="opacity" values="0;1;0" dur="1.8s" repeatCount="indefinite"/>
                </circle>
            </g>
        </svg>

        {{-- Badge --}}
        <div class="badge">
            <span class="dot"></span>
            <span>صيانة مجدولة</span>
        </div>

        {{-- Title --}}
        <h1>النظام يخضع للصيانة حالياً</h1>
        <p>
            نعمل حالياً على تطوير وتحسين المنظومة لنقدم لكم تجربة أفضل.
            سنعود للعمل خلال وقت قصير — شكراً لصبركم وتفهمكم.
        </p>

        {{-- Progress --}}
        <div class="progress">
            <div class="progress-bar"></div>
        </div>

        {{-- Contact --}}
        <div class="contact-card">
            <h3>للاستفسارات العاجلة</h3>
            <div class="contact-item">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z" />
                </svg>
                <span dir="ltr">+966 50 000 0000</span>
            </div>
            <div class="contact-item">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                </svg>
                <span dir="ltr">support@safarakealayna.com</span>
            </div>
        </div>

        <footer>
            &copy; {{ date('Y') }} Safarak Ealayna — جميع الحقوق محفوظة
        </footer>
    </main>
</body>
</html>