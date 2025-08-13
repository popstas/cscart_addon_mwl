## Multi Wishlist Add-on

### Features
- Create and name wishlists for the current user or session.
- Add individual products to a chosen wishlist from product listings.
- Avoid duplicate products when adding to a wishlist.
- View existing wishlists on the **My wishlists** page.
- Each wishlist shows the number of products and links to its own page.
- View products of a wishlist on a dedicated page.
- Export wishlists to XLSX files from the wishlist list page, placing each product feature in its own column and translating product and feature data into the site's current language.
- Navigate wishlists with breadcrumbs and page titles.
- Rename or remove wishlists from the manage page.

### Add-on URLs
- `index.php?dispatch=mwl_xlsx.manage` – list user wishlists.
- `index.php?dispatch=mwl_xlsx.create_list` – create a wishlist (POST).
- `index.php?dispatch=mwl_xlsx.add` – add a product to a wishlist (POST).
- `index.php?dispatch=mwl_xlsx.rename_list` – rename a wishlist (POST).
- `index.php?dispatch=mwl_xlsx.delete_list` – remove a wishlist (POST).

### TODO
- [ ] Add all selected products to a wishlist (default limit: 20 items).
- [ ] Export wishlist to XLSX on a single wishlist page.
- [ ] Upload XLSX template for a wishlist.

### Dev install

```bash
cscart-sdk addon:symlink mwl_xlsx /path/to/mwl_xlsx /path/to/public_html --templates-to-design
```

### Dev tools

- `admin.php?dispatch=mwl_xlsx.dev_reload_langs` – import addon language files from `var/langs` and clear cache.
