## الهدف
إنشاء **سكربت deploy مستقل + ملفات env + توثيق** يخلّي staging شغّال على مسار منفصل بـ VHost مستقل، مع DB منفصلة، من غير ما يأثر على الـ production.

## المشاكل اللي تأكدت منها
1. ❌ لا يوجد `.env.staging.*` ولا `deploy/staging.sh`
2. ❌ `tests/e2e/fix_staging_data.php` و `tests/e2e/flights_e2e_staging.php` حاطين `APP_ENV=staging` كحارس — مستحيل يشتغلوا حالياً
3. ❌ `upload_run_staging_safely.sh` سكريبت يدوي مخصّص لتشغيل سكريبت واحد فقط (مش flow عام)
4. ❌ مفيش توثيق في `deploy/README.md` للـ staging

## الملفات اللي هتنشأ/تتعدّل

### 1. `deploy/staging.sh` (جديد — ≈220 سطر)
سكريبت deploy مخصّص للـ staging، مأخوذ من `deploy/deploy.sh` مع التعديلات دي:
- **APP_DIR افتراضي**: `/var/www/safarakealayna-staging`
- **APP_USER**: `www-data` (نفس)
- **LOG_DIR**: `/var/log/safarakealayna-deploy-staging`
- **BRANCH افتراضي**: `main` (بدل ما يلتقط الـ branch الحالي)
- **ENV_FILE**: يستخدم `.env.staging` (مش `.env`)
- **PHP_FPM_SERVICE**: `php8.3-fpm` (نفس)
- **Maintenance mode**: شغّال قبل الـ deploy وبيتطفي بعده (زي الإنتاج)
- **Caches**: clear + warm (نفس)
- **Queue restart**: شغّال
- **Permissions**: chown نفس
- **DRY_RUN**: مدعوم
- **Flags**: `--branch=`, `--no-build`, `--no-migrate`, `--no-seed`, `--no-backup`, `--dir=`, `--user=`, `--fpm=`, `--dry-run`, `-h`
- **Trap**: لو فشلت أي خطوة، يطفي maintenance mode تلقائياً (نفس النمط)
- **تعليق خاص في الـ header**: يوضح إن السكريبت ده **للـ staging فقط** — يفصل كلياً عن الإنتاج

### 2. `deploy/staging.conf.example` (جديد)
نفس فكرة `deploy.conf.example` لكن للـ staging:
```bash
APP_DIR="/var/www/safarakealayna-staging"
APP_USER="www-data"
APP_GROUP="www-data"
PHP_FPM_SERVICE="php8.3-fpm"
LOG_DIR="/var/log/safarakealayna-deploy-staging"
BRANCH="main"
```

### 3. `deploy/.env.staging.example` (جديد)
Template للـ `.env.staging`، مع القيم اللي المفروض تختلف عن `.env.example`:
- `APP_ENV=staging`
- `APP_DEBUG=false` (مثل production)
- `APP_NAME="Travel Office System (STG)"`
- `APP_URL=https://staging.your-domain.com`
- `DB_CONNECTION=mysql` (نفس السيرفر)
- `DB_DATABASE=safarakealayna_staging` (DB منفصلة)
- `CACHE_STORE=database`
- `SESSION_SECURE_COOKIE=true`
- `CORS_ALLOWED_ORIGINS=https://staging.your-domain.com`
- `MAIL_MAILER=log` (عشان الإيميلات ما تطلعش)
- `LOG_CHANNEL=daily`, `LOG_LEVEL=debug`
- `SANCTUM_STATEFUL_DOMAINS=staging.your-domain.com`
- تعليق في الـ header: "Copy to .env.staging on the server, then run: php artisan key:generate"

### 4. `deploy/README.md` (تعديل)
إضافة قسم `## Staging deploy` بعد قسم `Daily deploys`:
- مسار افتراضي مختلف
- Nginx vhost example (نفس جزء production لكن على port مختلف أو server_name مختلف)
- إزاي تنشئ staging على سيرفر جديد
- إزاي تنسخ `.env.staging.example` إلى `.env.staging`
- `--dry-run` للتجربة
- Tabel للفلاغز

### 5. `.gitignore` (تعديل)
إضافة:
```
# Staging environment
.env.staging
```

## النتيجة المتوقعة
بعد تنفيذ الخطة دي، على السيرفر هتقدر تعمل:

```bash
# 1. أول مرة: جهّز الملفات
sudo mkdir -p /var/www/safarakealayna-staging
sudo chown -R deploy:www-data /var/www/safarakealayna-staging
sudo -u deploy git clone <repo-url> /var/www/safarakealayna-staging
cd /var/www/safarakealayna-staging
cp deploy/.env.staging.example .env.staging
php artisan key:generate
cp deploy/staging.conf.example deploy/staging.conf
# عدّل APP_URL, DB creds, CORS في .env.staging لو محتاج

# 2. أي تعديل بعد كده
sudo -u deploy ./deploy/staging.sh

# 3. لو عايز تجرب بدون ما تعمل حاجة فعلاً
sudo -u deploy ./deploy/staging.sh --dry-run

# 4. لو عايز تنفّذ سكريبت على staging بدون confusion
APP_ENV=staging php artisan tinker --execute='...'
# (دلوقتي مافيش حارس AP_ENV=staging يفشل لأن .env.staging موجود)
```

## Checklist للـ "تأكد إن staging جاهز" (تعليمات بعد التنفيذ)
السكريبتات `tests/e2e/fix_staging_data.php` و `tests/e2e/flights_e2e_staging.php` لسه هيقولوا "REFUSED: must run on staging" لحد ما يدّوا بيانات STG. أنت قلت "سكيب الـ seeders" — فهتتجاهل دول لحد ما تعمل seeder، أو تشغّلهم على staging مع data جاهزة.

## معتمد من المستخدم
- ✅ مسار منفصل + VHost مستقل
- ✅ بدون StagingSeeder
- ✅ Maintenance mode زي الإنتاج

## ملاحظات
- مش هعمل `.env.staging` نفسه (ملف الـ secrets) — هكتب بس `.env.staging.example`
- مش هعمل أي تغيير في `deploy/deploy.sh` — السكريبت الجديد منفصل تماماً
- مش هلمس `tests/e2e/*` — اللي فيها حراس `APP_ENV=staging` هتشتغل تلقائياً بعد ما `.env.staging` يبقى موجود