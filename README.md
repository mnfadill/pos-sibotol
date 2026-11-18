# SiBotol Multi-Outlet Point of Sales (POS)

Standalone, enterprise-grade **Multi-Outlet Point of Sales (POS)** system built with **Laravel 12 (REST API + Sanctum)**, **React 18 + TypeScript + Tailwind CSS v4**, and **PostgreSQL / SQLite** in a **single unified project directory (`pos-sibotol`)**.

---

## Struktur Direktori (Unified Single Repository)

Seluruh kode Backend (Laravel 12) dan Frontend (React TypeScript + Vite) sudah disatukan langsung di dalam folder utama `pos-sibotol/`:

```text
pos-sibotol/
├── app/                        # Laravel 12 Backend (Controllers, Services, Models, Middleware, Events)
├── bootstrap/                  # Bootstrap Laravel 12
├── config/                     # Konfigurasi Laravel & Sanctum
├── database/
│   ├── migrations/             # 9 File Migrasi PostgreSQL/SQLite (Multi-Outlet, RBAC, Outbox, Audit)
│   └── seeders/                # DatabaseSeeder (Outlet, Terminal, User RBAC, Produk, Stok)
├── public/
│   ├── build/                  # Bundle produksi React (otomatis disajikan oleh php artisan serve)
│   └── index.php               # Entrypoint HTTP Laravel
├── resources/views/
│   └── welcome.blade.php       # Host SPA React saat dijalankan via php artisan serve
├── routes/
│   ├── api.php                 # Endpoint REST API (/api/v1/*)
│   └── web.php                 # Fallback SPA Frontend
├── src/                        # Kode Sumber React 18 + TypeScript + Tailwind CSS v4
│   ├── components/
│   │   ├── Auth/               # LoginAndSessionModal.tsx
│   │   ├── Layout/             # CashierHeaderBar.tsx
│   │   ├── Shift/              # ShiftManagementModal.tsx
│   │   ├── Checkout/           # CheckoutScreen.tsx & SplitPaymentModal.tsx
│   │   ├── Orders/             # OrdersManagementPage.tsx
│   │   ├── Inventory/          # InventoryControlPage.tsx
│   │   └── Audit/              # AuditLogHistoryPage.tsx
│   ├── lib/                    # Axios client & formatter IDR
│   └── types/                  # Kontrak TypeScript POS
├── tests/Feature/              # PosSystemEndToEndTest.php (4/4 E2E Test Suites)
├── artisan                     # CLI Laravel (otomatis memuat php.ini lokal)
├── php.ini                     # Konfigurasi ekstensi PHP lokal (PDO, OpenSSL, Mbstring, dll.)
├── package.json                # Script Vite & dependensi React TypeScript
└── vite.config.ts              # Konfigurasi Vite + auto-starter Backend Laravel
```

---

## Cara Menjalankan Aplikasi

Anda dapat menjalankan aplikasi langsung dari folder `pos-sibotol` menggunakan **salah satu** dari dua cara berikut:

### Cara 1: Menggunakan `php artisan serve` (Full-Stack di Port `8000`)
Menjalankan Backend API sekaligus menampilkan antarmuka React POS (dari `public/build`) di satu alamat:
```powershell
php artisan serve
```
Buka di browser: **`http://127.0.0.1:8000`**

### Cara 2: Menggunakan `npm run dev` (Mode Development HMR di Port `5173`)
Menjalankan Vite Dev Server di port `5173` **dan secara otomatis menyalakan Backend Laravel di port `8000`** jika belum aktif:
```powershell
npm run dev
```
Buka di browser: **`http://127.0.0.1:5173`**

> **Catatan Terminal Windows / Laragon**:
> Jika perintah `php` atau `npm` belum terdaftar di *System PATH* Windows Anda, `.vscode/settings.json` di proyek ini sudah dikonfigurasi agar terminal baru di editor otomatis mengenali PHP (`C:\laragon\bin\php\php-8.3.36-Win32-vs16-x64`) dan Node.js (`C:\laragon\bin\nodejs\node-v22`). Cukup buka terminal baru (`Ctrl+Shift+\``) di editor.

---

## Akun Demo (Hasil Seed Otomatis)

Semua akun menggunakan password **`password123`**:

| Role | Username | Email | PIN Supervisor | Akses Outlet |
| :--- | :--- | :--- | :--- | :--- |
| **Super Admin** | `admin` | `admin@sibotol.id` | `999999` | Semua Outlet (`JKT-SNP`, `BDG-DGO`) |
| **Outlet Manager** | `manager.jkt` | `manager.jkt@sibotol.id` | `123456` | Flagship Senopati (`JKT-SNP`) |
| **Supervisor** | `spv.jkt` | `spv.jkt@sibotol.id` | `123456` | Flagship Senopati (`JKT-SNP`) |
| **Cashier Jakarta** | `kasir.jkt` | `kasir.jkt@sibotol.id` | — | Flagship Senopati (`JKT-SNP`) |
| **Cashier Bandung** | `kasir.bdg` | `kasir.bdg@sibotol.id` | — | Bandung Dago (`BDG-DGO`) |
