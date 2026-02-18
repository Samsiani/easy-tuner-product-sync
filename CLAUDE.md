# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**EasyTuner Sync Pro** is a WordPress plugin (PHP 7.4+, WooCommerce 5.0+) that synchronises products from the EasyTuner remote API (`https://easytuner.net:8090`) into WooCommerce. It uses Action Scheduler for background batch processing. No Composer — all PHP is hand-rolled with an SPL autoloader.

## No Build Steps

There are no build steps, no package managers, no transpilation. Edit PHP/CSS/JS files directly. The plugin is ready to use as-is.

## Architecture

### Entry Point

`easytuner-sync.php` — the main plugin file. It:
1. Defines constants (`ET_SYNC_VERSION`, `ET_SYNC_PLUGIN_DIR`, etc.)
2. Registers the SPL autoloader for the `AutoSync\` namespace
3. Declares `use AutoSync\*` statements
4. Defines the `EasyTuner_Sync_Pro` singleton orchestrator class
5. Defines the `EasyTunerPlugin()` global helper function
6. Bootstraps the plugin with `EasyTunerPlugin()`

### SPL Autoloader

Registered in `easytuner-sync.php`, maps the `AutoSync\` namespace prefix to the `src/` directory:

| Class | File |
|---|---|
| `AutoSync\Admin` | `src/Admin.php` |
| `AutoSync\API` | `src/API.php` |
| `AutoSync\Image` | `src/Image.php` |
| `AutoSync\Logger` | `src/Logger.php` |
| `AutoSync\Scheduler` | `src/Scheduler.php` |
| `AutoSync\Sync` | `src/Sync.php` |

To add a new class: create `src/ClassName.php` with `namespace AutoSync;` — no `require_once` needed.

### File Structure

```
easy-tuner-product-sync/
├── easytuner-sync.php   ← entry point, autoloader, singleton orchestrator
├── src/
│   ├── Admin.php        ← admin menu, settings page, AJAX handlers
│   ├── API.php          ← HTTP calls to EasyTuner remote API
│   ├── Image.php        ← image download and WooCommerce attachment
│   ├── Logger.php       ← sync log table reads/writes
│   ├── Scheduler.php    ← Action Scheduler integration
│   └── Sync.php         ← product sync logic (create/update WC products)
├── assets/
│   ├── css/admin.css
│   └── js/admin.js
├── uninstall.php        ← cleanup on plugin deletion
└── CLAUDE.md
```

### Main Orchestrator

`EasyTuner_Sync_Pro` in `easytuner-sync.php` is a singleton. It keeps public properties for each subsystem:

```php
EasyTunerPlugin()->api        // AutoSync\API instance
EasyTunerPlugin()->image      // AutoSync\Image instance
EasyTunerPlugin()->logger     // AutoSync\Logger instance
EasyTunerPlugin()->scheduler  // AutoSync\Scheduler instance
EasyTunerPlugin()->sync       // AutoSync\Sync instance
EasyTunerPlugin()->admin      // AutoSync\Admin instance (null when is_admin() is false)
```

### Global Helper Function

`EasyTunerPlugin()` returns the `EasyTuner_Sync_Pro` singleton. It is used throughout all subsystem classes for cross-class communication. Example:

```php
// Inside src/Scheduler.php:
$sync = EasyTunerPlugin()->sync;
$sync->run_full_sync( 'scheduled' );
```

**Note:** `$admin` is only initialised inside `if ( is_admin() )`. Access from cron/background contexts will return `null`. Always guard: `if ( null !== EasyTunerPlugin()->admin )`.

### Class Responsibilities

| Class | Responsibility | Key Constants |
|---|---|---|
| `Admin` | Admin menu (`admin_menu`), settings page render, 8 AJAX handlers | `MENU_SLUG = 'easytuner-sync-pro'` |
| `API` | Bearer token auth, HTTP GET/POST to EasyTuner API, product/category fetch | — |
| `Image` | Image deduplication (by source URL meta), download, WP media import | `SOURCE_URL_META_KEY = '_et_source_url'` |
| `Logger` | DB log table CRUD, counters for created/updated/errors | — |
| `Scheduler` | Action Scheduler hook registration, batch scheduling, daily sync | `SCHEDULED_SYNC_HOOK = 'et_sync_scheduled_task'`, `BATCH_PROCESS_HOOK = 'et_sync_batch_process'` |
| `Sync` | WooCommerce product create/update (Sync Locking), 4 AJAX handlers | — |

## WordPress Hooks Inventory

### Admin Hooks (registered in `Admin::__construct`)
| Hook | Handler |
|---|---|
| `admin_menu` | `Admin::add_admin_menu` |
| `admin_enqueue_scripts` | `Admin::enqueue_admin_assets` |

### AJAX Hooks — Admin class (registered in `Admin::__construct`)
| AJAX action | Handler |
|---|---|
| `wp_ajax_et_test_connection` | `Admin::ajax_test_connection` |
| `wp_ajax_et_fetch_categories` | `Admin::ajax_fetch_categories` |
| `wp_ajax_et_save_mapping` | `Admin::ajax_save_mapping` |
| `wp_ajax_et_save_settings` | `Admin::ajax_save_settings` |
| `wp_ajax_et_delete_log` | `Admin::ajax_delete_log` |
| `wp_ajax_et_clear_all_logs` | `Admin::ajax_clear_all_logs` |

### AJAX Hooks — Sync class (registered in `Sync::__construct`)
| AJAX action | Handler |
|---|---|
| `wp_ajax_et_sync_start` | `Sync::ajax_start_sync` |
| `wp_ajax_et_sync_process_batch` | `Sync::ajax_process_batch` |
| `wp_ajax_et_sync_get_status` | `Sync::ajax_get_sync_status` |
| `wp_ajax_et_sync_log_error` | `Sync::ajax_log_error` |

### System Hooks (registered in `EasyTuner_Sync_Pro::init_hooks`)
| Hook | Handler |
|---|---|
| `plugins_loaded` | `EasyTuner_Sync_Pro::init` |
| `https_ssl_verify` | `EasyTuner_Sync_Pro::bypass_ssl_for_easytuner` |
| `https_local_ssl_verify` | `EasyTuner_Sync_Pro::bypass_ssl_for_easytuner` |

### Action Scheduler Hooks (registered in `Scheduler::__construct`)
| Hook string | Handler | Constant |
|---|---|---|
| `et_sync_scheduled_task` | `Scheduler::run_scheduled_sync` | `Scheduler::SCHEDULED_SYNC_HOOK` |
| `et_sync_batch_process` | `Scheduler::process_batch` | `Scheduler::BATCH_PROCESS_HOOK` |

**Important:** Hook strings are frozen — they are stored in the WordPress database by Action Scheduler. Never rename them without a migration.

## AJAX Nonce

All AJAX requests use nonce key `'et_sync_nonce'`. The nonce is created in `Admin::enqueue_admin_assets` and passed to JS via `etSyncAdmin.nonce`. All AJAX handlers verify it with `check_ajax_referer( 'et_sync_nonce', 'nonce' )`.

## WordPress Options (`wp_options`)

| Option key | Type | Default | Description |
|---|---|---|---|
| `et_api_email` | string | `''` | EasyTuner API username/email |
| `et_api_password` | string | `''` | EasyTuner API password |
| `et_category_mapping` | array | `[]` | API category → WC category mapping config |
| `et_sync_batch_size` | int | `20` | Products per batch (1–100) |
| `et_auto_sync` | int | `0` | 1 = daily auto-sync enabled |

## Transients

| Transient key | TTL | Description |
|---|---|---|
| `et_sync_running` | 1 hour | Set to `true` while sync is active |
| `et_sync_progress` | 1 hour | Array: `{created, updated, errors, total}` |
| `et_sync_bg_{uuid}` | 1 hour | Full product list for background sync sessions |
| `et_sync_{uuid}` | 1 hour | Full product list for manual (AJAX) sync sessions |

## Database Table: `wp_et_sync_logs`

| Column | Type | Description |
|---|---|---|
| `id` | `bigint(20) unsigned AUTO_INCREMENT` | Primary key |
| `sync_date` | `datetime DEFAULT CURRENT_TIMESTAMP` | When the sync started |
| `sync_type` | `varchar(50)` | `manual`, `scheduled`, `background` |
| `products_updated` | `int(11)` | Count of updated products |
| `products_created` | `int(11)` | Count of created products |
| `errors_count` | `int(11)` | Count of errors |
| `error_details` | `longtext` | JSON-encoded array of error objects |
| `status` | `varchar(20)` | `in_progress`, `completed`, `partial`, `failed`, `cancelled` |

Indexes: `sync_date`, `status`

## Sync Locking

New products are created as **Draft** status with full data (name, price, stock, category, image). Existing products (matched by SKU via `wc_get_product_id_by_sku`) only update **price** and **stock** — name, status, category, and images are intentionally preserved. This prevents merchant edits from being overwritten.

## WooCommerce Class References in Namespaced Files

Inside any `src/` file, WC and WP classes must be globally qualified because PHP resolves names relative to `namespace AutoSync`. Use `\` prefix:

```php
// Correct inside src/Sync.php:
$product = new \WC_Product_Simple();
```

WordPress functions (`wp_remote_get`, `add_action`, `get_option`, etc.) are never namespaced and require no prefix change.

## PHP Version Constraint

Minimum PHP 7.4. Typed properties are supported but `$admin` is declared as untyped `public $admin` because it is only initialised in admin context. Accessing an uninitialised typed property throws `TypeError` in PHP 7.4+.

## Adding a New Subsystem Class

1. Create `src/ClassName.php` with `namespace AutoSync;` header
2. Add `use AutoSync\ClassName;` to the use block in `easytuner-sync.php`
3. Add a `@var ClassName` property to `EasyTuner_Sync_Pro`
4. Instantiate it in `EasyTuner_Sync_Pro::init()`
5. No `require_once` needed — autoloader handles it automatically
