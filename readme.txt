=== WB CPT & Taxonomy Order ===
Contributors: vapvarun, wbcomdesigns
Tags: post order, custom post type order, taxonomy order, drag and drop, sort posts
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Drag-and-drop ordering for any custom post type and its taxonomy terms — filter by taxonomy first, so you only sort the items you care about.

== Description ==

Most "post order" plugins force you to drag through thousands of posts in a single flat list. **WB CPT & Taxonomy Order** takes a smarter approach: pick a taxonomy term first, and only the posts in that term show up — drag a manageable list, save, done.

Built originally for documentation sites where you want to order articles inside a category, but works for any custom post type and any public taxonomy on your site.

= Features =

* Order any public custom post type — products, docs, portfolio, recipes, events, etc.
* Taxonomy-scoped post ordering — pick a term, drag only its posts
* Order taxonomy terms (parents and children, hierarchical or flat)
* Auto-save on drop with debounced AJAX — no "Save" button
* Frontend ordering via `pre_get_posts` and `get_terms` filters (toggleable)
* REST API endpoints for programmatic ordering
* Developer filters: `wb_cpto_supported_post_types`, `wb_cpto_enabled_term_taxonomies`
* Translation-ready
* Responsive admin UI — drag works on touch devices

= How it works =

* **Posts** are ordered using the native `menu_order` column in `wp_posts` — zero overhead, works with `orderby => 'menu_order'` everywhere.
* **Terms** are ordered using a `term_order` term meta key — sorted post-query so ordering survives WordPress's lack of native term ordering.

= REST API =

`POST /wp-json/wb-cpt-order/v1/term-order` — single term order
`POST /wp-json/wb-cpt-order/v1/term-order/bulk` — bulk term ordering

Both require the `manage_categories` capability.

= Developer hooks =

`apply_filters( 'wb_cpto_supported_post_types', $post_types );`
`apply_filters( 'wb_cpto_enabled_term_taxonomies', $taxonomies );`

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install from the WordPress Plugin Directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **WB CPT Order** in the admin sidebar.
4. Pick a post type, taxonomy, and term, then drag posts to reorder.

== Frequently Asked Questions ==

= Will it conflict with WooCommerce or other ordering plugins? =

The plugin uses native `menu_order` for posts and a dedicated `term_order` meta for terms, so it plays nicely with WooCommerce's product sorting, ACF, Yoast, etc. If multiple plugins try to set `pre_get_posts` orderby, the later filter wins — disable "Frontend Ordering" in Settings if you need manual control.

= Why a custom term meta instead of a `term_order` column? =

WordPress doesn't ship a native `term_order` column anymore. Term meta works the same way, doesn't require schema changes, and is portable across hosts.

= Does this work with block themes / FSE / Query Loop? =

Yes. The plugin operates at the query layer (`pre_get_posts`, `get_terms`), so any block theme, Query Loop, or core block respects the order automatically.

= Will it slow down my site? =

Post ordering uses a native database column (`menu_order`) — zero overhead. Term ordering does an in-memory sort after `get_terms` returns results, which is negligible for typical taxonomy sizes.

= Can I limit which post types are sortable? =

Yes — go to **WB CPT Order → Settings** and check only the post types you want to manage.

== Screenshots ==

1. Order Posts page — pick post type, taxonomy, and term; drag the filtered list of posts.
2. Order Terms page — reorder taxonomy terms (parents or children).
3. Settings page — toggle frontend ordering and choose which CPTs are sortable.

== Changelog ==

= 1.0.0 =
* Initial release.
* Drag-and-drop ordering for posts within a taxonomy term.
* Drag-and-drop ordering for taxonomy terms (hierarchical and flat).
* Frontend ordering via `pre_get_posts` and `get_terms` filters.
* REST API endpoints for single and bulk term ordering.
* Settings page for enabling/disabling per post type.
* Translation-ready.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
