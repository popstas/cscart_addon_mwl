# Media List Add-on Guidelines

This project implements a CS-Cart Multivendor add-on for the `abt__unitheme2` theme: **Media Lists**.

## Glossary
- "Media list" / "подборка": **media list**

# Pull request naming
Create name using angular commit message format.
`feat:` and `fix:` are using in CHANGELOG.md. It's a release notes for developers. Name your PRs in a way that it's easy to understand what was changed. Forbidden to use `feat:` and `fix:` prefixes for chore tasks that don't add new features or fix bugs.

# Cron tasks
Rules for backend cron modes: publish_down_missing_products, delete_unused_products, filters_sync and other similar tasks:
- Don't use .po files for cron tasks. Use hardcoded english messages
- Remove existing messages from .po files, change to hardcoded messages

## Core Features
- Add a single product to a media list
- Add all selected products to a media list (configurable limit, default 50 items)
- Name media lists
- Media lists page
- Export media list to XLSX on the media lists page
- Export media list to XLSX on a single media list page
- Upload XLSX template for a media list

## Rules on new features:
- Add documentation for new features to README.md

## Development Practices
- Structure the add-on according to the [CS-Cart developer guide](https://docs.cs-cart.com/latest/developer_guide/addons/index.html).
- Place PHP code under `app/addons/<addon_name>/` and templates under `var/themes_repository/abt__unitheme2/templates/addons/<addon_name>/`.
- Register the add-on with a `addon.xml` scheme file as described in [Add-on Scheme](https://docs.cs-cart.com/latest/developer_guide/addons/addon_scheme.html).
- Use hooks and template overrides as outlined in [Custom Templates via Add-on](https://docs.cs-cart.com/latest/developer_guide/addons/tutorials/custom_templates_via_addon.html).
- Define and use language variables with `__()` and store them under `var/langs/<language>/addons/<addon_name>.po` as per [Language Variables in Add-on](https://docs.cs-cart.com/latest/developer_guide/addons/language_variables_in_addon.html).
- Availabe icons classes placed at `docs/unitheme_icons.md`
- Some of the hooks placed at `docs/hooks.md`

## Dependencies
- Manage PHP dependencies with Composer.
- Store installed dependencies in the `vendor/` directory.
- Run `composer install` after any change to `composer.json`.
