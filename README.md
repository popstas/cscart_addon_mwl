## Multi Wishlist Add-on

### Features
- Create and name wishlists for the current user or session.
- Add individual products to a chosen wishlist from product listings.
- View existing wishlists on the **My wishlists** page.

### Add-on URLs
- `index.php?dispatch=mwl_xlsx.manage` – list user wishlists.
- `index.php?dispatch=mwl_xlsx.create_list` – create a wishlist (POST).
- `index.php?dispatch=mwl_xlsx.add` – add a product to a wishlist (POST).

### TODO
- [ ] Add all selected products to a wishlist (default limit: 20 items).
- [ ] Export wishlist to XLSX on the wishlist list page.
- [ ] Export wishlist to XLSX on a single wishlist page.
- [ ] Upload XLSX template for a wishlist.

### Dev install

```bash
cscart-sdk addon:symlink mwl_xlsx /path/to/mwl_xlsx /path/to/public_html --templates-to-design
```
