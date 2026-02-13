# Backend template overrides (CS-Cart addon)

## Directory scanned by core

In `app/functions/fn.control.php`, `fn_addon_template_overrides()` builds the list of overrides from:

```text
{$area_type}/templates/addons/{$addon_name}/overrides/
```

- `$area_type` is the area identifier (e.g. `backend` for admin).
- `$addon_name` is the addon id (e.g. `mwl_xlsx`).
- Only files under **overrides/** are considered. Subdirectories are scanned recursively; the relative path of each file (e.g. `views/profiles/update.tpl`) is used to match the requested template resource name.

So for backend, the addon must place override files at:

```text
design/backend/templates/addons/<addon_id>/overrides/<path_identical_to_core>
```

Example: to override `views/profiles/update.tpl`, create:

```text
design/backend/templates/addons/mwl_xlsx/overrides/views/profiles/update.tpl
```

## Cache

Override list is cached (key derived from template dir; cache level `static`, tags `addons`). After adding or changing override files, clear cache so the next request rebuilds the override map.

## Wrong pattern (not used as override)

Putting a file at:

```text
design/backend/templates/addons/<addon_id>/views/profiles/update.tpl
```

does **not** override the core template, because core only looks inside `overrides/`. That path is not used by `fn_addon_template_overrides()`.
