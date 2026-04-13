# Vue Frontend Module

Server-driven frontend architecture for multi-tenant Laravel SaaS, built with Inertia v2 + Vue 3.

## Stack

- Laravel 12 + Inertia v2 (server adapter)
- Vue 3 + @inertiajs/vue3 v2 (client adapter)
- Vite (bundler, code splitting)
- vue-i18n v11 (translations)
- Ziggy v2 (named routes in Vue)
- Custom SCSS v4 (styling)
- SweetAlert2 (confirmation dialogs)

## Architecture

```
HTTP Request
  |
  v
HandleInertiaRequests middleware
  |── visitor_status: admin | auth | authBlocked | authDeleted | guest
  |── layout: { layoutData, authUserData } (lazy-loaded closure)
  |── flash, translations, ziggy, locale, ui_settings
  |
  v
app.js - createInertiaApp()
  |── import.meta.glob('./pages/**/*.vue')  → code-split pages
  |── page.layout = page.layout || LayoutSwitcher
  |── i18n, ZiggyVue, Head, Link plugins
  |
  v
LayoutSwitcher.vue
  |── <component :is="..."> based on visitor_status
  |
  v
Layout Component (AuthLayout / AdminLayout / GuestLayout / etc.)
  |── header, pullout menus, overlays, modals
  |── <slot /> → page content
```

## Layout System

### LayoutSwitcher

Dynamic layout selection based on server prop:

```vue
<component :is="
    visitorStatus === 'admin' ? AdminLayout
    : visitorStatus === 'auth' ? AuthLayout
    : visitorStatus === 'authBlocked' ? AuthBlockedLayout
    : visitorStatus === 'authDeleted' ? AuthDeletedLayout
    : GuestLayout
">
    <slot />
</component>
```

Assigned as default layout in page resolution:

```javascript
page.layout = page.layout || LayoutSwitcher;
```

### Available Layouts

| Layout | User Type | Components |
|--------|-----------|------------|
| GuestLayout | Unauthenticated | Landing page, login/register/reset modals, language selector |
| AuthLayout | Authenticated | Full app header, 7 pullout menus, notification bell, modal orchestration |
| AdminLayout | Admin | Admin header, admin filters, admin right menu |
| AuthBlockedLayout | Blocked | Limited navigation, support access |
| AuthDeletedLayout | Deleted | Minimal UI for data requests |

## Overlay System

### Pullout Panels (AuthLayout)

7 independently managed pullout panels:
1. Mobile menu (left slide-out)
2. Right profile menu
3. Notifications panel
4. Language selector
5. Company switcher
6. Contragent filter drawer
7. Product filter drawer

### State Management

```javascript
const viewPulloutMobilemenu = ref(false);
const viewPulloutMenuRight = ref(false);
const viewOverlay = ref(false);
const viewDoubleOverlay = ref(false);
```

No Vuex/Pinia. Reactive refs manage all UI state.

### Overlay Stacking

- Single overlay: one pullout panel open
- Double overlay: second panel on top of first
- ESC key: dismisses top layer first
- Backdrop click: closes all layers
- Body scroll lock: `document.body.classList.add('non-overflow')`

### Child Communication

```javascript
// Layout provides trigger functions
provide('showPulloutContragentFilter', showFilter);

// Page injects and calls
const showFilter = inject('showPulloutContragentFilter');
```

## Pagination

### Backend: HasPagination Trait

```php
trait HasPagination
{
    protected function getPaginationData($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
            'from'         => $paginator->firstItem(),
            'to'           => $paginator->lastItem(),
        ];
    }
}
```

### Frontend: PagePaginator

- Auto-rendered by AppMainContentLayout when pagination data exists
- Smart page range: max 7 buttons + ellipsis
- Preserves all query params (filters, sorting) across page navigation
- Uses Inertia `<Link>` for SPA navigation

### Filter Auto-Discovery

```php
abstract class Filter
{
    public function apply(Builder $builder)
    {
        foreach ($this->request->all() as $name => $value) {
            if (method_exists($this, $name)) {
                call_user_func_array([$this, $name], [$value]);
            }
        }
        return $this->builder;
    }
}
```

Features:
- Method name = request parameter name
- Word-by-word search matching
- Whitelisted sort columns
- Alphabetical starts_with() filter
- Quick filter presets
- Validated via Form Requests

## Modal System

### Types

| Type | Implementation | Use Case |
|------|----------------|----------|
| Form modal | Custom Vue + Inertia `useForm()` | Invitations, role management |
| Data modal | Custom Vue + axios + tabs | Contragent view, email preview |
| Confirmation | SweetAlert2 | Delete, restore, force logout |
| Generic | Layout-level `AppModalLayout` | Simple confirm/cancel |

### Form Modal Pattern

```javascript
const form = useForm({ email: '', role_id: '' });

form.post(route('endpoint'), {
    preserveScroll: true,
    onSuccess: () => emit('close'),
});
```

- `form.processing` - disables buttons
- `form.errors` - shows validation errors
- `onSuccess` - closes modal

### Async Data Modal Pattern

```javascript
watch(() => props.visible, (newVal) => {
    if (newVal) fetchData();
});
```

- Fetches data only when visible
- Loading/error/content states
- Tab switching within modal
- ESC key handler

### Layout Orchestration

```javascript
const modal = reactive({ view: false, title: '', content: null, targetUrl: null });
provide('showViewContragentModal', showModal);
```

## Inertia Wiring

### Entry Point (app.js)

- `createInertiaApp()` with async page resolution
- `import.meta.glob('./pages/**/*.vue')` for code splitting
- Vue i18n v11 with translations from Inertia shared props
- ZiggyVue for `route()` in templates
- Global `Head` and `Link` components
- Global `t()` translation function
- Progress bar (blue, delay: 0, no spinner)

### Shared Props (HandleInertiaRequests)

All wrapped in closures for lazy evaluation:
- `layout` - LayoutController output
- `flash` - session messages
- `ziggy` - route collection
- `locale` - current locale
- `translations` - merged JSON + PHP translations
- `visitor_status` - user type string
- `ui_settings` - user preferences
- `impersonating` - admin impersonation state

### Vite Configuration

```javascript
alias: {
    '@': 'resources/js',
    '@css': 'resources/css',
    '@assets': 'public/assets',
    'ziggy-js': 'vendor/tightenco/ziggy',
}
```

## File Structure

```
resources/js/
├── app.js
├── layouts/
│   ├── LayoutSwitcher.vue
│   ├── AuthLayout.vue
│   ├── AdminLayout.vue
│   ├── GuestLayout.vue
│   ├── AuthBlockedLayout.vue
│   ├── AuthDeletedLayout.vue
│   └── app/
│       ├── AppHeaderDesktopLayout.vue
│       ├── AppMainContentLayout.vue
│       ├── AppModalLayout.vue
│       ├── AppFooterLayout.vue
│       ├── AppPulloutMobilemenuLayout.vue
│       ├── AppPulloutMenuRightLayout.vue
│       ├── AppPulloutMenuNotificationsLayout.vue
│       ├── AppPulloutMenuChangeLanguageLayout.vue
│       ├── AppPulloutMenuChangeCompanyLayout.vue
│       ├── AppPulloutContragentFilterLayout.vue
│       ├── AppPulloutProductFilterLayout.vue
│       ├── AppGuestLoginModalLayout.vue
│       ├── AppGuestRegisterModalLayout.vue
│       └── admin/ (admin-specific layouts)
├── pages/
├── components/
│   ├── PagePaginator.vue
│   ├── contragents/ViewContragentModal.vue
│   ├── SendTestEmailModal.vue
│   ├── EmailPreviewModal.vue
│   └── ui/
├── composables/
└── store/
    └── unreadCountStore.js
```

## Testing

Pest feature tests assert Inertia prop contracts:

```php
// Layout switching
actingAs($user)->get('/dashboard')
    ->assertInertia(fn ($page) => $page->where('visitor_status', 'auth'));

// Pagination
actingAs($user)->get('/warehouse/products?page=2')
    ->assertInertia(fn ($page) =>
        $page->has('pagination', fn ($p) =>
            $p->where('current_page', 2)->where('total', 30)
        )
    );

// Modal data endpoint
actingAs($user)->get(route('contragents.view_data', $uuid))
    ->assertJson(['contragent' => ['name' => $name]]);
```

## Design Principles

1. **Server-driven** - Backend determines layouts, navigation, permissions. Frontend renders.
2. **No client-side auth** - Zero permission checks in Vue. Zero route guards.
3. **No state library** - Inertia manages page state. Refs manage UI state.
4. **Lazy evaluation** - Shared props wrapped in closures. Only computed when accessed.
5. **Convention over configuration** - LayoutSwitcher as default. PagePaginator auto-renders. Filters auto-discover.
