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
- Manage a single XLSX template from the admin panel for custom export layouts; uploading a new file replaces the previous template and exports use it when available.
- Navigate media lists with breadcrumbs and page titles.
- Rename or remove media lists from the manage page.
- Track when each media list was last updated.
- SEO-friendly URLs for media list pages (`/media-lists` and `/media-lists/{list_id}`).
- UT2 top panel block displaying the number of media lists for the current user or session.
- Hide prices for guests or unauthorized user groups.
- Optional extra button in product lists for adding items to a media list.
- Limit media list features to specific user groups.
- Configure price visibility, extra list button, and access groups via the add-on's **Settings** page in the admin panel.
- Limit the number of items in a media list via the **Max list items** setting (default 50).

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

### Dev tools

- `admin.php?dispatch=mwl_xlsx.dev_reload_langs` – import addon language files from `var/langs` and clear cache.
