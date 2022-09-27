<?php
/**
 * Class CFF_Post_Record
 *
 * Primarily used for resizing and storing images, this class
 * performs certain tasks with data for a single post.
 *
 *
 * @since 2.0/4.0
 */
namespace CustomFacebookFeed;
use CustomFacebookFeed\SB_Facebook_Data_Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


class CFF_Post_Record{
	/**
	 * @var string
	 */
	private $api_post_id;

	/**
	 * @var array
	 */
	private $api_data;

	/**
	 * @var string
	 */
	private $db_id;

	/**
	 * @var bool|int
	 */
	private $images_done;

	/**
	 * @var array
	 */
	private $resized_image_data;

	private $feed_id;

	private $connected_account;

	/**
	 * @var object|SB_Facebook_Data_Encryption
	 */
	private $encryption;

	/**
	 * SB_Instagram_Post constructor.
	 *
	 * @param string $instagram_post_id from the Instagram API
	 */
	public function __construct( $api_data, $feed_id, $connected_account = false ) {
		global $wpdb;
		$table_name = $wpdb->prefix . CFF_POSTS_TABLE;

		$this->api_data = $api_data;
		$this->api_post_id = CFF_Parse_Pro::get_post_id( $api_data );

		$feed_id_match = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table_name WHERE facebook_id = %s LIMIT 1", $this->api_post_id ), ARRAY_A );

		$this->db_id = ! empty( $feed_id_match ) ? $feed_id_match[0]['id'] : '';
		$this->images_done = ! empty( $feed_id_match ) && isset( $feed_id_match[0]['images_done'] ) ? $feed_id_match[0]['images_done'] === '1' : 0;
		$this->feed_id = $feed_id;
		$this->connected_account = $connected_account;
		$this->encryption = new SB_Facebook_Data_Encryption();
	}

	/**
	 * Whether or not this post has already been saved in the custom table
	 *
	 * @return bool
	 *
	 * @since 2.0/4.0
	 */
	public function exists_in_posts_table() {
		return ! empty( $this->db_id );
	}

	/**
	 * Whether or not resized image files have already been recorded as being created
	 * in the database table
	 *
	 * @return bool|int
	 *
	 * @since 2.0/4.0
	 */
	public function images_done_resizing() {
		return (int)$this->images_done === 1;
	}

	public function get_resized_image_data() {
		return $this->resized_image_data;
	}

	public function resize_and_save_image( $image_sizes, $upload_dir ) {
		if ( ! isset( $this->api_post_id ) ) {
			return false;
		}
		$image_source_set    = CFF_Parse_Pro::get_media_src_set( $this->api_data, $this->connected_account );

		$new_file_name       = $this->api_post_id;

		// the process is considered a success if one image is successfully resized
		$one_successful_image_resize = false;

		foreach ( $image_sizes as $image_size ) {

			$i = 0;
			foreach ( $image_source_set as $image_file_to_resize ) {
				if ($i < 4) {

					$largest_resolution = 0;
					foreach ( $image_file_to_resize as $resolution => $image_url ) {
						if ( $resolution > $largest_resolution ) {
							$largest_resolution = $resolution;
						}

					}

					$largest_resolution_image_file = $image_file_to_resize[ $largest_resolution ];

					$suffix = $image_size;

					$this_image_file_name = $new_file_name . '-' . $i . '-' .  $suffix . '.jpg';

					$image_editor = wp_get_image_editor( $largest_resolution_image_file );
					// not uncommon for the image editor to not work using it this way
					if ( ! is_wp_error( $image_editor ) ) {
						$sizes = $image_editor->get_size();

						$image_editor->resize( $image_size, null );

						$full_file_name = trailingslashit( $upload_dir ) . $this_image_file_name;

						$saved_image = $image_editor->save( $full_file_name );

						if ( ! $saved_image ) {
							\cff_main_pro()->cff_error_reporter->add_error( 'image_editor_save', array(
								__( 'Error saving edited image.', 'custom-facebook-feed' ),
								$full_file_name
							) );
						} else {
							$one_successful_image_resize = true;
						}
					} else {

						$message = __( 'Error editing image.', 'custom-facebook-feed' );
						if ( isset( $image_editor ) && isset( $image_editor->errors ) ) {
							foreach ( $image_editor->errors as $key => $item ) {
								$message .= ' ' . $key . '- ' . $item[0] . ' |';
							}
						}
						\cff_main_pro()->cff_error_reporter->add_error( 'image_editor', array( $new_file_name, $message ) );
					}
				}

				$i++;
			}

		}

		if ( $one_successful_image_resize ) {
			$aspect_ratio = round( $sizes['width'] / $sizes['height'], 2 );
			$media_id = $new_file_name;
			//$this->add_resized_image_to_obj_array( 'id', $new_file_name );
		} else {
			$aspect_ratio = 1;
			$media_id = 'error';
		}

		$this->update_cff_posts( array(
			'media_id'     => $media_id,
			'sizes'        => CFF_Utils::cff_json_encode( $image_sizes ),
			'aspect_ratio' => $aspect_ratio,
			'images_done'  => 1
		) );

	}

	/**
	 * Controls whether or not the database record will be updated for this post.
	 * Called after images are successfully created.
	 *
	 * @param bool $update_last_requested
	 * @param bool $transient_name
	 * @param array $image_sizes
	 * @param string $upload_dir
	 * @param string $upload_url
	 * @param bool $timestamp_for_update
	 *
	 * @return bool
	 *
	 * @since 2.0/4.0
	 */
	public function update_db_data( $update_last_requested, $transient_name, $image_sizes, $upload_dir, $upload_url, $timestamp_for_update = false ) {

	}

	/**
	 * Updates columns that need to be updated in the posts types table.
	 * Called after images successfully resized and if any information
	 * needs to be updated.
	 *
	 * @param array $to_update assoc array of columns and values to update
	 *
	 * @since 2.0/4.0
	 */
	public function update_cff_posts( $to_update ) {
		global $wpdb;
		$table_name = $wpdb->prefix . CFF_POSTS_TABLE;

		$data = $to_update;
		$format = array( '%s', '%s', '%d', '%d' );
		$where = array( 'id' => $this->db_id );
		$where_format = array( '%d' );
		$wpdb->update( $table_name, $data, $where, $format, $where_format );
	}

	/**
	 * Checks database for matching record for post and feed ID.
	 * There shouldn't be duplicate records
	 *
	 * @param string $transient_name
	 *
	 * @return bool
	 *
	 * @since 2.0/4.1
	 */
	public function exists_in_feeds_posts_table() {
		global $wpdb;
		$table_name = $wpdb->prefix . CFF_FEEDS_POSTS_TABLE;
		$db_id = $this->db_id;
		$results = $wpdb->get_results( $wpdb->prepare( "SELECT feed_id FROM $table_name WHERE id = %s AND feed_id = %s LIMIT 1", $db_id, $this->feed_id ), ARRAY_A );
		return isset( $results[0]['feed_id'] );
	}


	/**
	 * Used to save information about the post before image resizing is done to
	 * prevent a potentially storing multiple entries for the same post
	 *
	 *
	 * @since 2.0/4.0
	 */
	public function save_new_record() {
		global $wpdb;

		$db_data = $this->get_db_data();

		$table_name = $wpdb->prefix . CFF_POSTS_TABLE;
		$data = array(
			'facebook_id' => $db_data['facebook_id'],
			'created_on' => $db_data['created_on'],
			'last_requested' => $db_data['last_requested'],
			'time_stamp' => $db_data['time_stamp'],
			'json_data' => $this->encryption->encrypt( CFF_Utils::cff_json_encode( $db_data['json_data'] ) ),
			'media_id' => $db_data['media_id'],
			'sizes' => $db_data['sizes'],
			'aspect_ratio' => $db_data['aspect_ratio'],
			'images_done' => $db_data['images_done']
		);
		$format = array(
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%d',
			'%d'
		);
		$error = $wpdb->insert( $table_name, $data, $format );

		if ( $error !== false ) {
			$insert_id = $wpdb->insert_id;

			$this->db_id = $insert_id;
			$this->insert_cff_feeds_posts();
		} else {
			// log error
		}
	}

	/**
	 * Add a record of this post being used for the specified transient name (feed id)
	 *
	 * @param string $transient_name
	 *
	 * @return int
	 *
	 * @since 2.0/4.0
	 */
	public function insert_cff_feeds_posts() {
		global $wpdb;
		$table_name = $wpdb->prefix . CFF_FEEDS_POSTS_TABLE;

		if ( ! empty( $this->db_id ) ) {
			$data = array(
				'id' => $this->db_id,
				'feed_id' => $this->feed_id
			);
			$format = array(
				'%d',
				'%s'
			);
			$error = $wpdb->insert( $table_name, $data, $format );
		} else {
			\cff_main_pro()->cff_error_reporter->add_error( 'database_insert_post', array( __( 'Error inserting post.', 'custom-facebook-feed' ), __( 'No database ID.', 'custom-facebook-feed' ) ) );
			return false;
		}


		if ( $error !== false ) {
			return $wpdb->insert_id;
		} else {
			$error = $wpdb->last_error;
			$query = $wpdb->last_query;

			\cff_main_pro()->cff_error_reporter->add_error( 'database_insert_post', array( __( 'Error inserting post.', 'custom-facebook-feed' ), $error . '<br><code>' . $query . '</code>' ) );
		}
	}

	/**
	 * Uses the saved json for the post to be used for updating records
	 *
	 *
	 * @return array
	 *
	 * @since 2.0/4.0
	 */
	private function get_db_data() {
		if ( is_string( $this->api_data ) && $this->encryption->decrypt( $this->api_data ) ) {
			$api_data = $this->encryption->decrypt( $this->api_data );
		} else {
			$api_data = $this->api_data;
		}
		$db_data = array(
			'facebook_id' => $this->api_post_id,
			'created_on' => date( 'Y-m-d H:i:s' ),
			'time_stamp' => date( 'Y-m-d H:i:s', CFF_Parse_Pro::get_timestamp( $this->api_data ) ),
			'last_requested' => date( 'Y-m-d H:i:s' ),
			'json_data' =>  CFF_Utils::cff_json_encode( $api_data ) ,
			'media_id' => '',
			'sizes' => '{}',
			'aspect_ratio' => 1,
			'images_done' => 0
		);

		return $db_data;
	}

	/**
	 * If a record hasn't been made for this transient name/feed id,
	 * make a record
	 *
	 * @param string $feed_id
	 *
	 * @since 2.0/4.0
	 */
	private function maybe_add_feed_id( $feed_id ) {
/*
		if ( empty( $this->instagram_post_id ) ) {
			return;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . SBI_INSTAGRAM_FEEDS_POSTS;
		// the number is removed from the transient name for backwards compatibilty.
		$feed_id_array = explode( '#', $feed_id );
		$feed_id = $feed_id_array[0];

		$feed_id_match = $wpdb->get_col( $wpdb->prepare( "SELECT feed_id FROM $table_name WHERE feed_id = %s AND instagram_id = %s", $feed_id, $this->instagram_post_id ) );

		if ( ! isset( $feed_id_match[0] ) ) {
			$entry_data = array(
				$this->db_id,
				"'" . esc_sql( $this->instagram_post_id ) . "'",
				"'" . esc_sql( $feed_id ) . "'"
			);
			$entry_string = implode( ',',$entry_data );
			$error = $wpdb->query( "INSERT INTO $table_name
      		(id,instagram_id,feed_id) VALUES ($entry_string);" );
		}
*/
	}
}
