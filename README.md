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

* **Entry point**: `php admin.php --dispatch=mwl_xlsx.publish_down_missing_products` (intended for cron/CLI).
* **Settings**:
  * **Publish down stale products** toggles the feature.
  * **Publish down limit** caps the number of products disabled per run. Set to `0` to disable the cap.
  * **Publish down period (seconds)** defines how old the `cscart_products.updated_timestamp` may become before the product is disabled (default `3600` seconds = 1 hour).
* **Behaviour**: Each run queries `cscart_products` for entries with `status` in `A/H/P` whose `updated_timestamp` (falling back to `timestamp` when empty) is older than the configured period. The matching products are disabled (via `fn_tools_update_status` when available) until the optional limit is reached. Actions, disabled IDs, limit hits, and errors are printed to STDOUT and recorded in `var/log/mwl_xlsx.log`.

### Delete unused products

* **Entry point**: `php admin.php --dispatch=mwl_xlsx.delete_unused_products` (CLI/cron only).
* **Scan**: Collects product IDs that appear in critical tables (`cscart_mwl_xlsx_list_products`, `cscart_order_details`, `cscart_product_reviews`, `cscart_product_sales`, `cscart_rma_return_products`, `cscart_wishlist_products`, `cscart_user_session_products`, `cscart_product_subscriptions`, and `cscart_discussion_posts` for products). Products that do not occur in any of these tables remain candidates for deletion.
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
