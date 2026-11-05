# Trader Man Compliance Management — Project Rules

## Stack

- **Backend**: Laravel 11 (PHP)
- **Frontend**: Vue 3 (Composition API, `<script setup>`) loaded via Inertia.js
- **CSS**: Bootstrap 5.3.3 (CDN) + Bootstrap Icons 1.11.3 (CDN)
- **Fonts**: IBM Plex Sans + IBM Plex Mono (Google Fonts)
- **JS bundler**: Vite (JS only — no CSS pipeline through Vite)
- **SCSS compiler**: `./node_modules/.bin/sass` (sass-embedded devDependency)

---

## Styles

- Always write **SCSS source files**; compile output CSS to the **same folder** (`public/assets/css/`)
- Compile command: `./node_modules/.bin/sass --no-source-map <input.scss> <output.css>`
- Three SCSS files — never mix their responsibilities:
  - `styles.scss` → all design tokens, colors, global resets, utility classes
  - `sidebar.scss` → sidebar, nav, user menu, mobile toggle
  - `custom.scss` → layout shell, topbar, tables, aside/drawer, form fields, page-specific utilities
- Component-level `<style>` blocks are allowed only for highly specific one-off styles that don't belong in any shared file.

---

## Vue File Organisation

```
resources/js/
  Pages/          ← Inertia page components (one per route)
  Includes/       ← Sub-components used inside Pages (partials, forms, drawers)
    Companies/
    ...
  Components/
    Common/       ← Globally registered shared components (PageHeader, Aside, Content, etc.)
    Form/         ← Globally registered form primitives (FormInput, FormSelect, FormButton, etc.)
    NewComponents/
  Service/
    Utils/
      DBService.js  ← Axios wrapper; always use this for API calls
```

- Direct page components go in `Pages/`, named by feature: `Pages/Companies/Index.vue`
- All sub-components (forms, filter panels, etc.) go in `Includes/<Feature>/`
- Never put feature sub-components in `Components/` — that folder is for globally shared primitives only

---

## Inertia Usage

- Inertia is used **only to load Vue page components**. No Inertia links, forms, or router features.
- All data fetching and mutations go through the API via `DBService`.
- Page props (passed via `Inertia::render`) are used only for **initial static data** (lookups, default filters) — dynamic data is fetched by Vue on mount.

---

## API & Controllers

- Controllers authenticate via: `User::AuthenticateUser($request->header('apiToken'))`
- `DBService` (axios) sends `apiToken` header automatically on every request.
- Controller responses always return: `Response::json(['success' => true|false, 'message' => '...', ...data])`
- API routes go in `routes/api.php` under a feature prefix: `Route::prefix('/company')->group(...)`
- Web (Inertia page) routes go in `routes/web.php` under `Route::middleware('auth')`

---

## Forms

- Always use **vee-validate** for form validation: `useForm`, `handleSubmit`, `resetForm`
- Use global form components — never raw `<input>` or `<select>`:
  - `<FormInput>` — text, number, email, date, password inputs; `validate="email|mobile|url"` for built-in rules
  - `<FormSelect>` — dropdowns; use `opt_id` / `opt_name` props to map option arrays
  - `<FormButton>` — submit button; use `form="formId"` to connect to a `<form>` tag
- Required fields: `:req="true"` — shows asterisk and runs `yup.string().required()`
- Optional fields: omit `:req` — vee-validate skips them, controller saves `null`
- Reference pattern: `Sample/js_scouting/Admin/Player/PlayerAddUpdateModal.vue`

---

## Aside / Drawer

- Use the globally registered `<Aside>` component for all right-side drawers (add, edit, filter panels)
- Props: `:show` (Boolean), `title`, `subtitle`, `size` (`''` | `'sm'` | `'lg'` | `'xl'`)
- Emits: `close`
- Slots: default (body content), `#footer` (sticky action buttons)
- Uses `<teleport to="body">` internally — no z-index wiring needed in the page

---

## Database Conventions

- All schema changes must be documented in **`database_migration.php`** (project root) in **raw SQL DDL format** — matching the format in `Sample/database_migrations.php`
- Migration PHP files still go in `database/migrations/` and are run via `php artisan migrate`
- `database_migration.php` is the human-readable SQL reference alongside the migration folder
- Lookup/reference tables (sectors, market_caps, etc.) are seeded inside their migration — no separate seeders unless the dataset is large

---

## Naming & Misc

- Status chips: `<span class="status-chip chip-green|chip-amber|chip-red|chip-neutral">`
- Market cap badges: `<span class="cap-badge cap-large|cap-mid|cap-small|cap-micro">`
- Monospaced values (ISIN, CIN, codes): add class `mono`
- Sidebar active state: set via `withViewData(['sidebar' => 'key'])` in the controller; matched in `menus/admin.blade.php`
- Nav groups: **Overview** → Dashboard | **Entities** → Companies, Directors | **Filings** → Uploads, XBRL Files
