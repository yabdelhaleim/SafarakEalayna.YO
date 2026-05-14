# Kilo - Filament v3 Integration Commands
# Run these commands in order to complete the setup

# 1. Clear all caches to ensure new middleware and config are loaded
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear

# 2. Optimize (optional - for production)
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 3. Check if Filament admin user exists - create one if needed
# First, make sure you have an admin user in the database
# You can create one via tinker or seeder:
php artisan tinker
>>> App\Models\User::create([
...     'name' => 'Admin',
...     'email' => 'admin@example.com',
...     'password' => bcrypt('password'),
...     'role' => 'admin',
...     'is_active' => true,
... ]);

# 4. Ensure the admin panel recognizes admin users only
# The user must have role = 'admin' to access /admin

# 5. Test the integration
# - Login to Vue app as admin user
# - You should see "لوحة التحكم الإدارية" button in sidebar
# - Click it → opens /admin in new tab
# - In Filament sidebar → you should see "العودة للتطبيق" link at the top
# - Click it → returns to /dashboard

# 6. Migrations (if any new ones needed)
# No new migrations required - using existing tables

# 7. To add new module in future:
# Step 1: Create the Model
# Step 2: Create Admin Resource:
#   php artisan make:filament-resource --generate --no-soft-deletes --resource="Models/YourModel"
#   OR manually create: app/Filament/Admin/Resources/YourModels/YourModelResource.php
#   with Pages\ManageYourModels
# Step 3: Add resource to config/filament.php resources[] array
# Step 4: Add navigation group if needed
# Step 5: Clear cache
