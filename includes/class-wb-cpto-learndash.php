<?php
/**
 * LearnDash integration for WB CPT & Taxonomy Order.
 *
 * Provides hierarchy-aware ordering UI for LearnDash courses, lessons and
 * topics, and writes order changes through LearnDash's own Course Steps API
 * so the Course Builder stays in sync.
 *
 * @package WB_CPT_Taxonomy_Order
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LearnDash integration class.
 */
class WB_CPTO_LearnDash {

	/**
	 * Singleton instance.
	 *
	 * @var WB_CPTO_LearnDash|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return WB_CPTO_LearnDash
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Register LearnDash CPTs as supported in the parent plugin.
		add_filter( 'wb_cpto_supported_post_types', array( $this, 'add_learndash_post_types' ) );

		// Add hierarchy-aware menu page.
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 20 );

		// AJAX save handler.
		add_action( 'wp_ajax_wb_cpto_ld_save_order', array( $this, 'ajax_save_order' ) );

		// Enqueue scripts on our LD page.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Frontend: force menu_order on LearnDash course listings (shortcodes / blocks).
		add_filter( 'learndash_course_grid_query_args', array( $this, 'force_course_menu_order' ), 20 );
		add_filter( 'learndash_ld_course_list_query_args', array( $this, 'force_course_list_menu_order' ), 20, 2 );

		// Frontend: override LearnDash's CPT archive orderby (LD's pre_posts runs at 10
		// and overrides whatever the parent plugin set; we run later to win).
		add_action( 'pre_get_posts', array( $this, 'force_course_archive_menu_order' ), 99 );
	}

	/**
	 * Override the LearnDash course archive query orderby.
	 *
	 * LearnDash's `LD_CPT_Instance::pre_posts()` runs at priority 10 on
	 * `pre_get_posts` and forces its own orderby (default `title`) on the
	 * `sfwd-courses` archive, clobbering both the main-query filter from the
	 * parent plugin and any other earlier hook. We run at 99 so we are the
	 * final word for that archive.
	 *
	 * @param WP_Query $query Query object.
	 */
	public function force_course_archive_menu_order( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( 'yes' !== get_option( 'wb_cpto_frontend_order', 'yes' ) ) {
			return;
		}

		$cpts = self::get_managed_post_types();
		if ( empty( $cpts['course'] ) ) {
			return;
		}

		if ( ! $query->is_post_type_archive( $cpts['course'] ) ) {
			return;
		}

		$query->set( 'orderby', 'menu_order title' );
		$query->set( 'order', 'ASC' );
	}

	/**
	 * Force menu_order on the LearnDash Course Grid shortcode / block query.
	 *
	 * The grid defaults to orderby=ID, which ignores admin-set order. When
	 * frontend ordering is enabled and `sfwd-courses` is being queried, we
	 * coerce to menu_order so the grid matches the order set in this plugin.
	 *
	 * @param array $query_args WP_Query args used by the course grid.
	 * @return array
	 */
	public function force_course_menu_order( $query_args ) {
		if ( 'yes' !== get_option( 'wb_cpto_frontend_order', 'yes' ) ) {
			return $query_args;
		}

		$cpts = self::get_managed_post_types();
		if ( empty( $cpts['course'] ) ) {
			return $query_args;
		}

		$post_type = isset( $query_args['post_type'] ) ? $query_args['post_type'] : '';
		if ( $post_type !== $cpts['course'] ) {
			return $query_args;
		}

		$query_args['orderby'] = 'menu_order title';
		$query_args['order']   = 'ASC';

		return $query_args;
	}

	/**
	 * Force menu_order on the [ld_course_list] shortcode query.
	 *
	 * @param array $query_args WP_Query args.
	 * @param array $atts       Shortcode attributes (unused, signature requirement).
	 * @return array
	 */
	public function force_course_list_menu_order( $query_args, $atts = array() ) {
		unset( $atts );

		if ( 'yes' !== get_option( 'wb_cpto_frontend_order', 'yes' ) ) {
			return $query_args;
		}

		$cpts = self::get_managed_post_types();
		if ( empty( $cpts['course'] ) ) {
			return $query_args;
		}

		$post_type = isset( $query_args['post_type'] ) ? $query_args['post_type'] : '';
		if ( $post_type !== $cpts['course'] ) {
			return $query_args;
		}

		$query_args['orderby'] = 'menu_order title';
		$query_args['order']   = 'ASC';

		return $query_args;
	}

	/**
	 * Check if LearnDash is active and the steps API is available.
	 *
	 * @return bool
	 */
	public static function is_learndash_active() {
		return class_exists( 'SFWD_LMS' ) && function_exists( 'learndash_get_post_type_slug' );
	}

	/**
	 * Is the LearnDash Course Builder owning order for the given course?
	 *
	 * When the Course Builder is enabled (global setting, which is the default
	 * in modern LearnDash), the builder is the sole authority for lesson/topic
	 * order within that course. Our plugin must not override it.
	 *
	 * Per-course `ld_course_steps['course_builder_enabled']` is set when LD
	 * loads steps and mirrors the global flag, so we check both.
	 *
	 * @param int $course_id Course ID. 0 = global check only.
	 * @return bool
	 */
	public static function is_builder_managed( $course_id = 0 ) {
		if ( function_exists( 'learndash_is_course_builder_enabled' ) && learndash_is_course_builder_enabled() ) {
			return true;
		}

		if ( $course_id ) {
			$meta = get_post_meta( $course_id, 'ld_course_steps', true );
			if ( is_array( $meta ) && ! empty( $meta['course_builder_enabled'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get LearnDash CPT slugs we manage.
	 *
	 * @return array
	 */
	public static function get_managed_post_types() {
		if ( ! self::is_learndash_active() ) {
			return array();
		}

		return array(
			'course' => learndash_get_post_type_slug( 'course' ),
			'lesson' => learndash_get_post_type_slug( 'lesson' ),
			'topic'  => learndash_get_post_type_slug( 'topic' ),
		);
	}

	/**
	 * Add LearnDash CPTs to the parent plugin's supported list.
	 *
	 * The parent plugin filters by `public => true, _builtin => false`, which
	 * already includes LD CPTs, but the plugin also restricts via
	 * `wb_cpto_enabled_post_types` option. This filter ensures LD types are
	 * always present as objects when LD is active.
	 *
	 * @param array $post_types Array of post type objects, keyed by name.
	 * @return array
	 */
	public function add_learndash_post_types( $post_types ) {
		if ( ! self::is_learndash_active() ) {
			return $post_types;
		}

		foreach ( self::get_managed_post_types() as $slug ) {
			if ( ! isset( $post_types[ $slug ] ) ) {
				$obj = get_post_type_object( $slug );
				if ( $obj ) {
					$post_types[ $slug ] = $obj;
				}
			}
		}

		return $post_types;
	}

	/**
	 * Add LearnDash submenu under WB CPT Order.
	 */
	public function add_admin_menu() {
		if ( ! self::is_learndash_active() ) {
			return;
		}

		add_submenu_page(
			'wb-cpt-order',
			__( 'Order LearnDash', 'wb-cpt-taxonomy-order' ),
			__( 'Order LearnDash', 'wb-cpt-taxonomy-order' ),
			'edit_courses',
			'wb-cpt-order-learndash',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue scripts on our LD page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_scripts( $hook ) {
		if ( 'wb-cpt-order_page_wb-cpt-order-learndash' !== $hook ) {
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
			'wb-cpto-ld-script',
			WB_CPTO_URL . 'assets/learndash.js',
			array( 'jquery', 'jquery-ui-sortable' ),
			WB_CPTO_VERSION,
			true
		);

		wp_localize_script(
			'wb-cpto-ld-script',
			'wbCptoLD',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wb_cpto_ld_save_order' ),
				'saving'  => __( 'Saving order...', 'wb-cpt-taxonomy-order' ),
				'saved'   => __( 'Order saved.', 'wb-cpt-taxonomy-order' ),
				'error'   => __( 'Error saving order.', 'wb-cpt-taxonomy-order' ),
			)
		);
	}

	/**
	 * Render the LearnDash order page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'edit_courses' ) ) {
			return;
		}

		$cpts = self::get_managed_post_types();
		$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : 'courses';
		$mode = in_array( $mode, array( 'courses', 'lessons', 'topics' ), true ) ? $mode : 'courses';

		$course_id = isset( $_GET['course_id'] ) ? absint( $_GET['course_id'] ) : 0;
		$lesson_id = isset( $_GET['lesson_id'] ) ? absint( $_GET['lesson_id'] ) : 0;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Order LearnDash Items', 'wb-cpt-taxonomy-order' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Reorder courses, lessons or topics. Changes are written through the LearnDash Course Steps API so the Course Builder stays in sync.', 'wb-cpt-taxonomy-order' ); ?>
			</p>

			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-cpt-order-learndash&mode=courses' ) ); ?>"
					class="nav-tab <?php echo 'courses' === $mode ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Courses', 'wb-cpt-taxonomy-order' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-cpt-order-learndash&mode=lessons' ) ); ?>"
					class="nav-tab <?php echo 'lessons' === $mode ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Lessons in Course', 'wb-cpt-taxonomy-order' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-cpt-order-learndash&mode=topics' ) ); ?>"
					class="nav-tab <?php echo 'topics' === $mode ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Topics in Lesson', 'wb-cpt-taxonomy-order' ); ?>
				</a>
			</h2>

			<?php
			if ( 'courses' === $mode ) {
				$this->render_courses_panel();
			} elseif ( 'lessons' === $mode ) {
				$this->render_lessons_panel( $course_id );
			} elseif ( 'topics' === $mode ) {
				$this->render_topics_panel( $course_id, $lesson_id );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render the courses ordering panel.
	 */
	private function render_courses_panel() {
		$cpts    = self::get_managed_post_types();
		$courses = get_posts(
			array(
				'post_type'      => $cpts['course'],
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			)
		);

		if ( empty( $courses ) ) {
			echo '<div class="wb-cpto-notice"><p>' . esc_html__( 'No courses found.', 'wb-cpt-taxonomy-order' ) . '</p></div>';
			return;
		}
		?>
		<p class="description"><?php esc_html_e( 'Drag and drop to reorder courses. Saved automatically.', 'wb-cpt-taxonomy-order' ); ?></p>
		<div class="wb-cpto-status"></div>
		<ul id="wb-cpto-sortable" class="wb-cpto-list" data-mode="courses">
			<?php foreach ( $courses as $i => $course ) : ?>
				<li class="wb-cpto-item" data-id="<?php echo esc_attr( $course->ID ); ?>">
					<span class="wb-cpto-handle">☰</span>
					<span class="wb-cpto-order"><?php echo esc_html( $i + 1 ); ?></span>
					<span class="wb-cpto-title"><?php echo esc_html( $course->post_title ); ?></span>
					<span class="wb-cpto-status-badge <?php echo esc_attr( $course->post_status ); ?>">
						<?php echo esc_html( ucfirst( $course->post_status ) ); ?>
					</span>
					<a href="<?php echo esc_url( get_edit_post_link( $course->ID ) ); ?>" class="wb-cpto-edit" target="_blank">
						<?php esc_html_e( 'Edit', 'wb-cpt-taxonomy-order' ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Render the lessons-in-course ordering panel.
	 *
	 * @param int $course_id Selected course ID.
	 */
	private function render_lessons_panel( $course_id ) {
		$cpts    = self::get_managed_post_types();
		$courses = get_posts(
			array(
				'post_type'      => $cpts['course'],
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		?>
		<form method="get" class="wb-cpto-filters">
			<input type="hidden" name="page" value="wb-cpt-order-learndash" />
			<input type="hidden" name="mode" value="lessons" />
			<div class="wb-cpto-filter-row">
				<label for="course_id"><?php esc_html_e( 'Course:', 'wb-cpt-taxonomy-order' ); ?></label>
				<select name="course_id" id="course_id" onchange="this.form.submit()">
					<option value=""><?php esc_html_e( '— Select Course —', 'wb-cpt-taxonomy-order' ); ?></option>
					<?php foreach ( $courses as $c ) : ?>
						<option value="<?php echo esc_attr( $c->ID ); ?>" <?php selected( $course_id, $c->ID ); ?>>
							<?php echo esc_html( $c->post_title ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
		</form>
		<?php
		if ( ! $course_id ) {
			echo '<div class="wb-cpto-notice"><p>' . esc_html__( 'Select a course to see its lessons.', 'wb-cpt-taxonomy-order' ) . '</p></div>';
			return;
		}

		$lessons = $this->get_lessons_in_course( $course_id );

		if ( empty( $lessons ) ) {
			echo '<div class="wb-cpto-notice"><p>' . esc_html__( 'No lessons found for this course.', 'wb-cpt-taxonomy-order' ) . '</p></div>';
			return;
		}

		$builder_managed = self::is_builder_managed( $course_id );
		$list_id         = $builder_managed ? 'wb-cpto-readonly' : 'wb-cpto-sortable';

		if ( $builder_managed ) {
			?>
			<div class="notice notice-info inline">
				<p>
					<strong><?php esc_html_e( 'LearnDash Course Builder is enabled for this course.', 'wb-cpt-taxonomy-order' ); ?></strong>
					<?php
					printf(
						/* translators: %s: link to the course builder */
						' ' . esc_html__( 'Lesson order is managed by the Course Builder. %s to reorder.', 'wb-cpt-taxonomy-order' ),
						'<a href="' . esc_url( admin_url( 'post.php?post=' . $course_id . '&action=edit#sfwd-courses_course_builder' ) ) . '" target="_blank">' . esc_html__( 'Open Course Builder', 'wb-cpt-taxonomy-order' ) . '</a>'
					);
					?>
				</p>
			</div>
			<?php
		} else {
			?>
			<p class="description"><?php esc_html_e( 'Drag and drop to reorder lessons. Saved automatically.', 'wb-cpt-taxonomy-order' ); ?></p>
			<div class="wb-cpto-status"></div>
			<?php
		}
		?>
		<ul id="<?php echo esc_attr( $list_id ); ?>" class="wb-cpto-list<?php echo $builder_managed ? ' wb-cpto-readonly' : ''; ?>" data-mode="lessons" data-course-id="<?php echo esc_attr( $course_id ); ?>">
			<?php foreach ( $lessons as $i => $lesson ) : ?>
				<li class="wb-cpto-item" data-id="<?php echo esc_attr( $lesson->ID ); ?>">
					<?php if ( ! $builder_managed ) : ?>
						<span class="wb-cpto-handle">☰</span>
					<?php endif; ?>
					<span class="wb-cpto-order"><?php echo esc_html( $i + 1 ); ?></span>
					<span class="wb-cpto-title"><?php echo esc_html( $lesson->post_title ); ?></span>
					<span class="wb-cpto-status-badge <?php echo esc_attr( $lesson->post_status ); ?>">
						<?php echo esc_html( ucfirst( $lesson->post_status ) ); ?>
					</span>
					<a href="<?php echo esc_url( get_edit_post_link( $lesson->ID ) ); ?>" class="wb-cpto-edit" target="_blank">
						<?php esc_html_e( 'Edit', 'wb-cpt-taxonomy-order' ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Render the topics-in-lesson ordering panel.
	 *
	 * @param int $course_id Selected course ID.
	 * @param int $lesson_id Selected lesson ID.
	 */
	private function render_topics_panel( $course_id, $lesson_id ) {
		$cpts    = self::get_managed_post_types();
		$courses = get_posts(
			array(
				'post_type'      => $cpts['course'],
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$lessons = $course_id ? $this->get_lessons_in_course( $course_id ) : array();
		?>
		<form method="get" class="wb-cpto-filters">
			<input type="hidden" name="page" value="wb-cpt-order-learndash" />
			<input type="hidden" name="mode" value="topics" />

			<div class="wb-cpto-filter-row">
				<label for="course_id"><?php esc_html_e( 'Course:', 'wb-cpt-taxonomy-order' ); ?></label>
				<select name="course_id" id="course_id" onchange="this.form.submit()">
					<option value=""><?php esc_html_e( '— Select Course —', 'wb-cpt-taxonomy-order' ); ?></option>
					<?php foreach ( $courses as $c ) : ?>
						<option value="<?php echo esc_attr( $c->ID ); ?>" <?php selected( $course_id, $c->ID ); ?>>
							<?php echo esc_html( $c->post_title ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<?php if ( $course_id && ! empty( $lessons ) ) : ?>
				<div class="wb-cpto-filter-row">
					<label for="lesson_id"><?php esc_html_e( 'Lesson:', 'wb-cpt-taxonomy-order' ); ?></label>
					<select name="lesson_id" id="lesson_id" onchange="this.form.submit()">
						<option value=""><?php esc_html_e( '— Select Lesson —', 'wb-cpt-taxonomy-order' ); ?></option>
						<?php foreach ( $lessons as $l ) : ?>
							<option value="<?php echo esc_attr( $l->ID ); ?>" <?php selected( $lesson_id, $l->ID ); ?>>
								<?php echo esc_html( $l->post_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			<?php endif; ?>
		</form>
		<?php
		if ( ! $course_id ) {
			echo '<div class="wb-cpto-notice"><p>' . esc_html__( 'Select a course.', 'wb-cpt-taxonomy-order' ) . '</p></div>';
			return;
		}

		if ( ! $lesson_id ) {
			echo '<div class="wb-cpto-notice"><p>' . esc_html__( 'Select a lesson to see its topics.', 'wb-cpt-taxonomy-order' ) . '</p></div>';
			return;
		}

		$topics = $this->get_topics_in_lesson( $course_id, $lesson_id );

		if ( empty( $topics ) ) {
			echo '<div class="wb-cpto-notice"><p>' . esc_html__( 'No topics found for this lesson.', 'wb-cpt-taxonomy-order' ) . '</p></div>';
			return;
		}

		$builder_managed = self::is_builder_managed( $course_id );
		$list_id         = $builder_managed ? 'wb-cpto-readonly' : 'wb-cpto-sortable';

		if ( $builder_managed ) {
			?>
			<div class="notice notice-info inline">
				<p>
					<strong><?php esc_html_e( 'LearnDash Course Builder is enabled for this course.', 'wb-cpt-taxonomy-order' ); ?></strong>
					<?php
					printf(
						/* translators: %s: link to the course builder */
						' ' . esc_html__( 'Topic order is managed by the Course Builder. %s to reorder.', 'wb-cpt-taxonomy-order' ),
						'<a href="' . esc_url( admin_url( 'post.php?post=' . $course_id . '&action=edit#sfwd-courses_course_builder' ) ) . '" target="_blank">' . esc_html__( 'Open Course Builder', 'wb-cpt-taxonomy-order' ) . '</a>'
					);
					?>
				</p>
			</div>
			<?php
		} else {
			?>
			<p class="description"><?php esc_html_e( 'Drag and drop to reorder topics. Saved automatically.', 'wb-cpt-taxonomy-order' ); ?></p>
			<div class="wb-cpto-status"></div>
			<?php
		}
		?>
		<ul id="<?php echo esc_attr( $list_id ); ?>" class="wb-cpto-list<?php echo $builder_managed ? ' wb-cpto-readonly' : ''; ?>"
			data-mode="topics"
			data-course-id="<?php echo esc_attr( $course_id ); ?>"
			data-lesson-id="<?php echo esc_attr( $lesson_id ); ?>">
			<?php foreach ( $topics as $i => $topic ) : ?>
				<li class="wb-cpto-item" data-id="<?php echo esc_attr( $topic->ID ); ?>">
					<?php if ( ! $builder_managed ) : ?>
						<span class="wb-cpto-handle">☰</span>
					<?php endif; ?>
					<span class="wb-cpto-order"><?php echo esc_html( $i + 1 ); ?></span>
					<span class="wb-cpto-title"><?php echo esc_html( $topic->post_title ); ?></span>
					<span class="wb-cpto-status-badge <?php echo esc_attr( $topic->post_status ); ?>">
						<?php echo esc_html( ucfirst( $topic->post_status ) ); ?>
					</span>
					<a href="<?php echo esc_url( get_edit_post_link( $topic->ID ) ); ?>" class="wb-cpto-edit" target="_blank">
						<?php esc_html_e( 'Edit', 'wb-cpt-taxonomy-order' ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Get lessons that belong to a course, ordered by current menu_order.
	 *
	 * Uses LearnDash's own steps where possible to honor shared steps mode.
	 *
	 * @param int $course_id Course ID.
	 * @return array Array of WP_Post objects.
	 */
	private function get_lessons_in_course( $course_id ) {
		$cpts = self::get_managed_post_types();

		// Try LearnDash's own helper for shared-steps awareness.
		if ( function_exists( 'learndash_course_get_steps_by_type' ) ) {
			$ids = learndash_course_get_steps_by_type( $course_id, $cpts['lesson'] );
			if ( ! empty( $ids ) && is_array( $ids ) ) {
				$ids = array_map( 'absint', $ids );
				return array_values(
					array_filter(
						array_map(
							function ( $id ) {
								return get_post( $id );
							},
							$ids
						)
					)
				);
			}
		}

		// Fallback to direct meta query.
		return get_posts(
			array(
				'post_type'      => $cpts['lesson'],
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'     => 'course_id',
						'value'   => $course_id,
						'compare' => '=',
					),
				),
			)
		);
	}

	/**
	 * Get topics under a specific lesson within a course.
	 *
	 * @param int $course_id Course ID.
	 * @param int $lesson_id Lesson ID.
	 * @return array Array of WP_Post objects.
	 */
	private function get_topics_in_lesson( $course_id, $lesson_id ) {
		$cpts = self::get_managed_post_types();

		if ( function_exists( 'learndash_course_get_children_of_step' ) ) {
			$ids = learndash_course_get_children_of_step( $course_id, $lesson_id, $cpts['topic'] );
			if ( ! empty( $ids ) && is_array( $ids ) ) {
				$ids = array_map( 'absint', $ids );
				return array_values(
					array_filter(
						array_map(
							function ( $id ) {
								return get_post( $id );
							},
							$ids
						)
					)
				);
			}
		}

		return get_posts(
			array(
				'post_type'      => $cpts['topic'],
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => 'course_id',
						'value' => $course_id,
					),
					array(
						'key'   => 'lesson_id',
						'value' => $lesson_id,
					),
				),
			)
		);
	}

	/**
	 * AJAX handler: save order.
	 */
	public function ajax_save_order() {
		check_ajax_referer( 'wb_cpto_ld_save_order', 'nonce' );

		if ( ! current_user_can( 'edit_courses' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wb-cpt-taxonomy-order' ) ) );
		}

		$mode      = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		$order     = isset( $_POST['order'] ) ? array_values( array_map( 'absint', (array) $_POST['order'] ) ) : array();
		$course_id = isset( $_POST['course_id'] ) ? absint( $_POST['course_id'] ) : 0;
		$lesson_id = isset( $_POST['lesson_id'] ) ? absint( $_POST['lesson_id'] ) : 0;

		if ( empty( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'No order data received.', 'wb-cpt-taxonomy-order' ) ) );
		}

		switch ( $mode ) {
			case 'courses':
				$this->save_courses_order( $order );
				break;

			case 'lessons':
				if ( ! $course_id ) {
					wp_send_json_error( array( 'message' => __( 'Missing course context.', 'wb-cpt-taxonomy-order' ) ) );
				}
				if ( self::is_builder_managed( $course_id ) ) {
					wp_send_json_error(
						array(
							'message' => __( 'This course is managed by the LearnDash Course Builder. Use the builder to reorder lessons.', 'wb-cpt-taxonomy-order' ),
						)
					);
				}
				$this->save_lessons_order( $course_id, $order );
				break;

			case 'topics':
				if ( ! $course_id || ! $lesson_id ) {
					wp_send_json_error( array( 'message' => __( 'Missing course/lesson context.', 'wb-cpt-taxonomy-order' ) ) );
				}
				if ( self::is_builder_managed( $course_id ) ) {
					wp_send_json_error(
						array(
							'message' => __( 'This course is managed by the LearnDash Course Builder. Use the builder to reorder topics.', 'wb-cpt-taxonomy-order' ),
						)
					);
				}
				$this->save_topics_order( $course_id, $lesson_id, $order );
				break;

			default:
				wp_send_json_error( array( 'message' => __( 'Invalid mode.', 'wb-cpt-taxonomy-order' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Order saved.', 'wb-cpt-taxonomy-order' ) ) );
	}

	/**
	 * Save courses order: just menu_order on the course CPT.
	 *
	 * @param array $order Ordered array of course IDs.
	 */
	private function save_courses_order( $order ) {
		global $wpdb;

		foreach ( $order as $position => $post_id ) {
			$wpdb->update(
				$wpdb->posts,
				array( 'menu_order' => $position + 1 ),
				array( 'ID' => $post_id ),
				array( '%d' ),
				array( '%d' )
			);
			clean_post_cache( $post_id );
		}
	}

	/**
	 * Save lessons order within a course.
	 *
	 * Strategy: load current hierarchical steps via LDLMS_Factory_Post,
	 * reorder the lesson keys, preserve sub-trees (topics/quizzes), and write
	 * back with set_steps(). LearnDash's set_steps() updates menu_order on all
	 * descendants and saves the meta atomically, so the Course Builder reflects
	 * the new order immediately.
	 *
	 * @param int   $course_id Course ID.
	 * @param array $order     Ordered array of lesson IDs.
	 */
	private function save_lessons_order( $course_id, $order ) {
		$cpts = self::get_managed_post_types();

		if ( ! class_exists( 'LDLMS_Factory_Post' ) ) {
			$this->fallback_menu_order( $order );
			return;
		}

		$course_steps_obj = LDLMS_Factory_Post::course_steps( $course_id );
		if ( ! $course_steps_obj ) {
			$this->fallback_menu_order( $order );
			return;
		}

		$h = $course_steps_obj->get_steps( 'h' );
		if ( ! is_array( $h ) ) {
			$h = array();
		}

		$lesson_slug = $cpts['lesson'];
		$existing    = isset( $h[ $lesson_slug ] ) && is_array( $h[ $lesson_slug ] ) ? $h[ $lesson_slug ] : array();

		// Rebuild lesson list in the new order, preserving each lesson's child sub-tree.
		$new_lessons = array();
		foreach ( $order as $lesson_id ) {
			$lesson_id = absint( $lesson_id );
			if ( ! $lesson_id ) {
				continue;
			}

			if ( isset( $existing[ $lesson_id ] ) ) {
				$new_lessons[ $lesson_id ] = $existing[ $lesson_id ];
			} else {
				// Lesson exists in DB (course_id meta) but not yet in steps tree.
				$new_lessons[ $lesson_id ] = array();
			}
		}

		// Append any lessons that exist in the steps tree but were not in our reorder set
		// (defensive: shouldn't normally happen since UI lists them all).
		foreach ( $existing as $lid => $children ) {
			if ( ! isset( $new_lessons[ $lid ] ) ) {
				$new_lessons[ $lid ] = $children;
			}
		}

		$h[ $lesson_slug ] = $new_lessons;

		$course_steps_obj->set_steps( $h, true );

		clean_post_cache( $course_id );
	}

	/**
	 * Save topics order within a lesson within a course.
	 *
	 * @param int   $course_id Course ID.
	 * @param int   $lesson_id Lesson ID.
	 * @param array $order     Ordered array of topic IDs.
	 */
	private function save_topics_order( $course_id, $lesson_id, $order ) {
		$cpts = self::get_managed_post_types();

		if ( ! class_exists( 'LDLMS_Factory_Post' ) ) {
			$this->fallback_menu_order( $order );
			return;
		}

		$course_steps_obj = LDLMS_Factory_Post::course_steps( $course_id );
		if ( ! $course_steps_obj ) {
			$this->fallback_menu_order( $order );
			return;
		}

		$h = $course_steps_obj->get_steps( 'h' );
		if ( ! is_array( $h ) ) {
			$h = array();
		}

		$lesson_slug = $cpts['lesson'];
		$topic_slug  = $cpts['topic'];

		if ( ! isset( $h[ $lesson_slug ][ $lesson_id ] ) || ! is_array( $h[ $lesson_slug ][ $lesson_id ] ) ) {
			// Lesson is not yet represented in the steps tree; bootstrap it.
			$h[ $lesson_slug ][ $lesson_id ] = array(
				$topic_slug                          => array(),
				learndash_get_post_type_slug( 'quiz' ) => array(),
			);
		}

		$existing_topics = isset( $h[ $lesson_slug ][ $lesson_id ][ $topic_slug ] ) && is_array( $h[ $lesson_slug ][ $lesson_id ][ $topic_slug ] )
			? $h[ $lesson_slug ][ $lesson_id ][ $topic_slug ]
			: array();

		$new_topics = array();
		foreach ( $order as $topic_id ) {
			$topic_id = absint( $topic_id );
			if ( ! $topic_id ) {
				continue;
			}

			$new_topics[ $topic_id ] = isset( $existing_topics[ $topic_id ] ) ? $existing_topics[ $topic_id ] : array();
		}

		// Defensive: keep any topics not in our reorder set.
		foreach ( $existing_topics as $tid => $children ) {
			if ( ! isset( $new_topics[ $tid ] ) ) {
				$new_topics[ $tid ] = $children;
			}
		}

		$h[ $lesson_slug ][ $lesson_id ][ $topic_slug ] = $new_topics;

		$course_steps_obj->set_steps( $h, true );

		clean_post_cache( $course_id );
	}

	/**
	 * Fallback: just write menu_order if LD steps API isn't available.
	 *
	 * @param array $order Ordered post IDs.
	 */
	private function fallback_menu_order( $order ) {
		global $wpdb;

		foreach ( $order as $position => $post_id ) {
			$wpdb->update(
				$wpdb->posts,
				array( 'menu_order' => $position + 1 ),
				array( 'ID' => $post_id ),
				array( '%d' ),
				array( '%d' )
			);
			clean_post_cache( $post_id );
		}
	}
}
