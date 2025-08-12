# Multi Wishlist Add-on Guidelines

This project implements a CS-Cart Multivendor add-on for the `bright_theme` theme: **Multi Wishlist**.

## Glossary
- "Media list" / "подборка": **wishlist**

## Core Features
- Add a single product to a wishlist
- Add all selected products to a wishlist (default limit: 20 items)
- Name wishlists
- Wishlist list page
- Export wishlist to XLSX on the wishlist list page
- Export wishlist to XLSX on a single wishlist page
- Upload XLSX template for a wishlist

## Rules on new features:
- Add documentation for new features to README.md

## Development Practices
- Structure the add-on according to the [CS-Cart developer guide](https://docs.cs-cart.com/latest/developer_guide/addons/index.html).
- Place PHP code under `app/addons/<addon_name>/` and templates under `design/themes/bright_theme/templates/addons/<addon_name>/`.
- Register the add-on with a `addon.xml` scheme file as described in [Add-on Scheme](https://docs.cs-cart.com/latest/developer_guide/addons/addon_scheme.html).
- Use hooks and template overrides as outlined in [Custom Templates via Add-on](https://docs.cs-cart.com/latest/developer_guide/addons/tutorials/custom_templates_via_addon.html).
- Define and use language variables with `__()` and store them under `var/langs/<language>/addons/<addon_name>.po` as per [Language Variables in Add-on](https://docs.cs-cart.com/latest/developer_guide/addons/language_variables_in_addon.html).
- Availabe icons classes placed at `docs/unitheme_icons.md`
- Some of the hooks placed at `docs/hooks.md`

## Dependencies
- Manage PHP dependencies with Composer.
- Store installed dependencies in the `vendor/` directory.
- Run `composer install` after any change to `composer.json`.
