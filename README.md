<div align="center">

# Wear Me Maanyag

**Intelligent Inventory and Sales Management System with AI-Powered Assistant for Wear Me Maanyag Thrift Store**

A single-store web application for thrift-store inventory, sales, receipts, unpaid payments, reports, and an Owner-only AI assistant.

![Status](https://img.shields.io/badge/status-active%20development-f59e0b)
![Laravel](https://img.shields.io/badge/Laravel-13.x-FF2D20?logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-target-4479A1?logo=mysql&logoColor=white)
![Tailwind CSS](https://img.shields.io/badge/Tailwind_CSS-4.x-06B6D4?logo=tailwindcss&logoColor=white)

</div>

> **Project status:** Active development. The Maanyag Assistant is disabled by default until the Google account and store-data handling are approved. See [AI assistant](#ai-assistant).

## Contents

- [Overview](#overview)
- [Roles and access](#roles-and-access)
- [Features](#features)
- [AI assistant](#ai-assistant)
- [Tech stack](#tech-stack)
- [Architecture](#architecture)
- [Project structure](#project-structure)
- [Getting started](#getting-started)
- [Environment variables](#environment-variables)
- [Testing](#testing)
- [Security and data handling](#security-and-data-handling)
- [Known limitations](#known-limitations)
- [Project documentation](#project-documentation)

## Overview

Wear Me Maanyag is a web-based inventory and sales management system built for a single thrift-store business. It brings products, categories, paid sales, unpaid payments, receipts, reports, cashier accounts, and store performance into one Laravel application.

The system has two roles:

- **Store Owner** — manages the store, reviews reports, handles unpaid records, manages cashier accounts, and uses the AI assistant.
- **Cashier** — records paid walk-in and online sales and prepares receipts.

The application is built with Laravel, Blade, Tailwind CSS, and MySQL. The AI assistant is intentionally Owner-only, read-only, and limited to verified aggregate store data.

## Roles and access

| Capability | Store Owner | Cashier |
| --- | :---: | :---: |
| Dashboard | Yes | — |
| Categories and products | Yes | — |
| Record paid sales (walk-in or online) | Yes | Yes |
| Receipts | Download paid receipts | Generate and download paid receipts |
| Unpaid transactions | Yes | No (current gap) |
| Reports and PDF/Excel exports | Yes | — |
| Cashier account management | Yes | — |
| Maanyag Assistant | Yes | — |
| View/edit own profile | Yes | Yes |

The current unpaid-transaction routes are Owner-only. The SRS and SPMP assign unpaid-item recording to the Cashier, so this remains an open product/document decision. See [Known limitations](#known-limitations).

## Features

### Authentication and accounts

- Email/password login with role-based redirects: Owner to `/dashboard`, Cashier to `/sales`.
- Five failed login attempts trigger a 60-second lockout for that email/IP pair.
- Inactive accounts cannot sign in.
- Forgot-password and reset-password flow; configure mail delivery for real emails.
- Both roles can view and edit their own name, email, and password.

### Inventory

- Categories with create, rename, and active/inactive status.
- Products with code, name, category, description, price, quantity, low-stock threshold, and image.
- Product archive, restore, and permanent delete; permanent delete is blocked when sales or unpaid records reference the product.
- Low-stock detection based on each product's threshold.

### Sales and receipts

- Paid sales only in the sales module: walk-in or online (TikTok, Facebook, Messenger).
- Multi-product line items, stock validation, automatic stock deduction, and transaction numbers.
- Receipt generation for Cashiers and receipt download from paid sales.
- Receipt documents are downloadable HTML files; report exports use PDF and Excel.

### Unpaid payments (Owner)

- Record multi-product unpaid or partially paid transactions with customer details.
- Initial payment must be less than the total; the balance is tracked per payment record.
- Update cumulative payments, settle balances, add products to an existing unpaid transaction, and edit shared customer/item details.
- Unpaid records deduct stock when the transaction is recorded.

### Dashboard and reports

- Owner dashboard with today's sales, a seven-day sales chart, active inventory value, low-stock count, and unpaid balance summary.
- Sales reports with daily, weekly, monthly, and yearly filters and Chart.js visualization.
- Inventory report with active products, low-stock flags, and stock values.
- Sales and inventory exports in PDF and Excel formats.

### Cashier management (Owner)

- Create and edit cashier accounts.
- Activate/deactivate accounts instead of deleting active accounts.
- Soft-delete cashiers only after deactivation.

## AI assistant

The **Maanyag Assistant** is an Owner-only, read-only assistant at `/assistant`. It answers supported questions about verified store totals. It cannot create, edit, delete, archive, settle, or arrange any record.

### Supported questions

| Intent | Example | Data scope |
| --- | --- | --- |
| Sales summary | "Show me sales for this month" | All recorded transactions in the selected period, including paid, unpaid, and partially paid |
| Inventory summary | "Summarize inventory status" | Active inventory counts, units, and value |
| Low stock | "List low-stock products" | Active products at or below their low-stock threshold |
| Best sellers | "List best-selling products" | Paid transactions only, all-time |
| Unpaid summary | "How many unpaid transactions?" | Pending unpaid payment records, aggregate counts and balances only |

Customer-specific questions, write actions, and ambiguous questions are declined before any model call.

### Request flow

```text
Question
  → AssistantQuestionPolicy        normalize, allowlist intent, reject writes/customer-specific questions
  → AssistantContextService        build deterministic aggregates + as_of dates
  → AssistantPromptBuilder         allowlist context fields, require JSON contract
  → GeminiClient                   Interactions API, store: false, optional response schema
  → AssistantResponseValidator     validate JSON, source, date, and numeric grounding
  → AssistantAnswerService         return answered / refused / invalid / unavailable / declined / disabled
  → Blade view                     show answer or a generic status message
```

### Safety controls

- `GEMINI_ALLOW_STORE_DATA=false` by default. When false, supported questions return `disabled` without reading store context or calling Gemini.
- The prompt contains only allowlisted aggregate fields; customer names, contacts, and customer-specific unpaid details are excluded.
- Responses must be a single JSON object with `answer`, `answer_type`, `sources`, `confidence`, and `refusal`.
- Non-refusal answers must cite exactly one app-owned source label and date from `allowed_sources`.
- Every number in the answer must appear in the verified context; formatted values such as `1,234.50` are normalized before comparison.
- Invalid output and transport failures return generic messages. Raw model text is never shown.
- Tests mock Gemini HTTP calls and use synthetic data; no real store data is sent.

### Current AI limitations

- Live Gemini calls are blocked until the Google account and data-handling arrangement are approved.
- AI request logging and embedding/indexing are not wired into the answer flow.
- The local retrieval utility and numeric knowledge-record builder exist but are not yet used by the assistant endpoint.
- Product names remain in low-stock and best-seller context; the product-name privacy review is pending.
- Numeric grounding prevents unsupported figures but does not fully verify semantic labels (for example, calling a balance a percentage).
- Source labels are returned by the backend but are not yet displayed in the UI.
- `as_of` is request-time metadata, not a full refresh/staleness policy.

## Tech stack

| Layer | Technology |
| --- | --- |
| Backend | PHP 8.3+, Laravel 13.x |
| Frontend | Blade, Tailwind CSS 4, Vite 8, Chart.js 4 |
| Database | MySQL (target); in-memory SQLite for tests |
| PDF / Excel | barryvdh/laravel-dompdf, maatwebsite/excel |
| AI | Google Gemini Interactions API |
| Testing | PHPUnit 12, Mockery |
| Formatting | Laravel Pint |

## Architecture

The application follows Laravel's MVC structure with service classes for business logic.

```text
Browser
  │
  ▼
routes/web.php
  │  auth + role middleware
  ▼
Controllers ──► Services ──► Eloquent models ──► MySQL
  │
  └── AssistantController ──► AssistantAnswerService
                                ├── AssistantQuestionPolicy
                                ├── AssistantContextService
                                ├── AssistantPromptBuilder
                                ├── GeminiClient
                                └── AssistantResponseValidator
```

- Controllers validate HTTP input and return Blade views or redirects.
- Service classes handle sales, unpaid payments, reports, receipts, dashboard metrics, and assistant orchestration.
- `RoleMiddleware` enforces `store_owner` and `cashier` access.
- Assistant services keep question policy, context building, prompting, transport, and validation separate.

## Project structure

```text
wearme-maanyag/
├── app/
│   ├── Http/
│   │   ├── Controllers/          # Auth, dashboard, products, sales, reports, assistant, ...
│   │   ├── Middleware/           # RoleMiddleware
│   │   └── Requests/             # AssistantRequest
│   ├── Models/                   # User, Product, Category, SalesTransaction, ...
│   └── Services/                 # Business logic and assistant pipeline
├── database/
│   ├── migrations/               # tbl_* schema
│   └── seeders/                  # UserSeeder (development accounts)
├── resources/
│   ├── css/app.css               # Tailwind entry point
│   ├── js/app.js                 # Chart.js and frontend entry point
│   └── views/                    # Blade pages and layouts
├── routes/web.php                # All web routes and role guards
├── tests/Feature/                # Feature tests, including assistant tests
├── config/services.php           # Gemini configuration
├── phpunit.xml                   # Test environment (SQLite in-memory)
├── .env.example                  # Environment template
└── README.md
```

### Core tables

| Table | Purpose |
| --- | --- |
| `tbl_users` | Store Owner and Cashier accounts, role, status, soft deletes |
| `tbl_categories` | Product categories and active/inactive status |
| `tbl_products` | Inventory, price, quantity, low-stock threshold, image, soft deletes |
| `tbl_sales_transactions` | Sale header, sale type, total, payment status, cashier |
| `tbl_sales_transaction_items` | Product line items for each sale |
| `tbl_unpaid_payments` | Per-product unpaid balances and customer details |
| `tbl_ai_assistant_logs` | AI log schema; logging is not wired yet |
| `tbl_ai_embeddings` | Embedding schema; indexing is not wired yet |

## Getting started

### Requirements

- PHP 8.3 or newer with PDO MySQL, mbstring, and the usual Laravel extensions
- Composer
- Node.js 20.19+ or 22.12+ (required by Vite 8)
- npm
- MySQL

### Installation

1. Install PHP dependencies:

   ```bash
   composer install
   ```

2. Create the environment file:

   ```bash
   cp .env.example .env
   ```

   On Windows PowerShell:

   ```powershell
   Copy-Item .env.example .env
   ```

3. Generate the application key:

   ```bash
   php artisan key:generate
   ```

4. Configure `.env` for MySQL and the store:

   ```dotenv
   APP_NAME="Wear Me Maanyag"
   APP_URL=http://localhost:8000

   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=wearme_maanyag
   DB_USERNAME=your_database_user
   DB_PASSWORD=your_database_password
   ```

   The committed `.env.example` currently ships with SQLite defaults; switch it to MySQL for this project.

5. Create the database schema and seed development accounts:

   ```bash
   php artisan migrate --seed
   ```

   The seeder creates a Store Owner and a Cashier account. Change their credentials before deploying.

6. Create the public storage link for product images:

   ```bash
   php artisan storage:link
   ```

7. Install and build frontend assets:

   ```bash
   npm install
   npm run build
   ```

8. Start the application:

   ```bash
   php artisan serve
   ```

   Open `http://localhost:8000`.

For active development, `composer run dev` starts the Laravel server, queue, and Vite dev processes together.

## Environment variables

| Variable | Purpose | Notes |
| --- | --- | --- |
| `APP_NAME` | Application name | Set to `Wear Me Maanyag` |
| `APP_ENV` | Environment | `local` for development, `production` for deployment |
| `APP_KEY` | Encryption key | Generate with `php artisan key:generate` |
| `APP_URL` | Base URL | Match the local or deployed URL |
| `DB_CONNECTION` | Database driver | Set to `mysql` for this project |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | MySQL connection | Use local values; never commit them |
| `SESSION_DRIVER` | Session storage | Defaults to `database` |
| `CACHE_STORE` | Cache storage | Defaults to `database` |
| `QUEUE_CONNECTION` | Queue driver | Defaults to `database` |
| `MAIL_MAILER` | Mail transport | Defaults to `log`; configure SMTP for real password-reset emails |
| `MAIL_FROM_ADDRESS` | Sender address | Set a valid store address for production |
| `GEMINI_API_KEY` | Gemini API key | Leave blank until live AI use is approved |
| `GEMINI_MODEL` | Text model | Defaults to `gemini-3.5-flash-lite` |
| `GEMINI_EMBEDDING_MODEL` | Embedding model | Defaults to `gemini-embedding-001` |
| `GEMINI_ALLOW_STORE_DATA` | Store-data sharing switch | Keep `false` until the Google account and data-handling arrangement are approved |

Never commit `.env` or real credentials. Use `.env.example` for placeholders only.

## Testing

Run the full suite:

```bash
php artisan test
```

Run the assistant tests only:

```bash
php artisan test --filter=Assistant
```

Format changed PHP files:

```bash
vendor/bin/pint --dirty
```

Notes:

- `phpunit.xml` uses an in-memory SQLite database, so tests do not require MySQL.
- Assistant tests use mocked Gemini HTTP calls, synthetic store data, and `Http::preventStrayRequests()`.
- No real Gemini request is made by the test suite.
- Known issue: the default `tests/Feature/ExampleTest.php` expects `/` to return HTTP 200, but the application redirects `/` to `/login` (302). This is an unrelated stale test and the only current full-suite failure.

## Security and data handling

- Authentication is required for all store pages except login and password reset.
- Role middleware protects Owner-only modules and Cashier-only receipt generation.
- Passwords are hashed; inactive accounts are blocked at login; repeated failed logins are rate-limited.
- CSRF protection is provided by Laravel's web middleware.
- `.env` is ignored by Git; commit only `.env.example` placeholders.
- The assistant never receives customer-identifying details and cannot perform writes.
- `GEMINI_ALLOW_STORE_DATA` must remain `false` until the Owner approves the Google account and data-handling arrangement.

## Known limitations

- **Cashier unpaid workflow:** The SRS and SPMP assign unpaid-item recording/updating to the Cashier, but the current `/unpaid-transactions` routes and sidebar link are Owner-only. This needs a product/document decision.
- **Live AI:** Gemini calls and store-data sharing are disabled by default pending account and data-handling approval.
- **AI observability:** Assistant logging and embedding/indexing are not wired; the `tbl_ai_assistant_logs` and `tbl_ai_embeddings` tables exist but are not used by the answer flow.
- **AI privacy:** Product names remain in low-stock and best-seller context; the product-name privacy review is pending.
- **AI verification:** Numeric grounding catches unsupported numbers, but semantic mislabeling is not fully verified.
- **AI UI:** Source labels are not displayed yet, and `as_of` is request-time metadata rather than a full freshness policy.
- **Stale example test:** `tests/Feature/ExampleTest.php` expects `/` to return 200, while the app redirects to `/login` (302).
- **Documentation:** The SRS, SDD, SPMP, and AI plan are being reconciled with the implementation; check them before changing scope.

## Project documentation

The project is guided by:

- **SRS** — functional and non-functional requirements.
- **SDD** — architecture, database design, and module design.
- **SPMP** — process, schedule, and testing expectations.
- **AI Assistant Implementation Plan** — assistant design and safety requirements.

These documents are maintained alongside the project workspace and take precedence over this README when scope or requirements conflict.

---

<sub>Wear Me Maanyag Thrift Store — inventory and sales management with a guarded, Owner-only AI assistant.</sub>
