<div class="demo-box">
    <!-- Header -->
    <div class="demo-header">
        <div class="demo-icon-wrapper">
            <x-heroicon-o-key />
        </div>
        <div class="demo-title-container">
            <span class="demo-title">بيانات الوصول السريع</span>
            <span class="demo-subtitle">اضغط للتعبئة التلقائية السريعة</span>
        </div>
    </div>

    <!-- Buttons Grid -->
    <div class="demo-buttons-grid">
        <button
            type="button"
            wire:click="fillAdminDemo"
            class="demo-btn"
        >
            <div class="demo-btn-info">
                <span class="demo-btn-role">مدير النظام</span>
                <span class="demo-btn-desc">Full Access Control</span>
            </div>
            <code class="demo-btn-badge">admin@admin.com</code>
        </button>
        
        <button
            type="button"
            wire:click="fillEmployeeDemo"
            class="demo-btn"
        >
            <div class="demo-btn-info">
                <span class="demo-btn-role">موظف (مثال)</span>
                <span class="demo-btn-desc">Standard Operations</span>
            </div>
            <code class="demo-btn-badge">employee1@office.com</code>
        </button>
    </div>
    
    <!-- Footer -->
    <div class="demo-footer">
        <p class="demo-footer-text">
            <span class="demo-pulse-dot"></span>
            كلمة المرور الافتراضية: <span class="text-white font-mono" style="color: #ffffff; font-weight: bold; margin: 0 4px;">11223311</span> للمدير و <span class="text-white font-mono" style="color: #ffffff; font-weight: bold; margin: 0 4px;">password</span> للموظف.
        </p>
    </div>
</div>
