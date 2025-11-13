## Media List Add-on

### Features
- Create and name media lists for the current user or session.
- Add individual products to a chosen media list from product listings.
- Add all products from a category page to a media list (up to a configurable limit, 50 items by default).
- Remember the last selected media list when adding products.
- Avoid duplicate products when adding to a media list, including bulk additions.
- Show a notification with a link to the media list after adding a product.
- View existing media lists on the **My media lists** page.
- Each media list shows the number of products and links to its own page.
- View products of a media list on a dedicated page.
- Remove products from a media list directly from its page.
- Export media lists to XLSX files from the media lists page, placing each product feature in its own column and translating product and feature data into the site's current language.
- Export media lists directly to Google Sheets using configured credentials.
- Manage a single XLSX template from the admin panel for custom export layouts; uploading a new file replaces the previous template and exports use it when available.
- Navigate media lists with breadcrumbs and page titles.
- Rename or remove media lists from the manage page.
- Track when each media list was last updated.
- SEO-friendly URLs for media list pages (`/media-lists` and `/media-lists/{list_id}`).
- Create Planfix tasks for orders directly from the orders list and display the resulting task link inline.
- UT2 top panel block displaying the number of media lists for the current user or session.
- New media lists appear in all selection controls and update the header counter instantly.
- Hide prices for guests or unauthorized user groups.
- Hide configured product filters and feature values from unauthorized visitors.
- Optional extra button in product lists for adding items to a media list.
- Limit media list features to specific user groups.
- Configure price visibility, extra list button, and access groups via the add-on's **Settings** page in the admin panel.
- Provide Google API credentials JSON in the add-on settings to enable Google Sheets export.
- Limit the number of items in a media list via the **Max list items** setting (default 50).
- Configure the Planfix origin used for deep links so orders and customers with Planfix bindings show the task number with a direct link in the admin grids.
- Configure Planfix MCP integration parameters (endpoint, auth token, webhook Basic Auth, default direction, status filters, comment/payment sync, IP allowlist) directly from the add-on settings.
- Accept Planfix webhooks via `dispatch=mwl_xlsx.planfix_changed_status`, apply status mapping to CS-Cart orders, persist incoming payloads, and prevent ping-pong updates.
- Manage Planfix bindings from a dedicated tab in the order details page: create tasks via MCP, bind existing tasks, and review the full metadata/payload history for each link.
- Push order status changes, comments, and payment summaries to Planfix through MCP while storing the latest payload snapshot and timestamp in the binding record.
- Compact price slider labels: Display min/max values in shortened format (1,000 → 1 K / 1 тыс.) with localization support for Russian and English.
- Format large numeric product feature values with localized thousands separators on the storefront.
- Show a tooltip icon next to prices that explains what the amount includes (localized text).
- Redirect first-time visitors to the storefront language that matches their browser preferences (optional).
- Yandex Metrika tracking includes `user_id` for segmentation via `userParams` when available.
- Synchronize Unitheme price filters from a CSV file via CLI cron with insert/update support, float-aware rounding values, and detailed debug logging.
- Automatically handle product variation group conflicts during import: remove old features, auto-remove duplicate products, and fix new feature values that CS-Cart filters during import process.

### Shortcuts
- Press "a" on a product page to open the "Add to media list" dialog.
  - Works only when no input/select/textarea or contenteditable is focused.
  - Requires the page to have a single visible `data-ca-add-to-mwl_xlsx` button.

### Add-on URLs
- `/media-lists` – list user media lists.
- `/media-lists/{list_id}` – view a media list.
- `index.php?dispatch=mwl_xlsx.manage` – list user media lists.
- `index.php?dispatch=mwl_xlsx.view&list_id={list_id}` – view a media list.
- `index.php?dispatch=mwl_xlsx.create_list` – create a media list (POST).
- `index.php?dispatch=mwl_xlsx.add` – add a product to a media list (POST).
- `index.php?dispatch=mwl_xlsx.remove` – remove a product from a media list (POST).
- `index.php?dispatch=mwl_xlsx.rename_list` – rename a media list (POST).
- `index.php?dispatch=mwl_xlsx.delete_list` – remove a media list (POST).
- `index.php?dispatch=mwl_xlsx.planfix_changed_status` – Planfix webhook for status updates (POST).

## Import Behavior

### Image Import Protection

When importing products via CSV with "Detailed image" field:
- If product already has any images (main or additional) → skip import, keep existing images
- If product has no images → import images from CSV

This prevents accidental overwriting of existing product images during updates.

### Product Variation Groups - Auto-update Features

**Problem**: When importing products with product variations, CS-Cart doesn't automatically update the variation group's feature list:
- Adding new features: If a group has features A and B, and you import products with A, B, and C, feature C is ignored
- Removing features: If you remove a feature from products but it remains in the group, you get "exact same combination" errors
- Duplicate combinations: When re-importing products with changed product codes but same feature values, you get "exact same combination" errors

**Solution**: The add-on automatically handles all three scenarios:
1. **Removing features**: Automatically removes features from variation group when they're no longer present in products ✅
2. **Duplicate combinations**: Automatically removes old products with duplicate feature combinations before adding new ones ✅
3. **Adding features**: Two-step process with automatic fix:
   - Features must exist in group BEFORE import (add manually or via SQL)
   - Hook detects new features and marks them in Registry
   - `import_post` hook fixes feature values that were filtered by CS-Cart ✅

**How it works**:
- Hook: `variation_group_add_products_to_group` intercepts products being added to an existing variation group
- Detection: Compares available features on products against the group's current features
- Duplicate removal: **Automatically removes existing products** with duplicate combinations before adding new ones
  - Compares import data (new products) against DB (existing products)
  - Only removes truly existing products (not those being updated)
  - Prevents "exact same combination of feature values" errors
  - Automatically disables removed products (status='D') to prevent orphaned active products
- Feature removal: When features are removed from products, automatically updates the variation group:
  1. Detects removed features by comparing available vs current
  2. Deletes all features from `cscart_product_variation_group_features` for the group
  3. Inserts the updated feature list (excluding removed features)
  4. Reloads the group object with updated features using PHP Reflection
- Feature addition limitation: **Cannot automatically add new features** during import
  - CS-Cart's ProductsHookHandler filters variation features during save
  - New features must be added to the group manually BEFORE import
  - Hook will detect and warn about new features that need manual addition
- Safety: 
  - Collects features from **all new products** being imported (not just first one)
  - Excludes products being updated from duplicate check (compares only new vs truly existing)
  - Displays detailed debug output for troubleshooting
- Additional hook: `variation_group_save_group` provides post-save diagnostics showing all products and their feature combinations

**Example scenarios**:

**Adding features (SEMI-AUTOMATIC):**
1. Group exists: Products with "Days" and "Special Date" features (2 features)
2. **BEFORE import**: Add "Print" feature to variation group via SQL:
```sql
INSERT INTO cscart_product_variation_group_features (group_id, feature_id, purpose)
SELECT g.id, 84, 'group_variation_catalog_item'
FROM cscart_product_variation_groups g
WHERE g.code = 'marketingnews-ru';
```
3. Import products with "Days", "Special Date", and "Print"
4. Hook detects new feature → marks in Registry → `import_post` fixes values ✅
5. Result: All three feature values are saved correctly (No and Yes) ✅

**Why this two-step process:**
- CS-Cart's `ProductsHookHandler` removes variation features from save list during import
- If feature not in group before import → CS-Cart won't save its values
- If feature added to group but not updated → sync copies parent values to children
- Solution: Feature must exist in group BEFORE import, then `import_post` hook fixes values after sync

**Debug output shows the process:**
```
[Hook add_products_to_group]:
⚠ New features detected: 84
→ Will fix feature values in import_post hook
→ Saved to Registry for post-processing

[Hook import_post]:
Found 1 groups with new features
- Fixing product #18997 feature values...
  * Feature #84: will update to variant_id=5585
  → Updated 2 rows in DB
- Verifying: Product #18997: Yes (vid:5585) ✓
```

**Removing features:**
1. Initial state: Group has features A, B, and C
2. Products updated: Only features A and B remain on products (C removed)
3. Import: Group automatically updated to only A and B ✅
4. Result: No "exact same combination" errors, variations work correctly

**Handling duplicate combinations during import:**

The hook successfully handles the common scenario where new products have the same feature combinations as old products:

1. **Import batch duplicates**: Detects if two products in the same import have identical combinations → Warning (CS-Cart will reject)
2. **Against existing products**: Compares new products against existing products in DB → **Automatically removes old duplicates**
3. **Update scenario safety**: Excludes products being updated from duplicate check (only removes truly old products)

**How duplicate removal works:**
- New products are compared against existing products (from DB)
- If match found → old product automatically:
  1. Removed from variation group via `$group->detachProductById()`
  2. Disabled (status set to 'D') to prevent orphaned active products
- Import proceeds successfully with new product
- Disabled product can be deleted later by cleanup cron (`delete_unused_products`)

**Example:**
- Group has: Product #100 (Days=30, Special=No, status=A)
- Import: Product #200 (Days=30, Special=No) - same combination!
- Hook actions:
  1. Detaches Product #100 from variation group ✓
  2. Sets Product #100 status to 'D' (disabled) ✓
- Result: Product #200 added successfully, no "exact same combination" error
- Cleanup: Product #100 can be deleted by `delete_unused_products` cron

**Troubleshooting**:

The hook provides detailed debug output during import. Look for `[MWL_XLSX]` messages in the console or import log:

**Debug messages**:
- `Hook variation_group_add_products_to_group called` - Hook triggered, shows product count and group info
- `Hook skipped: ...` - Hook skipped with reason
- `Products in DB for group #...` - Shows all products currently in the variation group
- `Product IDs collected` - Shows new, existing, and total products being processed
- `Collecting available features from ALL new products...` - Scanning each new product for features
  - `Product #... has N variation features` - Feature count per product
  - `Added feature #...: ...` - New feature found and added to collection
  - `No features from new products, checking existing...` - Fallback to existing products if new have no features
- `Checking feature values of new products...` - Shows features from import data and current DB state
  - `features from import data` - What the import file specifies (correct new values)
  - `features from DB` - Current database state (may be old values, not yet updated)
- `Available features found: N` - Total unique features collected from all products
- `Features comparison` - Shows current vs available features, **features to add AND remove**
- `⚠ New features detected: ...` - New features found
  - `→ Will fix feature values in import_post hook` - Values will be fixed after import
  - `→ Saved to Registry for post-processing` - Marked for import_post processing
- `Checking for duplicate combinations...` - Looking for conflicts with existing and within import batch
  - `New product #... combination: ...` - Feature combination from import data
  - `NO variation_features in import data` - Product will use DB values (update scenario)
  - `Checking existing products (excluding new): ...` - Lists product IDs being checked for duplicates
  - `Existing product #... combination: ...` - Combination from DB (old product)
  - `⚠ Product #... has SAME combination as new product #...` - Duplicate found!
  - `→ Will remove existing product #... from group` - Old product will be removed
  - `Removing N existing products with duplicate combinations...` - Cleanup in progress
  - `Detached product #... from group` - Old product removed from variation group
  - `Disabled product #... (status=D)` - Old product disabled to prevent orphaned products
  - `No duplicate combinations with existing products` - No duplicates with old products
  - `⚠ WARNING: Duplicate in import batch!` - Two products in same import have identical combinations
  - `✓ All new products have unique combinations within import batch` - No duplicates in import
  - `No existing products to check (or all are being updated)` - All products being updated, no old ones to compare
- `No changes in features` - No additions or removals detected, only duplicate check performed
- `Features are up to date, finishing` - No feature list update needed
- `Will update group "..." (ID:...)` - Feature list will be updated
  - `Adding features: ...` - New features being added to group
  - `Removing features: ...` - Old features being removed from group
- `Updating DB: deleting old features...` - Database cleanup started
- `Features inserted to DB successfully` - Feature list update complete
- `Reloading group from DB...` - Group reload started
- `✓ Successfully updated group features via Reflection` - Group object updated successfully
- `Group saved: "..." (ID:...)` - Post-save diagnostics (from `variation_group_save_group` hook)
  - Shows final products and features in group
  - Lists all feature combinations from DB (reflects actual saved state)
  - `⚠ DUPLICATE COMBINATION detected!` - Post-save duplicate warning
- `Hook import_post called` - Post-import processing (from `import_post` hook)
  - `Found N groups with new features` - Groups that need feature value fixes
  - `Processing group #... with new features: ...` - Fixing specific group
  - `Searching for products in import_data...` - Finding products in import data
  - `Found product #... at import_data[N]` - Product found in import
  - `Fixing product #... feature values...` - Applying fixes
  - `Feature #...: will update to variant_id=...` - Specific feature being fixed
  - `→ Updated N rows in DB` - DB update result
  - `Verifying fixed feature values from DB:` - Final verification
  - `Product #...: Yes (vid:5585)` - Confirmed fixed value
- `✗ ERROR: ...` / `✗ EXCEPTION: ...` - Error details with stack trace

**SQL queries for troubleshooting**:
```sql
-- Check group features in DB
SELECT gf.*, pfd.description 
FROM cscart_product_variation_group_features gf
LEFT JOIN cscart_product_features_descriptions pfd 
  ON gf.feature_id = pfd.feature_id AND pfd.lang_code = 'en'
WHERE gf.group_id = <your_group_id>;

-- Check if hook is registered
SELECT hooks FROM cscart_addons 
WHERE addon = 'mwl_xlsx';
-- Look for 'variation_group_add_products_to_group' in the hooks field

-- Check product features
SELECT pfv.*, pfd.description 
FROM cscart_product_features_values pfv
LEFT JOIN cscart_product_features_descriptions pfd 
  ON pfv.feature_id = pfd.feature_id AND pfd.lang_code = 'en'
WHERE pfv.product_id = <your_product_id> 
  AND pfv.lang_code = 'en';
```

**Common issues**:
- No `[MWL_XLSX]` messages → Hook not called, check `hooks` field in `cscart_addons`, reinstall addon if needed
- "Hook skipped: no available features" → Product has no features with correct types (TEXT_SELECTBOX/NUMBER_SELECTBOX) and purpose
- "No changes in features" → Features match, duplicate check still runs, working as expected
- **"doesn't have the required features to become a variation"** → New feature added to products but not in group:
  - Look for `⚠ New features detected:` in import log
  - Check if feature was added to group before import via SQL
  - Look for `Hook import_post called` - should fix feature values
  - If no import_post messages → Registry not saved, add feature to group and re-import
  - **Solution**: Add feature to group via SQL BEFORE import (see example above), then import will auto-fix values
- "exact same combination of feature values" error → Check debug output:
  - Look for `Checking existing products (excluding new):` - should list products to check
  - Look for `⚠ Product #... has SAME combination` - should detect duplicates
  - Look for `Detached product #... from group` - should remove old product
  - Look for `Disabled product #... (status=D)` - should disable old product
  - If duplicate detected but error persists → may be duplicate within import batch (unfixable)
  - Check `⚠ WARNING: Duplicate in import batch!` - indicates two new products have same combination
- Orphaned active products after import → Old products should be automatically disabled when removed from group, check debug for "Disabled product" messages
- Reflection errors → Check PHP version supports ReflectionClass  
- Features have wrong `purpose` → Verify `purpose` in `cscart_product_features` table (must be `group_catalog_item` or `group_variation_catalog_item`)
- Old product not removed despite duplicate → Check that old product ID is in "truly existing" list (not in new_product_ids)

### Price filter sync

* **Entry point**: `php admin.php --dispatch=mwl_xlsx.filters_sync` (CLI/cron only). When called from a browser the controller exits early with a warning.
* **CSV location**: Configure a single absolute path in the add-on setting **Filters CSV path**.
* **Supported columns**: `name`, `name_ru`, `position`, `round_to`, `feature_id`, `display`, and optional Unitheme visibility flags (`abt__ut2_display_mobile`, `abt__ut2_display_tablet`, `abt__ut2_display_desktop`). Files that still use the legacy `filter`/`filter_ru` headers continue to work, but the new schema is preferred.
* **Row limit**: The sync aborts when the CSV contains more than 100 data rows.
* **Data rules**:
  * `feature_id > 0` rows are treated as feature-based filters (`filter_type = 'F'`, `field_type = 'F'`). Empty `feature_id` values fall back to price filters (`filter_type = 'P'`, `field_type = 'P'`, `feature_id = 0`).
  * Shared defaults applied on insert: `company_id = 0`, `categories_path = ''`, `status = 'A'`, `display_count = 10`.
  * Boolean flags (`display` and the Unitheme display columns) are normalized to `Y`/`N` per row.
  * `round_to` supports decimal values (e.g., `0.01`) and preserves precision when stored.
  * Existing filters are matched by `feature_id` (feature filters) or `field_type = 'P'` (price filter). The name-based match remains as a fallback when neither identifier is available. Only varying attributes (`position`, `round_to`, display flags, feature linkage, filter/field types) are updated; stable fields keep their database values.
  * Russian titles are refreshed from `name_ru` for every processed row.
  * Filters missing from the CSV but present in the database remain untouched; the sync never deletes existing entries.
* **Reporting**: The service returns a summary with counts for created/updated/skipped/errors. The controller prints the summary to STDOUT and appends both the summary and the full payload (including debug lines about searches and skipped deletions) to `var/log/mwl_xlsx.log`.
* **Failure handling**: Missing files, unreadable CSVs, header issues, or limit violations are logged and reported to STDOUT without touching the database.

### Publish down stale products

* **Entry point**: `php admin.php --dispatch=mwl_xlsx.publish_down_missing_products_outdated` (intended for cron/CLI).
* **Settings**:
  * **Publish down stale products** toggles the feature.
  * **Publish down limit** caps the number of products disabled per run. Set to `0` to disable the cap.
  * **Publish down period (seconds)** defines how old the `cscart_products.updated_timestamp` may become before the product is disabled (default `3600` seconds = 1 hour).
* **Behaviour**: Each run queries `cscart_products` for entries with `status` in `A/H/P` whose `updated_timestamp` (falling back to `timestamp` when empty) is older than the configured period. The matching products are disabled (via `fn_tools_update_status` when available) until the optional limit is reached. Actions, disabled IDs, limit hits, and errors are printed to STDOUT and recorded in `var/log/mwl_xlsx.log`.

### Publish down missing products from CSV

* **Entry point**: `php admin.php --dispatch=mwl_xlsx.publish_down_missing_products_csv` (intended for cron/CLI).
* **CSV location**: `$project_root/var/files/products.csv`
* **CSV format**: Must contain two columns: `Variation group code` and `Product code`. The CSV should list all products that should remain active within each variation group.
* **Behaviour**: 
  1. Reads the CSV file and builds a lookup of variation group codes and their associated product codes.
  2. For each unique variation group code in the CSV:
     - Queries `cscart_product_variation_groups` to find the group by its `code`.
     - Retrieves all products in that group from `cscart_product_variation_group_products`.
     - Fetches the `product_code` for each product from `cscart_products`.
     - Identifies products whose `product_code` is **not** present in the CSV.
     - Disables those products by setting their status to `D` (Disabled).
  3. Outputs metrics: `groups_in_csv`, `groups_processed`, `products_checked`, `disabled`, `errors`.
  4. All actions, disabled product IDs, and errors are printed to STDOUT and logged to `var/log/mwl_xlsx.log`.
* **Performance**: Optimized for large datasets (100K+ products) using indexed queries and batch processing per variation group.
* **Failure handling**: Missing CSV file, empty CSV, parsing errors, or non-existent variation groups are logged and reported to STDOUT without modifying the database.

### Delete unused products

* **Entry point**: `php admin.php --dispatch=mwl_xlsx.delete_unused_products` (CLI/cron only).
* **Scan**: Collects disabled product IDs (`status = 'D'`) that appear in critical tables (`cscart_mwl_xlsx_list_products`, `cscart_order_details`, `cscart_product_reviews`, `cscart_product_sales`, `cscart_rma_return_products`, `cscart_wishlist_products`, `cscart_user_session_products`, `cscart_product_subscriptions`, and `cscart_discussion_posts` for products). Disabled products that do not occur in any of these tables remain candidates for deletion.
* **Dry run**: Pass `dry_run=Y` (or define `MWL_XLSX_DELETE_UNUSED_PRODUCTS_DRY_RUN` before dispatch) to preview the deletion list without touching the database. Candidates are reported with `[dry-run]` log entries and a dedicated summary.
* **Deletion**: Calls `fn_delete_product($product_id)` for each candidate and removes lingering SEO names. Each deleted, skipped, or failed product ID is printed to STDOUT (`[deleted]`, `[skip]`, `[error]`) and appended to `var/log/mwl_xlsx.log`.
* **Safety**: Before deletion each product is re-checked against the same tables to avoid race conditions. The run finishes with a summary of deleted, skipped, and failed products.

### Planfix integration modes

The add-on exposes three Planfix-facing controller modes. All of them live under the
standard CS-Cart dispatcher and therefore accept requests at
`index.php?dispatch=<controller>.<mode>` (or `admin.php` when executed from the
backend). The table below summarises their purpose.

| Mode | Direction | Purpose |
| ---- | --------- | ------- |
| `mwl_xlsx.planfix_changed_status` | Planfix → CS-Cart | Receives status webhooks, updates the linked order, and records payload metadata. |
| `mwl_xlsx.planfix_create_sell_task` | CS-Cart admin → Planfix MCP | Creates a Planfix sell task for the current order through MCP and stores the binding. |
| `mwl_xlsx.planfix_bind_task` | CS-Cart admin → Planfix MCP | Binds an existing Planfix object to an order without creating a new task. |

#### `dispatch=mwl_xlsx.planfix_changed_status`

* **Auth**: Enforces Basic Auth (login/password from add-on settings) and an optional IP
  allowlist. Requests that fail either check are rejected with `401`/`403`.
* **Method**: `POST` only. Any other HTTP method produces `405 Method Not Allowed`.
* **Body**: Accepts JSON (`Content-Type: application/json`) or traditional form data. The
  handler looks for `planfix_task_id` (preferred), `task_id`, or `id` to resolve the binding.
  Optional field `status_id` populates the metadata and can trigger a status mapping to CS-Cart
  orders.
* **Response**: Returns JSON with `success` and `message` fields. CS-Cart responds with
  `200 OK` when the incoming status is processed, `404` when the Planfix task is not bound,
  or `500` if the order status update fails.

Example request:

```http
POST /index.php?dispatch=mwl_xlsx.planfix_changed_status HTTP/1.1
Host: store.example.com
Authorization: Basic cGxhbmZpeF9ob29rOnNlY3JldF9wYXNz
Content-Type: application/json
X-Forwarded-For: 203.0.113.15

{
  "planfix_task_id": "PF-1288",
  "status_id": "done",
  "changed_at": "2024-05-01T10:45:00+03:00"
}
```

Example response:

```http
HTTP/1.1 200 OK
Content-Type: application/json

{"success":true,"message":"Order status updated"}
```

#### `dispatch=mwl_xlsx.planfix_create_task`

* **Auth**: Requires an authenticated administrator session and CSRF token. Intended for
  usage through the order management UI.
* **Method**: `POST` with `application/x-www-form-urlencoded` parameters.
* **Parameters**:
  * `order_id` (integer, required) – CS-Cart order identifier.
  * `return_url` (string, optional) – URL to redirect back after processing. Defaults to the
    order details page.
* **Behaviour**: Sends the order snapshot to MCP, stores the returned Planfix object binding,
  and records the outbound payload metadata in `cscart_mwl_planfix_links`.
* **Response**: Redirects back to `return_url` and shows a notification about the outcome.

When a CS-Cart administrator clicks **Create Planfix task** on an order, the add-on sends a JSON payload to the MCP endpoint with a full sell-task payload: the task name follows the pattern `Продажа {товар} на pressfinity.com`, includes the fixed agency identity (“Жууу”), contact email (`agency@example.com`), responsible employee (“Имя Сотрудника”), Telegram handle (`agency_telegram`), a line-by-line order description, and direct order metadata (ID, formatted number, admin URL) along with status, total, direction, currency, and optional customer data. The MCP endpoint performs authenticated `planfix_create_sell_task` requests and records the outbound payload metadata in `cscart_mwl_planfix_links`.

Example request (line breaks added for readability):

```http
POST /admin.php?dispatch=mwl_xlsx.planfix_create_task HTTP/1.1
Host: store.example.com
Content-Type: application/x-www-form-urlencoded
Cookie: sid_admin=d338b9b1d1d2e64f6c0a; admin_csrf_token=0792f...
X-CSRF-Token: 0792f...

order_id=10571&return_url=orders.details%3Forder_id%3D10571
```

Example response:

```http
HTTP/1.1 302 Found
Location: /admin.php?dispatch=orders.details&order_id=10571
Set-Cookie: notices[success]=Planfix+task+PF-1288+created
```

#### `dispatch=mwl_xlsx.planfix_bind_task`

* **Auth**: Same as `planfix_create_task` – requires an authenticated administrator session
  and CSRF token.
* **Method**: `POST` with `application/x-www-form-urlencoded` parameters.
* **Parameters**:
  * `order_id` (integer, required) – CS-Cart order identifier.
  * `planfix_task_id` (string, required) – Identifier of the existing Planfix entity.
  * `planfix_object_type` (string, optional) – Planfix entity type (defaults to `task`).
  * `return_url` (string, optional) – URL to redirect back after processing.
* **Behaviour**: Validates that the Planfix object is not bound elsewhere, registers the
  binding for the order, and optionally seeds metadata.
* **Response**: Redirects back to `return_url` (or the order page) with a success/error
  notification.

Example request:

```http
POST /admin.php?dispatch=mwl_xlsx.planfix_bind_task HTTP/1.1
Host: store.example.com
Content-Type: application/x-www-form-urlencoded
Cookie: sid_admin=d338b9b1d1d2e64f6c0a; admin_csrf_token=0792f...
X-CSRF-Token: 0792f...

order_id=10571&planfix_task_id=PF-1288&planfix_object_type=task
```

Example response:

```http
HTTP/1.1 302 Found
Location: /admin.php?dispatch=orders.details&order_id=10571
Set-Cookie: notices[success]=Planfix+task+PF-1288+bound+to+order+10571
```

### Manual setup

1. Create SEO rule for `/media-lists`:
- Admin panel -> SEO -> SEO rules -> Add new rule
- SEO name: `media-lists`
- Dispatch: `mwl_xlsx.manage`

2. Create block in header:
- Admin panel -> Design -> Layouts -> Add new block
- Block type: `HTML with Smarty`
- Block content: `{include file="addons/mwl_xlsx/blocks/media_lists_counter.tpl"}`

### Dev install

```bash
cscart-sdk addon:symlink mwl_xlsx /path/to/mwl_xlsx /path/to/public_html --templates-to-design
```

### Compact Price Slider Labels

The add-on includes a feature to display compact price slider labels with localization support:

#### Features
- **Compact Format**: Large numbers are displayed in shortened format (e.g., 275,435,920 → 275 млн. / 275 M)
- **Localization**: Supports Russian and English with appropriate suffixes:
  - Russian: тыс., млн., млрд., трлн.
  - English: K, M, B, T
- **Currency Preservation**: Maintains currency prefix/suffix from filter settings
- **Non-intrusive**: Only affects display labels, doesn't change filter logic or values
- **Configurable**: Can be enabled/disabled via add-on settings

#### Test Cases
- 950 → 950 (no change)
- 12,345 → 12 тыс. / 12 K
- 1,234,567 → 1 млн. / 1 M
- 2,147,483,647 → 2 млрд. / 2 B
- 1,999,999,999,999 → 1 трлн. / 1 T

### Dev tools

- `admin.php?dispatch=mwl_xlsx.dev_reload_langs` – import addon language files from `var/langs` and clear cache.
