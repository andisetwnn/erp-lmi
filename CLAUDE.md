# ERP-LMI — Konteks Project

Sistem ERP untuk developer properti **PT Langit Membangun Indonesia (LMI)**.
Mencakup alur penjualan KPR (DBOS → SPR → SP3K → Akad) dan finance/accounting
(approval → pembayaran → jurnal → buku besar → laporan). Saat ini fokus tahap
**data master**. Single company (hanya LMI).

## Tech Stack (sudah final — jangan diganti)

- Laravel 13 (PHP 8.4+)
- Livewire + Alpine.js (Flux UI dari starter kit)
- Tailwind CSS
- **MySQL 8** (engine InnoDB, charset utf8mb4)
- Spatie Laravel Permission (role & permission)
- DomPDF (cetak SPR/invoice), Laravel Excel (export, jalankan via queue)
- Auth: Fortify (bawaan starter kit Livewire). Registrasi publik DIMATIKAN —
  user dibuat oleh admin / seeder.

## Aturan desain WAJIB

- **Semua kolom uang pakai `DECIMAL(15,2)`** — JANGAN pernah pakai FLOAT/DOUBLE.
  (harga rumah, UTJ, UM, biaya notaris, BPHTB, PPh, saldo, dll)
- Nama tabel: bahasa Indonesia, singular/sesuai entitas (perusahaan, proyek,
  type_rumah, rumah, customer, sales, notaris, coa, virtual_account).
- Foreign key pakai `constrained()` dengan cascade/null sesuai relasi.
- Setiap model: definisikan `$fillable`, relasi Eloquent, dan `$casts` untuk
  kolom decimal & boolean.
- COA dibuat hierarkis (`parent_id` self-reference) untuk laporan bertingkat.

## TUGAS SAAT INI: Data Master

Buat migration + model + seeder untuk 9 tabel master berikut.

### 1. perusahaan (data LMI untuk kop/invoice/setting)
nama, npwp (nullable), alamat (nullable), kota (nullable), telepon (nullable),
email (nullable), logo (nullable, path), direktur (nullable).

### 2. proyek (perumahan/cluster)
kode (unique), nama, alamat (nullable), kota (nullable),
status enum[aktif,nonaktif] default aktif, keterangan (nullable).
Relasi: hasMany typeRumah, hasMany rumah.

### 3. type_rumah (satu proyek punya banyak type: Type 36/45/60 dst)
proyek_id (FK cascade), nama, luas_bangunan (nullable int, m2),
luas_tanah (nullable int, m2), harga_standar DECIMAL(15,2) default 0,
spesifikasi (nullable text).
Relasi: belongsTo proyek, hasMany rumah.

### 4. rumah (unit fisik = blok + unit)
proyek_id (FK cascade), type_rumah_id (FK cascade), blok, unit,
harga_jual DECIMAL(15,2) default 0,
status enum[tersedia,booking,terjual] default tersedia, keterangan (nullable).
Unique composite: (proyek_id, blok, unit).
Relasi: belongsTo proyek, belongsTo typeRumah.
Tambahkan accessor kode_unit -> "{blok}-{unit}".

### 5. customer (data customer dari DBOS: KTP, nama, HP)
nama, nik (nullable, nomor KTP), npwp (nullable), hp (nullable),
email (nullable), alamat (nullable), kota (nullable), pekerjaan (nullable),
file_ktp (nullable, path upload).

### 6. sales (sales lapangan)
user_id (nullable FK ke users, nullOnDelete — sales yg punya akun login),
kode (unique), nama, hp (nullable), email (nullable),
status enum[aktif,nonaktif] default aktif.
Relasi: belongsTo user.

### 7. notaris (+ setting biaya notaris)
nama, hp (nullable), email (nullable), alamat (nullable),
biaya_jasa DECIMAL(15,2) default 0 (untuk perhitungan otomatis nanti),
keterangan (nullable), status enum[aktif,nonaktif] default aktif.

### 8. coa (Chart of Accounts — hierarkis)
kode (unique), nama, tipe enum[aset,kewajiban,modal,pendapatan,beban],
saldo_normal enum[debit,kredit],
parent_id (nullable self FK, nullOnDelete),
is_header boolean default false (akun induk, tidak untuk transaksi),
is_aktif boolean default true.
Relasi: belongsTo parent (Coa), hasMany children (Coa).

### 9. virtual_account (VA)
bank, nomor_va (unique), nama_va (nullable),
coa_id (nullable FK ke coa, nullOnDelete — VA mengalir ke akun kas/bank),
status enum[aktif,nonaktif] default aktif, keterangan (nullable).
Relasi: belongsTo coa.

## Seeder yang diperlukan

1. **RolePermissionSeeder** — Spatie. Role sesuai aktor diagram:
   super-admin (semua), sales-lapangan, sales-admin, admin-kpr, fat, finance, direktur.
   Permission per modul (master.kelola, customer.kelola, dbos.kelola, spr.kelola,
   spr.cetak, sp3k.kelola, akad.kelola, pembayaran.kelola, jurnal.kelola,
   laporan.lihat, approval.proses).
2. **CoaSeeder** — struktur COA dasar properti (Aset: Kas/Bank/Piutang/Persediaan
   Rumah; Kewajiban: Titipan Customer UTJ-UM, Hutang Pajak; Modal; Pendapatan
   Penjualan Rumah; Beban: Notaris/Operasional/BBM). Buat hierarkis.
3. **PerusahaanAdminSeeder** — data LMI + user admin pertama
   (email admin@lmi.test, password "password") di-assign role super-admin.
4. Update **DatabaseSeeder** memanggil ketiganya berurutan:
   RolePermission → Coa → PerusahaanAdmin.

## Jangan lupa
- Tambahkan trait `Spatie\Permission\Traits\HasRoles` ke `app/Models/User.php`.
- Pastikan Spatie sudah terinstall & migration-nya dipublish sebelum migrate.

## Setelah master selesai (JANGAN dikerjakan sekarang)
Modul transaksi: DBOS → SPR → SP3K → Akad → finance (pembayaran, jurnal,
buku besar, neraca, laba rugi). Akan dibrief terpisah.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- livewire/flux (FLUXUI_FREE) - v2
- livewire/livewire (LIVEWIRE) - v4
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== livewire/core rules ===

# Livewire

- Livewire allow to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

</laravel-boost-guidelines>
