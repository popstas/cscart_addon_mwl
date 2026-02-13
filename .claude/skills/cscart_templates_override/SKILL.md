---
name: cscart_templates_override
description: Override CS-Cart core or theme templates from an addon without editing core files. Use when adding or changing backoffice (admin) or storefront templates in a CS-Cart addon—where to place override files, path rules, and cache. Covers backend overrides (design/backend/templates/addons/<addon>/overrides/) and frontend theme overrides (var/themes_repository/<theme>/templates/addons/<addon>/overrides/).
---

# CS-Cart Addon Template Overrides

## When overrides apply

- **Backend (admin):** Overrides are loaded from `design/backend/templates/addons/<addon_id>/overrides/`. The path under `overrides/` must mirror the core template path (e.g. `views/profiles/update.tpl`).
- **Frontend (storefront):** Theme-specific overrides live under the theme repo, e.g. `var/themes_repository/<theme>/templates/addons/<addon_id>/overrides/`. Same mirror rule.

Core resolves overrides in `Tygh\SmartyEngine\FileResource::populate()` via `fn_addon_template_overrides($resource_name, $view)`, which scans **only** the `overrides/` directory. Files under `views/` (without `overrides/`) are **not** used as overrides.

## Backend override path (admin)

| Goal | Addon path (relative to addon root) |
|------|-------------------------------------|
| Override `views/profiles/update.tpl` | `design/backend/templates/addons/<addon_id>/overrides/views/profiles/update.tpl` |
| Override `views/orders/details.tpl`  | `design/backend/templates/addons/<addon_id>/overrides/views/orders/details.tpl`  |

- Directory scanned by core: `{$area_type}/templates/addons/{$addon_name}/overrides/` (see [references/backend-overrides.md](references/backend-overrides.md)).
- After adding or changing override files, clear cache (e.g. `var/cache` or admin "Clear cache") so `template_overrides` cache is rebuilt.

## Frontend override path (storefront theme)

- For a theme like `abt__unitheme2`, overrides live under that theme's directory, e.g. `var/themes_repository/abt__unitheme2/templates/addons/<addon_id>/overrides/`.
- Same rule: path under `overrides/` must match the template path you are overriding.

## Checklist

1. Use **overrides/** subdirectory; do not put override templates in `views/` alone.
2. Mirror the core template path under `overrides/` (e.g. `overrides/views/profiles/update.tpl`).
3. Clear cache after adding or changing overrides.
4. Optional: add an HTML comment or `data-*` attribute in the override to confirm in "View source" that the override is loaded.

## References

- [references/backend-overrides.md](references/backend-overrides.md) — Backend override directory and cache logic.
