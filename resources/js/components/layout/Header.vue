<template>
  <div class="header-inner">

    <!-- ═══════════════════════════════════════
         RIGHT SECTION: Title + Breadcrumbs
    ════════════════════════════════════════ -->
    <div class="header-start">

      <!-- Page identity -->
      <div class="page-identity">
        <h1 class="page-title">{{ pageTitle }}</h1>
        <p v-if="pageSubtitle" class="page-subtitle">{{ pageSubtitle }}</p>
      </div>

      <!-- Breadcrumbs -->
      <nav v-if="breadcrumbs.length > 1" class="breadcrumbs" aria-label="مسار التنقل">
        <template v-for="(crumb, index) in breadcrumbs" :key="index">
          <router-link
            :to="crumb.to"
            :class="['crumb', index === breadcrumbs.length - 1 ? 'crumb--active' : '']"
          >
            {{ crumb.label }}
          </router-link>
          <ChevronLeft v-if="index < breadcrumbs.length - 1" class="crumb-sep" />
        </template>
      </nav>
    </div>

    <!-- ═══════════════════════════════════════
         LEFT SECTION: Actions
    ════════════════════════════════════════ -->
    <div class="header-end">

      <!-- Search -->
      <div class="search-wrap" :class="{ 'search-wrap--focused': isSearchFocused }">
        <Search class="search-icon" />
        <input
          v-model="searchQuery"
          type="text"
          placeholder="بحث سريع..."
          class="search-input"
          @focus="isSearchFocused = true"
          @blur="isSearchFocused = false"
          aria-label="بحث"
        />
        <!-- Glow when focused -->
        <span class="search-glow" aria-hidden="true" />
      </div>

      <!-- Divider -->
      <div class="header-sep" aria-hidden="true" />

      <!-- Refresh -->
      <button
        class="icon-btn"
        :class="{ 'icon-btn--spinning': isRefreshing }"
        title="تحديث البيانات"
        @click="handleRefresh"
        aria-label="تحديث"
      >
        <RefreshCw class="w-4 h-4" :class="{ 'animate-spin': isRefreshing }" />
      </button>

      <!-- Notifications -->
      <button class="icon-btn notif-btn" title="الإشعارات" aria-label="الإشعارات">
        <Bell class="w-4 h-4" />
        <transition name="badge">
          <span v-if="notificationCount > 0" class="notif-badge">
            {{ notificationCount > 9 ? '9+' : notificationCount }}
          </span>
        </transition>
      </button>

      <!-- Divider -->
      <div class="header-sep" aria-hidden="true" />

      <!-- User Menu -->
      <div class="user-menu-wrap" ref="userMenuRef">
        <button
          class="user-btn"
          :class="{ 'user-btn--open': isUserMenuOpen }"
          @click="toggleUserMenu"
          aria-haspopup="true"
          :aria-expanded="isUserMenuOpen"
          aria-label="قائمة المستخدم"
        >
          <!-- Avatar -->
          <div class="user-avatar">
            <span class="avatar-letter">{{ userInitial }}</span>
            <span class="avatar-glow" aria-hidden="true" />
          </div>
          <!-- Name + role -->
          <div class="user-info">
            <span class="user-name">مدير النظام</span>
            <span class="user-role">مدير النظام</span>
          </div>
          <ChevronDown class="user-chevron" :class="{ 'rotate-180': isUserMenuOpen }" />
        </button>

        <!-- Dropdown -->
        <transition name="dropdown">
          <div v-if="isUserMenuOpen" class="dropdown" role="menu">
            <!-- User info header -->
            <div class="dropdown-header">
              <div class="dropdown-avatar">
                <span>{{ userInitial }}</span>
              </div>
              <div>
                <p class="dropdown-name">مدير النظام</p>
                <p class="dropdown-email">admin@system.com</p>
              </div>
            </div>

            <div class="dropdown-divider" />

            <button class="dropdown-item" role="menuitem" @click="isUserMenuOpen = false">
              <Settings class="w-4 h-4" />
              <span>الإعدادات</span>
            </button>

            <div class="dropdown-divider" />

            <button class="dropdown-item dropdown-item--danger" role="menuitem" @click="handleLogout">
              <LogOut class="w-4 h-4" />
              <span>تسجيل الخروج</span>
            </button>
          </div>
        </transition>
      </div>

    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useRoute } from 'vue-router';
import {
  Search, Bell, RefreshCw,
  ChevronDown, ChevronLeft,
  Settings, LogOut,
} from 'lucide-vue-next';

/* ─── Emits ─── */
const emit = defineEmits(['toggle-sidebar']);

/* ─── Route ─── */
const route = useRoute();

/* ─── State ─── */
const searchQuery      = ref('');
const isSearchFocused  = ref(false);
const isRefreshing     = ref(false);
const isUserMenuOpen   = ref(false);
const notificationCount = ref(3);     // TODO: fetch from API
const userMenuRef      = ref(null);

/* ─── Computed ─── */
const pageTitle    = computed(() => route.meta?.title    || 'لوحة التحكم');
const pageSubtitle = computed(() => route.meta?.subtitle || '');
const userInitial  = computed(() => 'م');    // TODO: from auth store

const breadcrumbs = computed(() => {
  const crumbs = [{ label: 'الرئيسية', to: '/dashboard' }];
  if (route.meta?.title && route.path !== '/dashboard') {
    crumbs.push({ label: route.meta.title, to: route.path });
  }
  return crumbs;
});

/* ─── Actions ─── */
const toggleUserMenu = () => { isUserMenuOpen.value = !isUserMenuOpen.value; };

const handleRefresh = () => {
  if (isRefreshing.value) return;
  isRefreshing.value = true;
  setTimeout(() => { isRefreshing.value = false; }, 1200);
};

const handleLogout = () => {
  if (confirm('هل أنت متأكد من تسجيل الخروج؟')) {
    console.log('Logging out...');
  }
};

/* ─── Close dropdown on outside click ─── */
const handleOutsideClick = (e) => {
  if (userMenuRef.value && !userMenuRef.value.contains(e.target)) {
    isUserMenuOpen.value = false;
  }
};

onMounted(()  => document.addEventListener('mousedown', handleOutsideClick));
onUnmounted(() => document.removeEventListener('mousedown', handleOutsideClick));
</script>

<style scoped>

/* ════════════════════════════════════════════════
   HEADER INNER LAYOUT
   (the <header> shell + sticky/border live in App.vue)
════════════════════════════════════════════════ */
.header-inner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  gap: 1rem;
  min-width: 0;
}

/* ────────────────────────────────────────────────
   START  (right in RTL)
──────────────────────────────────────────────── */
.header-start {
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: .2rem;
  min-width: 0;
}

.page-title {
  font-family: 'IBM Plex Sans Arabic', sans-serif;
  font-weight: 800;
  font-size: 1.0625rem;
  color: var(--c-t1, #EEF2FF);
  line-height: 1.2;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.page-subtitle {
  font-size: .6875rem;
  color: var(--c-t2, #8BA4C8);
  line-height: 1;
  margin-top: .1rem;
}

/* ─── Breadcrumbs ─── */
.breadcrumbs {
  display: flex;
  align-items: center;
  gap: .3rem;
  flex-wrap: wrap;
}

.crumb {
  font-size: .6875rem;
  color: var(--c-t3, #3D5478);
  text-decoration: none;
  transition: color .15s;
  white-space: nowrap;
}
.crumb:hover { color: var(--c-t2, #8BA4C8); }
.crumb--active { color: var(--c-t2, #8BA4C8); font-weight: 500; pointer-events: none; }

.crumb-sep {
  width: .6875rem;
  height: .6875rem;
  color: var(--c-t3, #3D5478);
  flex-shrink: 0;
}

/* ────────────────────────────────────────────────
   END  (left in RTL)
──────────────────────────────────────────────── */
.header-end {
  display: flex;
  align-items: center;
  gap: .5rem;
  flex-shrink: 0;
}

/* ─── Vertical separator ─── */
.header-sep {
  width: 1px;
  height: 1.5rem;
  background: var(--border-dim, rgba(28,58,100,.5));
  flex-shrink: 0;
}

/* ────────────────────────────────────────────────
   SEARCH
──────────────────────────────────────────────── */
.search-wrap {
  position: relative;
  display: none;           /* hidden on small screens */
}

@media (min-width: 768px) {
  .search-wrap { display: flex; align-items: center; }
}

.search-icon {
  position: absolute;
  left: .75rem;
  top: 50%;
  transform: translateY(-50%);
  width: .875rem;
  height: .875rem;
  color: var(--c-t3, #3D5478);
  pointer-events: none;
  transition: color .2s;
  z-index: 1;
}

.search-wrap--focused .search-icon {
  color: var(--c-blue, #4F8EF7);
}

.search-input {
  width: 13rem;
  height: 2.125rem;
  padding: 0 .875rem 0 2.375rem;
  border-radius: .625rem;
  border: 1px solid var(--border-dim, rgba(28,58,100,.5));
  background: rgba(12, 24, 48, .6);
  color: var(--c-t1, #EEF2FF);
  font-size: .8125rem;
  font-family: 'IBM Plex Sans Arabic', sans-serif;
  outline: none;
  transition: border-color .2s, width .3s ease;
}

.search-input::placeholder { color: var(--c-t3, #3D5478); }

.search-wrap--focused .search-input {
  border-color: rgba(79, 142, 247, .55);
  width: 16rem;
}

/* Glow under the input when focused */
.search-glow {
  position: absolute;
  inset: -3px;
  border-radius: .875rem;
  background: radial-gradient(ellipse, rgba(79,142,247,.14), transparent 70%);
  opacity: 0;
  transition: opacity .25s;
  pointer-events: none;
}
.search-wrap--focused .search-glow { opacity: 1; }

/* ────────────────────────────────────────────────
   ICON BUTTONS  (refresh, bell)
──────────────────────────────────────────────── */
.icon-btn {
  position: relative;
  width: 2.125rem;
  height: 2.125rem;
  border-radius: .5625rem;
  border: 1px solid var(--border-dim, rgba(28,58,100,.5));
  background: rgba(15, 29, 56, .45);
  color: var(--c-t2, #8BA4C8);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  flex-shrink: 0;
  transition: all .2s ease;
}

.icon-btn:hover {
  border-color: rgba(79,142,247,.4);
  color: var(--c-t1, #EEF2FF);
  background: rgba(79,142,247,.08);
  box-shadow: 0 0 10px rgba(79,142,247,.15);
}

/* ─── Notification badge ─── */
.notif-btn { overflow: visible; }

.notif-badge {
  position: absolute;
  top: -.35rem;
  right: -.35rem;
  min-width: 1.125rem;
  height: 1.125rem;
  padding: 0 .25rem;
  border-radius: 99px;
  background: var(--c-err, #F04545);
  border: 1.5px solid var(--c-void, #020810);
  color: #fff;
  font-size: .625rem;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  line-height: 1;
  box-shadow: 0 0 8px rgba(240,69,69,.5);
}

.badge-enter-active,
.badge-leave-active { transition: all .2s cubic-bezier(.4,0,.2,1); }
.badge-enter-from,
.badge-leave-to     { transform: scale(0); opacity: 0; }

/* ────────────────────────────────────────────────
   USER BUTTON
──────────────────────────────────────────────── */
.user-menu-wrap { position: relative; }

.user-btn {
  display: flex;
  align-items: center;
  gap: .5rem;
  height: 2.125rem;
  padding: 0 .625rem 0 .5rem;
  border-radius: .625rem;
  border: 1px solid var(--border-dim, rgba(28,58,100,.5));
  background: rgba(15, 29, 56, .45);
  cursor: pointer;
  transition: all .2s ease;
}

.user-btn:hover,
.user-btn--open {
  border-color: rgba(79,142,247,.4);
  background: rgba(79,142,247,.07);
}

/* Avatar */
.user-avatar {
  position: relative;
  width: 1.625rem;
  height: 1.625rem;
  border-radius: .4375rem;
  background: linear-gradient(135deg, #D4A843, rgba(212,168,67,.6));
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  overflow: visible;
}

.avatar-letter {
  font-size: .75rem;
  font-weight: 800;
  color: #000;
  font-family: 'IBM Plex Sans Arabic', sans-serif;
  line-height: 1;
}

.avatar-glow {
  position: absolute;
  inset: -3px;
  border-radius: .6875rem;
  background: radial-gradient(circle, rgba(212,168,67,.3), transparent 70%);
  pointer-events: none;
}

/* Name + role (hidden on small screens) */
.user-info {
  display: none;
  flex-direction: column;
  align-items: flex-start;
  gap: 0;
}

@media (min-width: 640px) {
  .user-info { display: flex; }
}

.user-name {
  font-size: .75rem;
  font-weight: 600;
  color: var(--c-t1, #EEF2FF);
  line-height: 1.1;
  white-space: nowrap;
}

.user-role {
  font-size: .625rem;
  color: var(--c-t3, #3D5478);
  font-family: 'IBM Plex Sans Arabic', sans-serif;
  line-height: 1.1;
}

/* Chevron */
.user-chevron {
  width: .875rem;
  height: .875rem;
  color: var(--c-t3, #3D5478);
  transition: transform .25s ease;
  flex-shrink: 0;
  display: none;
}
@media (min-width: 640px) { .user-chevron { display: block; } }

/* ────────────────────────────────────────────────
   DROPDOWN
──────────────────────────────────────────────── */
.dropdown {
  position: absolute;
  top: calc(100% + .5rem);
  left: 0;
  min-width: 13rem;
  z-index: 200;

  background: var(--c-card, #0A1528);
  border: 1px solid var(--border-bright, rgba(79,142,247,.3));
  border-radius: .875rem;
  overflow: hidden;

  box-shadow:
    0 0 0 1px rgba(79,142,247,.06),
    0 8px 40px rgba(2,8,16,.8),
    0 0 30px rgba(79,142,247,.05);
}

/* Dropdown animation */
.dropdown-enter-active,
.dropdown-leave-active {
  transition: opacity .2s ease, transform .2s cubic-bezier(.4,0,.2,1);
}
.dropdown-enter-from,
.dropdown-leave-to {
  opacity: 0;
  transform: translateY(-6px) scale(.97);
}

/* User info row at top of dropdown */
.dropdown-header {
  display: flex;
  align-items: center;
  gap: .75rem;
  padding: .875rem 1rem;
  background: rgba(79,142,247,.04);
}

.dropdown-avatar {
  width: 2rem;
  height: 2rem;
  border-radius: .5rem;
  background: linear-gradient(135deg, #D4A843, rgba(212,168,67,.6));
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: .875rem;
  font-weight: 800;
  color: #000;
  font-family: 'IBM Plex Sans Arabic', sans-serif;
  flex-shrink: 0;
}

.dropdown-name {
  font-size: .8125rem;
  font-weight: 600;
  color: var(--c-t1, #EEF2FF);
}

.dropdown-email {
  font-size: .6875rem;
  color: var(--c-t3, #3D5478);
  font-family: 'IBM Plex Sans Arabic', sans-serif;
  margin-top: .1rem;
}

/* Divider */
.dropdown-divider {
  height: 1px;
  background: var(--border-dim, rgba(28,58,100,.5));
  margin: 0;
}

/* Items */
.dropdown-item {
  display: flex;
  align-items: center;
  gap: .75rem;
  width: 100%;
  padding: .6875rem 1rem;
  background: transparent;
  border: none;
  color: var(--c-t2, #8BA4C8);
  font-size: .8125rem;
  font-family: 'IBM Plex Sans Arabic', sans-serif;
  cursor: pointer;
  text-align: right;
  transition: all .15s ease;
  text-decoration: none;
}

.dropdown-item:hover {
  background: rgba(79,142,247,.06);
  color: var(--c-t1, #EEF2FF);
}

.dropdown-item--danger { color: var(--c-err, #F04545); }
.dropdown-item--danger:hover {
  background: rgba(240,69,69,.08);
  color: var(--c-err, #F04545);
}
</style>