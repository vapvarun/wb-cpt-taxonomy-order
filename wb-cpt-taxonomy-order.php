<?php
/**
 * Plugin Name: WB CPT & Taxonomy Order
 * Plugin URI: https://wbcomdesigns.com/downloads/wb-cpt-taxonomy-order/
 * Description: Order any custom post type and their taxonomy terms with an intuitive drag-and-drop interface. Filter by taxonomy to order only specific items at a time - no more scrolling through thousands of posts!
 * Version: 1.0.0
 * Author: Wbcom Designs
 * Author URI: https://wbcomdesigns.com
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wb-cpt-taxonomy-order
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'WB_CPTO_VERSION', '1.0.0' );
define( 'WB_CPTO_FILE', __FILE__ );
define( 'WB_CPTO_PATH', plugin_dir_path( __FILE__ ) );
define( 'WB_CPTO_URL', plugin_dir_url( __FILE__ ) );

/**
 * WB CPT & Taxonomy Order Main Class
 *
 * @since 1.0.0
 */
class WB_CPT_Taxonomy_Order {

	/**
	 * Single instance of the class
	 *
	 * @var WB_CPT_Taxonomy_Order
	 */
	private static $instance = null;

	/**
	 * Supported post types cache
	 *
	 * @var array
	 */
	private $supported_post_types = array();

	/**
	 * Get single instance of the class
	 *
	 * @return WB_CPT_Taxonomy_Order
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// AJAX handlers.
		add_action( 'wp_ajax_wb_cpto_save_order', array( $this, 'save_post_order' ) );
		add_action( 'wp_ajax_wb_cpto_save_term_order', array( $this, 'save_term_order' ) );

		// Frontend ordering.
		add_filter( 'pre_get_posts', array( $this, 'order_posts_frontend' ) );
		add_filter( 'get_terms_args', array( $this, 'set_term_order_args' ), 10, 2 );
		add_filter( 'get_terms', array( $this, 'sort_terms_by_order' ), 10, 4 );

		// Add menu_order support to post types.
		add_action( 'init', array( $this, 'add_menu_order_support' ), 99 );

		// Load textdomain.
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// REST API endpoint for setting term order.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register REST API routes
	 */
	public function register_rest_routes() {
		register_rest_route(
			'wb-cpt-order/v1',
			'/term-order',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_set_term_order' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_categories' );
				},
				'args'                => array(
					'term_id'    => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'order'      => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'taxonomy'   => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'doc_category',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			'wb-cpt-order/v1',
			'/term-order/bulk',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_bulk_set_term_order' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_categories' );
				},
				'args'                => array(
					'orders'   => array(
						'required'    => true,
						'type'        => 'array',
						'description' => 'Array of {term_id, order} objects',
					),
					'taxonomy' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'doc_category',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * REST API callback to set single term order
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function rest_set_term_order( $request ) {
		$term_id  = $request->get_param( 'term_id' );
		$order    = $request->get_param( 'order' );
		$taxonomy = $request->get_param( 'taxonomy' );

		$term = get_term( $term_id, $taxonomy );
		if ( is_wp_error( $term ) || ! $term ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Term not found',
				),
				404
			);
		}

		$updated = update_term_meta( $term_id, 'term_order', $order );
		clean_term_cache( $term_id, $taxonomy );

		return new \WP_REST_Response(
			array(
				'success'  => true,
				'term_id'  => $term_id,
				'order'    => $order,
				'term_name' => $term->name,
			)
		);
	}

	/**
	 * REST API callback to bulk set term orders
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function rest_bulk_set_term_order( $request ) {
		$orders   = $request->get_param( 'orders' );
		$taxonomy = $request->get_param( 'taxonomy' );
		$results  = array();

		foreach ( $orders as $item ) {
			$term_id = absint( $item['term_id'] ?? 0 );
			$order   = absint( $item['order'] ?? 0 );

			if ( ! $term_id ) {
				continue;
			}

			$term = get_term( $term_id, $taxonomy );
			if ( is_wp_error( $term ) || ! $term ) {
				$results[] = array(
					'term_id' => $term_id,
					'success' => false,
					'message' => 'Term not found',
				);
				continue;
			}

			update_term_meta( $term_id, 'term_order', $order );
			clean_term_cache( $term_id, $taxonomy );

			$results[] = array(
				'term_id'   => $term_id,
				'order'     => $order,
				'term_name' => $term->name,
				'success'   => true,
			);
		}

		wp_cache_flush();

		return new \WP_REST_Response(
			array(
				'success' => true,
				'updated' => count( array_filter( $results, fn( $r ) => $r['success'] ) ),
				'results' => $results,
			)
		);
	}

	/**
	 * Load plugin textdomain
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'wb-cpt-taxonomy-order', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/**
	 * Get supported post types
	 *
	 * @return array
	 */
	public function get_supported_post_types() {
		if ( empty( $this->supported_post_types ) ) {
			$post_types = get_post_types(
				array(
					'public'   => true,
					'_builtin' => false,
				),
				'objects'
			);

			// Filter supported post types.
			$enabled = get_option( 'wb_cpto_enabled_post_types', array() );

			if ( ! empty( $enabled ) ) {
				$post_types = array_filter(
					$post_types,
					function ( $pt ) use ( $enabled ) {
						return in_array( $pt->name, $enabled, true );
					}
				);
			}

			/**
			 * Filter supported post types
			 *
			 * @param array $post_types Array of post type objects.
			 */
			$this->supported_post_types = apply_filters( 'wb_cpto_supported_post_types', $post_types );
		}

		return $this->supported_post_types;
	}

	/**
	 * Add menu_order support to supported post types
	 */
	public function add_menu_order_support() {
		$post_types = $this->get_supported_post_types();

		foreach ( $post_types as $post_type ) {
			if ( ! post_type_supports( $post_type->name, 'page-attributes' ) ) {
				add_post_type_support( $post_type->name, 'page-attributes' );
			}
		}
	}

	/**
	 * Add admin menu pages
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'WB CPT Order', 'wb-cpt-taxonomy-order' ),
			__( 'WB CPT Order', 'wb-cpt-taxonomy-order' ),
			'edit_posts',
			'wb-cpt-order',
			array( $this, 'render_posts_order_page' ),
			'dashicons-sort',
			30
		);

		add_submenu_page(
			'wb-cpt-order',
			__( 'Order Posts', 'wb-cpt-taxonomy-order' ),
			__( 'Order Posts', 'wb-cpt-taxonomy-order' ),
			'edit_posts',
			'wb-cpt-order',
			array( $this, 'render_posts_order_page' )
		);

		add_submenu_page(
			'wb-cpt-order',
			__( 'Order Terms', 'wb-cpt-taxonomy-order' ),
			__( 'Order Terms', 'wb-cpt-taxonomy-order' ),
			'manage_categories',
			'wb-cpt-order-terms',
			array( $this, 'render_terms_order_page' )
		);

		add_submenu_page(
			'wb-cpt-order',
			__( 'Settings', 'wb-cpt-taxonomy-order' ),
			__( 'Settings', 'wb-cpt-taxonomy-order' ),
			'manage_options',
			'wb-cpt-order-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Enqueue admin scripts
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_scripts( $hook ) {
		$allowed_hooks = array(
			'toplevel_page_wb-cpt-order',
			'wb-cpt-order_page_wb-cpt-order-terms',
			'wb-cpt-order_page_wb-cpt-order-settings',
		);

		if ( ! in_array( $hook, $allowed_hooks, true ) ) {
			return;
		}

		wp_enqueue_script( 'jquery-ui-sortable' );

		wp_enqueue_style(
			'wb-cpto-admin-style',
			WB_CPTO_URL . 'assets/admin.css',
			array(),
			WB_CPTO_VERSION
		);

		wp_enqueue_script(
			'wb-cpto-admin-script',
			WB_CPTO_URL . 'assets/admin.js',
			array( 'jquery', 'jquery-ui-sortable' ),
			WB_CPTO_VERSION,
			true
		);

		wp_localize_script(
			'wb-cpto-admin-script',
			'wbCptoAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'wb_cpto_save_order' ),
				'termNonce' => wp_create_nonce( 'wb_cpto_save_term_order' ),
				'saving'    => __( 'Saving...', 'wb-cpt-taxonomy-order' ),
				'saved'     => __( 'Order saved!', 'wb-cpt-taxonomy-order' ),
				'error'     => __( 'Error saving order.', 'wb-cpt-taxonomy-order' ),
			)
		);
	}

	/**
	 * Get terms ordered by term_order meta, with fallback for terms without meta
	 *
	 * @param array $args Term query arguments.
	 * @return array|WP_Error
	 */
	private function get_ordered_terms( $args ) {
		// First, get all terms without meta ordering.
		$query_args = $args;
		$query_args['orderby'] = 'name';
		$query_args['order'] = 'ASC';
		unset( $query_args['meta_key'] );

		$terms = get_terms( $query_args );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return $terms;
		}

		// Now sort by term_order meta if available.
		usort(
			$terms,
			function ( $a, $b ) {
				$order_a = get_term_meta( $a->term_id, 'term_order', true );
				$order_b = get_term_meta( $b->term_id, 'term_order', true );

				// If both have order, compare numerically.
				if ( '' !== $order_a && '' !== $order_b ) {
					return (int) $order_a - (int) $order_b;
				}

				// Terms with order come first.
				if ( '' !== $order_a ) {
					return -1;
				}
				if ( '' !== $order_b ) {
					return 1;
				}

				// Fall back to name comparison.
				return strcasecmp( $a->name, $b->name );
			}
		);

		return $terms;
	}

	/**
	 * Get hierarchical taxonomies for a post type
	 *
	 * @param string $post_type Post type name.
	 * @return array
	 */
	private function get_hierarchical_taxonomies( $post_type ) {
		$taxonomies = get_object_taxonomies( $post_type, 'objects' );

		return array_filter(
			$taxonomies,
			function ( $tax ) {
				return $tax->hierarchical && $tax->public;
			}
		);
	}

	/**
	 * Get non-hierarchical taxonomies for a post type
	 *
	 * @param string $post_type Post type name.
	 * @return array
	 */
	private function get_flat_taxonomies( $post_type ) {
		$taxonomies = get_object_taxonomies( $post_type, 'objects' );

		return array_filter(
			$taxonomies,
			function ( $tax ) {
				return ! $tax->hierarchical && $tax->public;
			}
		);
	}

	/**
	 * Get all public taxonomies for a post type
	 *
	 * @param string $post_type Post type name.
	 * @return array
	 */
	private function get_all_taxonomies( $post_type ) {
		$taxonomies = get_object_taxonomies( $post_type, 'objects' );

		return array_filter(
			$taxonomies,
			function ( $tax ) {
				return $tax->public;
			}
		);
	}

	/**
	 * Render posts order page
	 */
	public function render_posts_order_page() {
		$post_types      = $this->get_supported_post_types();
		$selected_type   = isset( $_GET['cpt_type'] ) ? sanitize_key( $_GET['cpt_type'] ) : '';
		$selected_tax    = isset( $_GET['tax'] ) ? sanitize_key( $_GET['tax'] ) : '';
		$selected_parent = isset( $_GET['parent_term'] ) ? absint( $_GET['parent_term'] ) : 0;
		$selected_term   = isset( $_GET['term'] ) ? absint( $_GET['term'] ) : 0;

		$taxonomies   = array();
		$parent_terms = array();
		$child_terms  = array();
		$posts        = array();
		$is_hierarchical = true;

		if ( $selected_type ) {
			$taxonomies = $this->get_all_taxonomies( $selected_type );
		}

		if ( $selected_tax ) {
			$tax_obj = get_taxonomy( $selected_tax );
			$is_hierarchical = $tax_obj ? $tax_obj->hierarchical : true;

			if ( $is_hierarchical ) {
				$parent_terms = $this->get_ordered_terms(
					array(
						'taxonomy'   => $selected_tax,
						'hide_empty' => false,
						'parent'     => 0,
					)
				);
			} else {
				// Non-hierarchical taxonomy - get all terms.
				$parent_terms = $this->get_ordered_terms(
					array(
						'taxonomy'   => $selected_tax,
						'hide_empty' => false,
					)
				);
			}
		}

		if ( $selected_parent && $is_hierarchical ) {
			$child_terms = $this->get_ordered_terms(
				array(
					'taxonomy'   => $selected_tax,
					'hide_empty' => false,
					'parent'     => $selected_parent,
				)
			);

			// If no children, use parent directly.
			if ( empty( $child_terms ) || is_wp_error( $child_terms ) ) {
				$selected_term = $selected_parent;
			}
		}

		// For non-hierarchical, use selected_parent as selected_term.
		if ( ! $is_hierarchical && $selected_parent ) {
			$selected_term = $selected_parent;
		}

		if ( $selected_term ) {
			$posts = $this->get_posts_by_term( $selected_type, $selected_tax, $selected_term );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Order Posts by Taxonomy', 'wb-cpt-taxonomy-order' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Select a post type, taxonomy, and term to order posts within that category.', 'wb-cpt-taxonomy-order' ); ?></p>

			<div class="wb-cpto-filters">
				<form method="get" action="">
					<input type="hidden" name="page" value="wb-cpt-order" />

					<div class="wb-cpto-filter-row">
						<label for="cpt_type"><?php esc_html_e( 'Post Type:', 'wb-cpt-taxonomy-order' ); ?></label>
						<select name="cpt_type" id="cpt_type" onchange="this.form.submit()">
							<option value=""><?php esc_html_e( '— Select Post Type —', 'wb-cpt-taxonomy-order' ); ?></option>
							<?php foreach ( $post_types as $pt ) : ?>
								<option value="<?php echo esc_attr( $pt->name ); ?>" <?php selected( $selected_type, $pt->name ); ?>>
									<?php echo esc_html( $pt->labels->name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<?php if ( $selected_type && ! empty( $taxonomies ) ) : ?>
						<div class="wb-cpto-filter-row">
							<label for="tax"><?php esc_html_e( 'Taxonomy:', 'wb-cpt-taxonomy-order' ); ?></label>
							<select name="tax" id="tax" onchange="this.form.submit()">
								<option value=""><?php esc_html_e( '— Select Taxonomy —', 'wb-cpt-taxonomy-order' ); ?></option>
								<?php foreach ( $taxonomies as $tax ) : ?>
									<option value="<?php echo esc_attr( $tax->name ); ?>" <?php selected( $selected_tax, $tax->name ); ?>>
										<?php echo esc_html( $tax->labels->name ); ?>
										<?php echo $tax->hierarchical ? '' : ' (' . esc_html__( 'flat', 'wb-cpt-taxonomy-order' ) . ')'; ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
					<?php endif; ?>

					<?php if ( $selected_tax && ! empty( $parent_terms ) && ! is_wp_error( $parent_terms ) ) : ?>
						<div class="wb-cpto-filter-row">
							<label for="parent_term">
								<?php echo $is_hierarchical ? esc_html__( 'Parent Term:', 'wb-cpt-taxonomy-order' ) : esc_html__( 'Term:', 'wb-cpt-taxonomy-order' ); ?>
							</label>
							<select name="parent_term" id="parent_term" onchange="this.form.submit()">
								<option value=""><?php esc_html_e( '— Select Term —', 'wb-cpt-taxonomy-order' ); ?></option>
								<?php foreach ( $parent_terms as $term ) : ?>
									<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( $selected_parent, $term->term_id ); ?>>
										<?php echo esc_html( $term->name ); ?> (<?php echo esc_html( $term->count ); ?>)
									</option>
								<?php endforeach; ?>
							</select>
						</div>
					<?php endif; ?>

					<?php if ( $is_hierarchical && $selected_parent && ! empty( $child_terms ) && ! is_wp_error( $child_terms ) ) : ?>
						<div class="wb-cpto-filter-row">
							<label for="term"><?php esc_html_e( 'Child Term:', 'wb-cpt-taxonomy-order' ); ?></label>
							<select name="term" id="term" onchange="this.form.submit()">
								<option value=""><?php esc_html_e( '— Select Term —', 'wb-cpt-taxonomy-order' ); ?></option>
								<?php foreach ( $child_terms as $term ) : ?>
									<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( $selected_term, $term->term_id ); ?>>
										<?php echo esc_html( $term->name ); ?> (<?php echo esc_html( $term->count ); ?>)
									</option>
								<?php endforeach; ?>
							</select>
						</div>
					<?php endif; ?>
				</form>
			</div>

			<?php if ( $selected_term && ! empty( $posts ) ) : ?>
				<div class="wb-cpto-status"></div>

				<p class="description">
					<?php esc_html_e( 'Drag and drop to reorder. Changes are saved automatically.', 'wb-cpt-taxonomy-order' ); ?>
				</p>

				<ul id="wb-cpto-sortable" class="wb-cpto-list" data-type="posts" data-post-type="<?php echo esc_attr( $selected_type ); ?>">
					<?php foreach ( $posts as $index => $post ) : ?>
						<li class="wb-cpto-item" data-id="<?php echo esc_attr( $post->ID ); ?>">
							<span class="wb-cpto-handle">☰</span>
							<span class="wb-cpto-order"><?php echo esc_html( $index + 1 ); ?></span>
							<span class="wb-cpto-title"><?php echo esc_html( $post->post_title ); ?></span>
							<span class="wb-cpto-status-badge <?php echo esc_attr( $post->post_status ); ?>">
								<?php echo esc_html( ucfirst( $post->post_status ) ); ?>
							</span>
							<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>" class="wb-cpto-edit" target="_blank">
								<?php esc_html_e( 'Edit', 'wb-cpt-taxonomy-order' ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>

				<p class="wb-cpto-count">
					<?php
					printf(
						/* translators: %d: number of items */
						esc_html__( 'Total: %d items', 'wb-cpt-taxonomy-order' ),
						count( $posts )
					);
					?>
				</p>

			<?php elseif ( $selected_term ) : ?>
				<div class="wb-cpto-notice">
					<p><?php esc_html_e( 'No posts found in this term.', 'wb-cpt-taxonomy-order' ); ?></p>
				</div>
			<?php elseif ( ! $selected_type ) : ?>
				<div class="wb-cpto-notice">
					<p><?php esc_html_e( 'Select a post type to begin.', 'wb-cpt-taxonomy-order' ); ?></p>
				</div>
			<?php elseif ( empty( $taxonomies ) ) : ?>
				<div class="wb-cpto-notice">
					<p><?php esc_html_e( 'This post type has no public taxonomies.', 'wb-cpt-taxonomy-order' ); ?></p>
				</div>
			<?php else : ?>
				<div class="wb-cpto-notice">
					<p><?php esc_html_e( 'Select taxonomy and term to order posts.', 'wb-cpt-taxonomy-order' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render terms order page
	 */
	public function render_terms_order_page() {
		$post_types      = $this->get_supported_post_types();
		$selected_type   = isset( $_GET['cpt_type'] ) ? sanitize_key( $_GET['cpt_type'] ) : '';
		$selected_tax    = isset( $_GET['tax'] ) ? sanitize_key( $_GET['tax'] ) : '';
		$order_type      = isset( $_GET['order_type'] ) ? sanitize_key( $_GET['order_type'] ) : 'children';
		$selected_parent = isset( $_GET['parent_term'] ) ? absint( $_GET['parent_term'] ) : 0;

		$taxonomies   = array();
		$parent_terms = array();
		$items        = array();
		$is_hierarchical = true;

		if ( $selected_type ) {
			$taxonomies = $this->get_all_taxonomies( $selected_type );
		}

		if ( $selected_tax ) {
			$tax_obj = get_taxonomy( $selected_tax );
			$is_hierarchical = $tax_obj ? $tax_obj->hierarchical : true;

			if ( $is_hierarchical ) {
				$parent_terms = $this->get_ordered_terms(
					array(
						'taxonomy'   => $selected_tax,
						'hide_empty' => false,
						'parent'     => 0,
					)
				);

				if ( 'parents' === $order_type ) {
					$items = $parent_terms;
				} elseif ( $selected_parent ) {
					$items = $this->get_ordered_terms(
						array(
							'taxonomy'   => $selected_tax,
							'hide_empty' => false,
							'parent'     => $selected_parent,
						)
					);
				}
			} else {
				// Non-hierarchical - get all terms.
				$items = $this->get_ordered_terms(
					array(
						'taxonomy'   => $selected_tax,
						'hide_empty' => false,
					)
				);
			}
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Order Taxonomy Terms', 'wb-cpt-taxonomy-order' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Select a post type and taxonomy to order terms for navigation and display.', 'wb-cpt-taxonomy-order' ); ?></p>

			<div class="wb-cpto-filters">
				<form method="get" action="">
					<input type="hidden" name="page" value="wb-cpt-order-terms" />

					<div class="wb-cpto-filter-row">
						<label for="cpt_type"><?php esc_html_e( 'Post Type:', 'wb-cpt-taxonomy-order' ); ?></label>
						<select name="cpt_type" id="cpt_type" onchange="this.form.submit()">
							<option value=""><?php esc_html_e( '— Select Post Type —', 'wb-cpt-taxonomy-order' ); ?></option>
							<?php foreach ( $post_types as $pt ) : ?>
								<option value="<?php echo esc_attr( $pt->name ); ?>" <?php selected( $selected_type, $pt->name ); ?>>
									<?php echo esc_html( $pt->labels->name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<?php if ( $selected_type && ! empty( $taxonomies ) ) : ?>
						<div class="wb-cpto-filter-row">
							<label for="tax"><?php esc_html_e( 'Taxonomy:', 'wb-cpt-taxonomy-order' ); ?></label>
							<select name="tax" id="tax" onchange="this.form.submit()">
								<option value=""><?php esc_html_e( '— Select Taxonomy —', 'wb-cpt-taxonomy-order' ); ?></option>
								<?php foreach ( $taxonomies as $tax ) : ?>
									<option value="<?php echo esc_attr( $tax->name ); ?>" <?php selected( $selected_tax, $tax->name ); ?>>
										<?php echo esc_html( $tax->labels->name ); ?>
										<?php echo $tax->hierarchical ? '' : ' (' . esc_html__( 'flat', 'wb-cpt-taxonomy-order' ) . ')'; ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
					<?php endif; ?>

					<?php if ( $selected_tax && $is_hierarchical ) : ?>
						<div class="wb-cpto-filter-row">
							<label for="order_type"><?php esc_html_e( 'Order:', 'wb-cpt-taxonomy-order' ); ?></label>
							<select name="order_type" id="order_type" onchange="this.form.submit()">
								<option value="children" <?php selected( $order_type, 'children' ); ?>>
									<?php esc_html_e( 'Child Terms', 'wb-cpt-taxonomy-order' ); ?>
								</option>
								<option value="parents" <?php selected( $order_type, 'parents' ); ?>>
									<?php esc_html_e( 'Parent Terms', 'wb-cpt-taxonomy-order' ); ?>
								</option>
							</select>
						</div>

						<?php if ( 'children' === $order_type && ! empty( $parent_terms ) && ! is_wp_error( $parent_terms ) ) : ?>
							<div class="wb-cpto-filter-row">
								<label for="parent_term"><?php esc_html_e( 'Parent:', 'wb-cpt-taxonomy-order' ); ?></label>
								<select name="parent_term" id="parent_term" onchange="this.form.submit()">
									<option value=""><?php esc_html_e( '— Select Parent —', 'wb-cpt-taxonomy-order' ); ?></option>
									<?php foreach ( $parent_terms as $term ) : ?>
										<?php
										$child_count = count(
											get_terms(
												array(
													'taxonomy'   => $selected_tax,
													'hide_empty' => false,
													'parent'     => $term->term_id,
												)
											)
										);
										?>
										<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( $selected_parent, $term->term_id ); ?>>
											<?php echo esc_html( $term->name ); ?> (<?php echo esc_html( $child_count ); ?> children)
										</option>
									<?php endforeach; ?>
								</select>
							</div>
						<?php endif; ?>
					<?php endif; ?>
				</form>
			</div>

			<?php if ( ! empty( $items ) && ! is_wp_error( $items ) ) : ?>
				<div class="wb-cpto-status"></div>

				<p class="description">
					<?php esc_html_e( 'Drag and drop to reorder terms. Changes are saved automatically.', 'wb-cpt-taxonomy-order' ); ?>
				</p>

				<ul id="wb-cpto-sortable" class="wb-cpto-list" data-type="terms" data-taxonomy="<?php echo esc_attr( $selected_tax ); ?>">
					<?php foreach ( $items as $index => $term ) : ?>
						<li class="wb-cpto-item wb-cpto-term-item" data-id="<?php echo esc_attr( $term->term_id ); ?>">
							<span class="wb-cpto-handle">☰</span>
							<span class="wb-cpto-order"><?php echo esc_html( $index + 1 ); ?></span>
							<span class="wb-cpto-title">
								<?php echo esc_html( $term->name ); ?>
								<span class="wb-cpto-term-count">(<?php echo esc_html( $term->count ); ?> posts)</span>
							</span>
							<a href="<?php echo esc_url( get_edit_term_link( $term->term_id, $selected_tax ) ); ?>" class="wb-cpto-edit" target="_blank">
								<?php esc_html_e( 'Edit', 'wb-cpt-taxonomy-order' ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>

				<p class="wb-cpto-count">
					<?php
					printf(
						/* translators: %d: number of terms */
						esc_html__( 'Total: %d terms', 'wb-cpt-taxonomy-order' ),
						count( $items )
					);
					?>
				</p>

			<?php elseif ( ! $selected_type ) : ?>
				<div class="wb-cpto-notice">
					<p><?php esc_html_e( 'Select a post type to begin.', 'wb-cpt-taxonomy-order' ); ?></p>
				</div>
			<?php elseif ( empty( $taxonomies ) ) : ?>
				<div class="wb-cpto-notice">
					<p><?php esc_html_e( 'This post type has no public taxonomies.', 'wb-cpt-taxonomy-order' ); ?></p>
				</div>
			<?php elseif ( $is_hierarchical && 'children' === $order_type && ! $selected_parent ) : ?>
				<div class="wb-cpto-notice">
					<p><?php esc_html_e( 'Select a parent term to order its children.', 'wb-cpt-taxonomy-order' ); ?></p>
				</div>
			<?php else : ?>
				<div class="wb-cpto-notice">
					<p><?php esc_html_e( 'No terms found.', 'wb-cpt-taxonomy-order' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render settings page
	 */
	public function render_settings_page() {
		$post_types     = get_post_types( array( 'public' => true ), 'objects' );
		$enabled_types  = get_option( 'wb_cpto_enabled_post_types', array() );
		$frontend_order = get_option( 'wb_cpto_frontend_order', 'yes' );

		// Handle form submission.
		if ( isset( $_POST['wb_cpto_save_settings'] ) && check_admin_referer( 'wb_cpto_settings' ) ) {
			$enabled_types  = isset( $_POST['enabled_types'] ) ? array_map( 'sanitize_key', $_POST['enabled_types'] ) : array();
			$frontend_order = isset( $_POST['frontend_order'] ) ? 'yes' : 'no';

			update_option( 'wb_cpto_enabled_post_types', $enabled_types );
			update_option( 'wb_cpto_frontend_order', $frontend_order );

			// Reset cached post types.
			$this->supported_post_types = array();

			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'wb-cpt-taxonomy-order' ) . '</p></div>';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WB CPT & Taxonomy Order Settings', 'wb-cpt-taxonomy-order' ); ?></h1>

			<form method="post" action="">
				<?php wp_nonce_field( 'wb_cpto_settings' ); ?>

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Frontend Ordering', 'wb-cpt-taxonomy-order' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="frontend_order" value="yes" <?php checked( $frontend_order, 'yes' ); ?> />
								<?php esc_html_e( 'Apply custom order on frontend queries', 'wb-cpt-taxonomy-order' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'When enabled, posts and terms will be ordered by your custom order on the frontend.', 'wb-cpt-taxonomy-order' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enabled Post Types', 'wb-cpt-taxonomy-order' ); ?></th>
						<td>
							<?php foreach ( $post_types as $pt ) : ?>
								<?php if ( 'attachment' === $pt->name ) continue; ?>
								<label style="display: block; margin-bottom: 8px;">
									<input type="checkbox" name="enabled_types[]" value="<?php echo esc_attr( $pt->name ); ?>"
										<?php checked( in_array( $pt->name, $enabled_types, true ) || empty( $enabled_types ) ); ?> />
									<strong><?php echo esc_html( $pt->labels->name ); ?></strong>
									<code><?php echo esc_html( $pt->name ); ?></code>
								</label>
							<?php endforeach; ?>
							<p class="description">
								<?php esc_html_e( 'Select which post types can be ordered. All are enabled by default if none selected.', 'wb-cpt-taxonomy-order' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<input type="submit" name="wb_cpto_save_settings" class="button-primary" value="<?php esc_attr_e( 'Save Settings', 'wb-cpt-taxonomy-order' ); ?>" />
				</p>
			</form>

			<hr />

			<h2><?php esc_html_e( 'How to Use', 'wb-cpt-taxonomy-order' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'Go to "Order Posts" to reorder posts within a specific taxonomy term.', 'wb-cpt-taxonomy-order' ); ?></li>
				<li><?php esc_html_e( 'Go to "Order Terms" to reorder taxonomy terms (categories, tags, etc.).', 'wb-cpt-taxonomy-order' ); ?></li>
				<li><?php esc_html_e( 'Select a post type and taxonomy, then choose a term to see items to order.', 'wb-cpt-taxonomy-order' ); ?></li>
				<li><?php esc_html_e( 'Drag and drop items to reorder. Changes save automatically.', 'wb-cpt-taxonomy-order' ); ?></li>
			</ol>

			<h3><?php esc_html_e( 'Developer Notes', 'wb-cpt-taxonomy-order' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Posts are ordered using the menu_order field in wp_posts table.', 'wb-cpt-taxonomy-order' ); ?></li>
				<li><?php esc_html_e( 'Terms are ordered using term_order meta key in term_meta table.', 'wb-cpt-taxonomy-order' ); ?></li>
				<li><?php printf( esc_html__( 'Use %s filter to customize supported post types.', 'wb-cpt-taxonomy-order' ), '<code>wb_cpto_supported_post_types</code>' ); ?></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Get posts by term
	 *
	 * @param string $post_type Post type name.
	 * @param string $taxonomy  Taxonomy name.
	 * @param int    $term_id   Term ID.
	 * @return array
	 */
	private function get_posts_by_term( $post_type, $taxonomy, $term_id ) {
		$args = array(
			'post_type'      => $post_type,
			'posts_per_page' => -1,
			'post_status'    => array( 'publish', 'draft', 'pending' ),
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
			'tax_query'      => array(
				array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $term_id,
				),
			),
		);

		return get_posts( $args );
	}

	/**
	 * Save posts order via AJAX
	 */
	public function save_post_order() {
		check_ajax_referer( 'wb_cpto_save_order', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wb-cpt-taxonomy-order' ) ) );
		}

		$order = isset( $_POST['order'] ) ? array_map( 'absint', $_POST['order'] ) : array();

		if ( empty( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'No order data received.', 'wb-cpt-taxonomy-order' ) ) );
		}

		global $wpdb;

		foreach ( $order as $position => $post_id ) {
			$wpdb->update(
				$wpdb->posts,
				array( 'menu_order' => $position ),
				array( 'ID' => $post_id ),
				array( '%d' ),
				array( '%d' )
			);

			clean_post_cache( $post_id );
		}

		wp_cache_flush();

		wp_send_json_success( array( 'message' => __( 'Order saved!', 'wb-cpt-taxonomy-order' ) ) );
	}

	/**
	 * Save term order via AJAX
	 */
	public function save_term_order() {
		check_ajax_referer( 'wb_cpto_save_term_order', 'nonce' );

		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wb-cpt-taxonomy-order' ) ) );
		}

		$order    = isset( $_POST['order'] ) ? array_map( 'absint', $_POST['order'] ) : array();
		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( $_POST['taxonomy'] ) : '';

		if ( empty( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'No order data received.', 'wb-cpt-taxonomy-order' ) ) );
		}

		foreach ( $order as $position => $term_id ) {
			update_term_meta( $term_id, 'term_order', $position );
			clean_term_cache( $term_id, $taxonomy );
		}

		wp_cache_flush();

		wp_send_json_success( array( 'message' => __( 'Order saved!', 'wb-cpt-taxonomy-order' ) ) );
	}

	/**
	 * Order posts on frontend by menu_order
	 *
	 * @param WP_Query $query WordPress query object.
	 */
	public function order_posts_frontend( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( 'yes' !== get_option( 'wb_cpto_frontend_order', 'yes' ) ) {
			return;
		}

		$post_type = $query->get( 'post_type' );

		if ( empty( $post_type ) ) {
			return;
		}

		$supported = $this->get_supported_post_types();
		if ( isset( $supported[ $post_type ] ) ) {
			$query->set( 'orderby', 'menu_order' );
			$query->set( 'order', 'ASC' );
		}
	}

	/**
	 * Set term order args - handle explicit term_order requests
	 *
	 * @param array $args       Term query args.
	 * @param array $taxonomies Taxonomies being queried.
	 * @return array
	 */
	public function set_term_order_args( $args, $taxonomies ) {
		if ( 'yes' !== get_option( 'wb_cpto_frontend_order', 'yes' ) ) {
			return $args;
		}

		// If explicitly requesting term_order, convert to meta ordering.
		if ( isset( $args['orderby'] ) && 'term_order' === $args['orderby'] ) {
			$args['orderby']  = 'meta_value_num';
			$args['meta_key'] = 'term_order'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		}

		return $args;
	}

	/**
	 * Sort terms by custom order after retrieval
	 *
	 * @param array         $terms      Array of found terms.
	 * @param array|null    $taxonomies An array of taxonomies.
	 * @param array         $args       An array of get_terms() arguments.
	 * @param WP_Term_Query $term_query The WP_Term_Query object.
	 * @return array
	 */
	public function sort_terms_by_order( $terms, $taxonomies, $args, $term_query ) {
		// Don't modify if disabled.
		if ( 'yes' !== get_option( 'wb_cpto_frontend_order', 'yes' ) ) {
			return $terms;
		}

		// Skip in admin unless AJAX.
		if ( is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return $terms;
		}

		// Skip if empty or not an array of objects.
		if ( empty( $terms ) || ! is_array( $terms ) || ! isset( $terms[0] ) || ! is_object( $terms[0] ) ) {
			return $terms;
		}

		// Skip if fields is not 'all' (we need term objects).
		if ( isset( $args['fields'] ) && 'all' !== $args['fields'] ) {
			return $terms;
		}

		// Get enabled taxonomies for term ordering.
		$enabled_taxonomies = $this->get_enabled_term_taxonomies();

		// Check if any of the queried taxonomies are enabled.
		$taxonomies_array = is_array( $taxonomies ) ? $taxonomies : array( $taxonomies );
		$should_order     = false;

		foreach ( $taxonomies_array as $tax ) {
			if ( in_array( $tax, $enabled_taxonomies, true ) ) {
				$should_order = true;
				break;
			}
		}

		if ( ! $should_order ) {
			return $terms;
		}

		// Sort terms by term_order meta.
		usort(
			$terms,
			function ( $a, $b ) {
				$order_a = get_term_meta( $a->term_id, 'term_order', true );
				$order_b = get_term_meta( $b->term_id, 'term_order', true );

				// If both have order, compare numerically.
				if ( '' !== $order_a && '' !== $order_b ) {
					return (int) $order_a - (int) $order_b;
				}

				// Terms with order come first.
				if ( '' !== $order_a ) {
					return -1;
				}
				if ( '' !== $order_b ) {
					return 1;
				}

				// Fall back to name comparison.
				return strcasecmp( $a->name, $b->name );
			}
		);

		return $terms;
	}

	/**
	 * Get taxonomies enabled for term ordering
	 *
	 * @return array Array of taxonomy names.
	 */
	private function get_enabled_term_taxonomies() {
		$enabled = get_option( 'wb_cpto_enabled_term_taxonomies', array() );

		// If no specific taxonomies set, use all public taxonomies from enabled post types.
		if ( empty( $enabled ) ) {
			$post_types = $this->get_supported_post_types();
			$enabled    = array();

			foreach ( $post_types as $pt ) {
				$taxonomies = get_object_taxonomies( $pt->name, 'names' );
				$enabled    = array_merge( $enabled, $taxonomies );
			}

			$enabled = array_unique( $enabled );
		}

		/**
		 * Filter enabled taxonomies for term ordering.
		 *
		 * @param array $enabled Array of taxonomy names.
		 */
		return apply_filters( 'wb_cpto_enabled_term_taxonomies', $enabled );
	}
}

// Initialize the plugin.
WB_CPT_Taxonomy_Order::get_instance();
