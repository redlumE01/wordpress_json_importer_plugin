<?php

	/**
	 * Plugin Name:       Redlum JSON Feed Import
	 * Description:       Concept plugin for importing JSON feed
	 * Version:           1.0.0
	 * Requires at least: 5.2
	 * Requires PHP:      7.2
	 * Author:            Erik Mulder
	 * Author URI:        https://redlum-media.com/
	 * License:           GPL v2 or later
	 * Text Domain:       redlum_json_
	 * Domain Path:       /languages
	 */

	if ( ! defined( 'WPINC' ) ) {die;}

	define( 'NEWCPT', 'json_cpt' );
	define( 'NEWTAX', 'jcat' );
	define( 'RESTCATURL', '/wp-json/wp/v2/categories' );
	define( 'RESTPOSTURL', '/wp-json/wp/v2/posts' );

	function json_cpt_tax() {
		register_taxonomy( NEWTAX, NEWCPT, array(
			'label'        => __( 'jCat' ),
			'rewrite'      => array( 'slug' => NEWTAX ),
			'hierarchical' => true,
		) );
	}

	function json_cpt() {

		$labels = array(
			'name'               => 'Imported JSON CPT',
			'singular_name'      => 'Imported JSON CPT',
			'menu_name'          => 'Imported JSON CPT',
			'name_admin_bar'     => 'Imported JSON CPT'
		);

		$args = array(
			'labels'             => $labels,
			'description'        => 'Imported JSON CPT',
			'public'             => false,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'query_var'          => true,
			'capability_type'    => 'page',
			'has_archive'        => true,
			'hierarchical'       => true,
			'menu_position'      => 5,
			'menu_icon'          => 'dashicons-database',
			'taxonomies'         => array(NEWTAX),
			'supports'           =>
				array(
					'title',
					'editor',
					'custom-fields'
				)
		);

		register_post_type( NEWCPT, $args );

	}

	function json_feed_page() {
		add_menu_page(
			'JSON Feed Importer',
			'JSON Feed Importer',
			'manage_options',
			'json_feed_list',
			'json_feed_page_callback',
			'dashicons-database-import'
		);
	}

	add_action('init', 'json_cpt_tax', 0);
	add_action('init', NEWCPT);
	add_action( 'admin_menu', 'json_feed_page' );

	function get_json_data() {

		$catRestDecode = json_decode(file_get_contents(RESTCATURL),true);

		// 1. WP IMPORT - categories
		foreach ($catRestDecode as $category) {
			if ( term_exists( $category['slug'], NEWCPT ) === null ) {

				$cid = wp_insert_term($category['name'],
					NEWTAX,
					array(
						'description' => '',
						'slug' => $category['slug']
					)
				);

				if ( ! is_wp_error( $cid ) ){
					add_term_meta( $cid['term_id'], "origin_id" , $category['id']);
					add_term_meta( $cid['term_id'], "origin_parent" , $category['parent']);
					add_term_meta( $cid['term_id'], "origin_description" , $category['description']);
				}

			}
		}

		// 2. WP UPDATE - Imported categories
		$terms = get_terms( array('taxonomy' => NEWTAX, 'hide_empty' => false));

		foreach ($terms as $el) {

			$originParent = get_term_meta($el->term_id)['origin_parent'][0];
			$originDescription = get_term_meta($el->term_id)['origin_description'][0];

			// get new term_id
			$args = array(
				'hide_empty' => false,
				'meta_query' => array(
					array(
						'key'     => 'origin_id',
						'value'   => $originParent,
						'compare' => '='
					)
				),
				'taxonomy'  => NEWTAX,
			);

			// get new id
			$newParentCatId = get_terms($args)[0]->term_id;

			$args = array(
				'description' => $originDescription,
				'parent' => $newParentCatId,
				'slug' => $el->slug, 'term_group' => 0,
				'name' => $el->name
			);

			wp_update_term( $el->term_id, NEWTAX,$args);

		}

		// 3. WP IMPORT - Imported POSTS
		$importPostsDecode = json_decode(file_get_contents(RESTPOSTURL),true);

		foreach ($importPostsDecode as $el) {

			$elArgs = array(
				'post_title'    => $el['title']['rendered'],
				'post_content'  => $el['content']['rendered'],
				'post_status'   => 'publish',
				'post_author'   => 1,
				'post_type' 	=> NEWCPT,
				'meta_input'   => array(
					'origin_catgories' => $el['categories']
				),
			);

			wp_insert_post( $elArgs );
		}

		// 4. WP IMPORT - JSON CPT to New Cat
		$args = array('post_type' => NEWCPT);
		$query = new WP_Query( $args );

		foreach ($query->posts as $el) {

			// get origin categories
			$metaData = unserialize(get_post_meta( $el->ID)['origin_catgories'][0]);

			// get new categories
			$newCatParents = [];

			foreach ($metaData as $metaDataEl) {

				$args = array(
					'hide_empty' => false,
					'meta_query' => array(
						array(
							'key'     => 'origin_id',
							'value'   => $metaDataEl,
							'compare' => '='
						)
					),
					'taxonomy'  => NEWTAX,
				);

				// get new id
				$newParentCatId = get_terms($args)[0]->term_id;
				array_push($newCatParents, $newParentCatId);
			}


			wp_set_post_terms( $el->ID, $newCatParents,NEWTAX );

		}

		wp_reset_postdata();

	}

	function the_form_response() {

		if (isset($_POST['redlum_add_user_meta_nonce'] ) && wp_verify_nonce( $_POST['redlum_add_user_meta_nonce'], 'redlum_add_user_meta_form_nonce') ) {
			get_json_data();
			$admin_notice = "success";
			custom_redirect( $admin_notice, $_POST );
			exit;
		}

		else {
			wp_die( __( 'Invalid nonce specified', 'JSON FEED' ), __( 'Error', 'JSON FEED' ),
				array(
					'response' 	=> 403,
					'back_link' => 'admin.php?page=json_feed_list',
				));
		}
	}

	add_action( 'admin_post_redlum_form_response', 'the_form_response');

	function custom_redirect( $admin_notice, $response ) {
		wp_redirect( esc_url_raw(
				add_query_arg(
					array('nds_admin_add_notice' => $admin_notice, 'nds_response' => $response), admin_url('admin.php?page=json_feed_list')
				)
			)
		);
	}

	add_action( 'admin_notices', 'print_plugin_admin_notices');

	function print_plugin_admin_notices() {
		if ( isset( $_REQUEST['nds_admin_add_notice'] ) ) {
			if( $_REQUEST['nds_admin_add_notice'] === "success") {
				$html =	'<div class="notice notice-success is-dismissible"><p><strong>The import was successful. </strong></p><br>';
				echo $html;
			}
		}
		else {
			return;
		}

	}

	function json_feed_page_callback() {

		$nds_add_meta_nonce = wp_create_nonce( 'redlum_add_user_meta_form_nonce' );

		?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" id="nds_add_user_meta_form" >
			<input type="hidden" name="action" value="redlum_form_response">
			<input type="hidden" name="redlum_add_user_meta_nonce" value="<?php echo $nds_add_meta_nonce ?>" />
			<p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="Trigger JSON FEED import"></p>
		</form>
		<?php

	}
