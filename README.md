# WB CPT & Taxonomy Order

> Drag-and-drop ordering for any custom post type and its taxonomy terms — filter by taxonomy first, so you only sort the items you care about.

[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://www.php.net/)

---

## Why this plugin?

Most "post order" plugins force you to drag through **thousands** of posts in a single flat list. WB CPT & Taxonomy Order takes a smarter approach:

> **Pick a taxonomy term first → only the posts in that term show up → drag a manageable list.**

Built originally for documentation sites where you want to order articles inside a category — but works for any custom post type and any public taxonomy on your site.

## Features

- 📦 **Order any public CPT** — products, docs, portfolio, recipes, events… anything `public => true`
- 🏷️ **Taxonomy-scoped post ordering** — pick a term, drag only its posts (no infinite-scroll pain)
- 🌳 **Order taxonomy terms** — both parent terms and children, hierarchical or flat
- ⚡ **Auto-save on drop** — debounced AJAX, no "Save" button needed
- 🎯 **Frontend ordering** — `pre_get_posts` filter applies your custom order on the front end (toggleable)
- 🔌 **REST API** — `POST /wp-json/wb-cpt-order/v1/term-order` and `/term-order/bulk` for programmatic ordering
- 🪝 **Developer filters** — `wb_cpto_supported_post_types`, `wb_cpto_enabled_term_taxonomies`
- 🌐 **Translation-ready** — text domain `wb-cpt-taxonomy-order`
- 📱 **Responsive admin UI** — drag works on touch devices too

## How it works

| Thing being ordered | Storage | Why |
|---|---|---|
| Posts | `wp_posts.menu_order` column | Native WP field — works with `orderby => 'menu_order'` everywhere |
| Terms | `term_order` term meta | Sorted post-query so ordering survives WP's lack of native term order |

## Installation

### From GitHub

```bash
cd wp-content/plugins/
git clone https://github.com/vapvarun/wb-cpt-taxonomy-order.git
```

Then activate in **Plugins → Installed Plugins**.

### Manual

1. Download the ZIP from the [releases page](https://github.com/vapvarun/wb-cpt-taxonomy-order/releases)
2. **Plugins → Add New → Upload Plugin**
3. Activate

## Usage

After activation, a new **WB CPT Order** menu appears in the admin sidebar with three pages:

### 1. Order Posts

```
Post Type → Taxonomy → Parent Term → Child Term → drag posts
```

Drag the list to reorder. Saves automatically.

### 2. Order Terms

```
Post Type → Taxonomy → Children-of-X (or all parents) → drag terms
```

Reorder taxonomy terms for menus, archive pages, and any `get_terms()` call.

### 3. Settings

- **Enable Frontend Ordering** — toggle whether `pre_get_posts` and `get_terms` filters apply your order to the front end
- **Enabled Post Types** — choose which CPTs are sortable (defaults to all public non-builtin types)

## Screenshots

| Screen | What you see |
|---|---|
| Order Posts | Filter dropdowns at top, sortable list of posts in selected term, status badges (Published/Draft/Pending), Edit links |
| Order Terms | Filter dropdowns, sortable term list with post counts |
| Settings | Toggle frontend ordering + CPT checkboxes |

## REST API

### Single term
```bash
curl -X POST https://example.com/wp-json/wb-cpt-order/v1/term-order \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: <nonce>" \
  -d '{"term_id": 42, "order": 3, "taxonomy": "product_cat"}'
```

### Bulk
```bash
curl -X POST https://example.com/wp-json/wb-cpt-order/v1/term-order/bulk \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: <nonce>" \
  -d '{"taxonomy": "product_cat", "orders": [{"term_id": 42, "order": 1}, {"term_id": 43, "order": 2}]}'
```

Both endpoints require the `manage_categories` capability.

## Filters & hooks

```php
// Restrict which CPTs the plugin manages.
add_filter( 'wb_cpto_supported_post_types', function( $post_types ) {
    unset( $post_types['attachment'] );
    return $post_types;
} );

// Limit which taxonomies get auto-sorted on the frontend.
add_filter( 'wb_cpto_enabled_term_taxonomies', function( $taxonomies ) {
    return array( 'product_cat', 'doc_category' );
} );
```

## Requirements

- WordPress **5.0+**
- PHP **7.4+**

## FAQ

**Q. Will it conflict with WooCommerce / Yoast / other "order" plugins?**
A. The plugin uses native `menu_order` for posts and a dedicated `term_order` meta for terms, so it plays nicely with WooCommerce's own product sorting, ACF, Yoast, etc. If multiple plugins try to set `pre_get_posts` orderby, the later filter wins — disable "Frontend Ordering" in Settings if you want manual control.

**Q. Why a custom term meta instead of a `term_order` column?**
A. WordPress doesn't ship a native `term_order` column anymore (it was removed years ago). Term meta works the same, doesn't require schema changes, and is portable across hosts.

**Q. Does this work with Gutenberg / FSE block themes?**
A. Yes. The plugin doesn't touch the editor — it operates at the query layer (`pre_get_posts`, `get_terms`), so any block theme, query loop, or core block respects the order automatically.

**Q. Will it slow down my site?**
A. The post ordering uses a native database column (`menu_order`) — zero overhead. Term ordering does an in-memory sort after `get_terms` returns results, which is negligible for typical taxonomy sizes (<500 terms).

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Contributing

PRs welcome. Please:

1. Fork the repo
2. Branch from `main`
3. Match the existing WordPress Coding Standards (run `phpcs --standard=WordPress`)
4. Open a PR with a clear description of what changed and why

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

## Credits

Built by [Varun Dubey](https://github.com/vapvarun) at [Wbcom Designs](https://wbcomdesigns.com).
