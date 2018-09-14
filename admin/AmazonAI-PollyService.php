<?php
/**
 * Class providers logic responsible for connecting with Amazon Polly Service
 * and converting post text to audio.
 *
 * @link       amazon.com
 * @since      2.0.3
 *
 * @package    Amazonpolly
 * @subpackage Amazonpolly/admin
 */

class AmazonAI_PollyService {

	/**
	 * Important. Run whenever new post is being created (or updated). The method executes Amazon Polly API to create audio file.
	 *
	 * @since    1.0.0
	 */
	public function save_post( $post_id, $post, $updated ) {

		// Creating new standard common object for interacting with other methods of the plugin.
		$common = new AmazonAI_Common();
		$common->init();

		// Check if this isn't an auto save.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check to make sure this is not a new post creation.
		if ( ! $updated ) {
			return;
		}

		// Validate if this post which is being saved is one of supported types. If not, return.
		$post_types_supported = $common->get_posttypes_array();
		$post_type = get_post_type($post_id);
		if (!in_array($post_type, $post_types_supported )) {
			return;
		}

		$is_quick_edit = isset($_POST['_inline_edit']) && wp_verify_nonce($_POST['_inline_edit'], 'inlineeditnonce');
		$is_polly_nonce_ok = isset( $_POST['amazon-polly-post-nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['amazon-polly-post-nonce'] ), 'amazon-polly' );

		// Check if this post is saved in 'regular' way.
		if (  $is_polly_nonce_ok || $is_quick_edit ) {

			$is_polly_enabled = isset( $_POST['amazon_polly_enable'] );

			// Check if translation deactivation flag is enabled. If yes, disable
			// and delete all files.
			$is_translate_deactivation_enabled = isset( $_POST['amazon_ai_deactive_translation'] );
			if ( !empty($is_translate_deactivation_enabled) ) {
				$common->deactive_translation_for_post($post_id);
			}

			$is_post_id_available = isset( $post_id );
			$is_key_valid = ( get_option( 'amazon_polly_valid_keys' ) === '1' );

			// If this is quick edit, we need to check other place to see if polly was already enabled for this post.
			if ( $is_post_id_available && $is_quick_edit ) {
				if ( 1 == get_post_meta( $post_id, 'amazon_polly_enable', true)) {
					$is_polly_enabled = true;
				}
			}

			// Input var okay.
			$is_amazon_polly_voice_id_available = isset( $_POST['amazon_polly_voice_id'] );

			if ( $is_post_id_available && $is_polly_enabled && $is_key_valid && $is_amazon_polly_voice_id_available ) {

				// Getting voice and sample rate which should be used for converting post to audio.
				$voice_id = sanitize_text_field( wp_unslash( $_POST['amazon_polly_voice_id'] ) );
				$sample_rate   = get_option( 'amazon_polly_sample_rate' );

				// Cleaning text. Includes for example removing not supported characters etc.
				$clean_text    = $common->clean_text( $post_id, true, false);

				// Breaking text into smaller parts, which will be then send to Amazon Polly for conversion.
				$sentences     = $common->break_text( $clean_text );

				// We will be operating on local file system for stiching files together.
				$wp_filesystem = $common->prepare_wp_filesystem();

				// Actual invocation of method which will call Amazon Polly API and create audio file.
				$this->convert_to_audio( $post_id, $sample_rate, $voice_id, $sentences, $wp_filesystem, '' );

				// Checking what was the source language of text and updating options for translate operations.
				$source_language = $common->get_source_language();
				update_post_meta( $post_id, 'amazon_polly_transcript_' . $source_language, $clean_text );
				update_post_meta( $post_id, 'amazon_polly_transcript_source_lan', $source_language );

			}

			// It's possible that Amazon Polly was not enabled, then we make sure that the audio file
			// for the post is deleted (if existing) and options are removed.
			if ( $is_post_id_available && ! $is_polly_enabled ) {
				$common->delete_post_audio( $post_id );
				update_post_meta( $post_id, 'amazon_polly_enable', 0 );
				update_post_meta( $post_id, 'amazon_polly_audio_location', '' );
			}
		}
	}

	private function start_speech_synthesis_task($common, $post_id, $sample_rate, $voice_id, $sentences, $lang) {

		$full_text = '';

		// Iterating through each of text parts.
		foreach ( $sentences as $key => $text_content ) {

			// Remove all tags
			$text_content = strip_tags($text_content);

			// If plugin SSML support option is enabled, plugin will try to decode all SSML tags.
			$text_content = $this->ssml_support($common, $text_content);

			$full_text = $full_text . $text_content;

		}


		// Adding breaths sounds (if enabled).
		$full_text = $this->add_breaths($common, $full_text);

		// Adding special polly mark.
		$full_text = $this->add_mark_tag($common, $full_text);

		// Adding speak polly mark.
		$full_text = $this->add_speak_tags($common, $full_text);

		//Preparing Amazon Polly client object.
		$polly_client = $common->get_polly_client();

		$s3_prefix = 'asyn/';
		if ( get_option('uploads_use_yearmonth_folders') ) {
			 $s3_prefix .= get_the_date( 'Y', $post_id ) . '/' . get_the_date( 'm', $post_id ) . "/";
		}

		$file_name = 'amazon_polly_' . $post_id . $lang . '.mp3';

		//Preparing lexicons which will be used create audio.
		$lexicons       = $common->get_lexicons();
		$lexicons_array = explode( ' ', $lexicons );


		//Call Amazon Polly service.
		if ( ! empty( $lexicons ) and ( count( $lexicons_array ) > 0 ) ) {

			$result = $polly_client->startSpeechSynthesisTask(
				array(
					'OutputFormat' => 'mp3',
					'SampleRate'   => $sample_rate,
					'Text'         => $full_text,
					'TextType'     => 'ssml',
					'VoiceId'      => $voice_id,
					'OutputS3BucketName' => $common->get_s3_bucket_name(),
					'OutputS3KeyPrefix' => $s3_prefix . $file_name,
					'LexiconNames' => $lexicons_array,
				)
			);

		} else {

			$result = $polly_client->startSpeechSynthesisTask(
				array(
					'OutputFormat' => 'mp3',
					'SampleRate'   => $sample_rate,
					'Text'         => $full_text,
					'TextType'     => 'ssml',
					'VoiceId'      => $voice_id,
					'OutputS3BucketName' => $common->get_s3_bucket_name(),
					'OutputS3KeyPrefix' => $s3_prefix . $file_name
				)
			);

		}

		// Saving audio file in final destination.
		$audio_location_link = $common->get_s3_object_link($common, $post_id, $file_name);

		// This will bust the browser cache when a content revision is made.
		$audio_location_link = add_query_arg( 'version', time(), $audio_location_link );

		// We are using a hash of these values to improve the speed of queries.
		$amazon_polly_settings_hash = md5( $voice_id . $sample_rate . "s3" );

		if ( $lang == '' ) {
			update_post_meta( $post_id, 'amazon_polly_audio_link_location', $audio_location_link );
			update_post_meta( $post_id, 'amazon_polly_audio_location', "s3" );
		} else {
			update_post_meta( $post_id, 'amazon_polly_translation_' . $lang, '1' );
		}

		// Update post meta data.
		update_post_meta( $post_id, 'amazon_polly_enable', 1 );
		update_post_meta( $post_id, 'amazon_polly_voice_id', $voice_id );
		update_post_meta( $post_id, 'amazon_polly_sample_rate', $sample_rate );
		update_post_meta( $post_id, 'amazon_polly_settings_hash', $amazon_polly_settings_hash );


	}


	/**
	 * Method execute Amazon Polly API and convert content which was provided to audio file.
	 *
	 * @param           string $post_id                 ID of the posts which is being converted.
	 * @param           string $sample_rate         Sample rate for speech conversion.
	 * @param           string $voice_id                Amazon Polly voice ID.
	 * @param           string $sentences               Sentences which should be converted to audio.
	 * @param           string $wp_filesystem       Reference to WP File system variable.
	 * @param           string $lang       Language
	 * @since           1.0.0
	 */
	public function convert_to_audio( $post_id, $sample_rate, $voice_id, $sentences, $wp_filesystem, $lang ) {


		// Creating new standard common object for interacting with other methods of the plugin.
		$common = new AmazonAI_Common();
		$common->init();

		// This part will be called only in case of translate opration and converting
		// text into other languages (only then the last parameter is not empty).
		// The last parameter of method specify language for which we are creating audio
		foreach ($common->get_all_translable_languages() as $language_code) {
			if ( $language_code == $lang ) {
				$voice_id = get_option( 'amazon_polly_trans_langs_' . $language_code . '_voice' );
			}
		}

		if ( empty($voice_id) ) {
			return;
		}

		// Just in case we check if sample rate is valid, if not we will set default value.
		$sample_rate_values = array( '22050', '16000', '8000' );
		if ( ! in_array( $sample_rate, $sample_rate_values, true ) ) {
			$sample_rate = '22050';
		}

		// In case of asynchronous synthesis flow.
		$amazon_ai_asynchronous = apply_filters( '$amazon_ai_asynchronous', '' );
		if ( $amazon_ai_asynchronous ) {
			$this->start_speech_synthesis_task($common, $post_id, $sample_rate, $voice_id, $sentences, $lang);
			return;
		}


		// Preparing locations and names of temporary files which will be used.
		$upload_dir           = wp_upload_dir()['basedir'];
		$file_prefix          = 'amazon_polly_';
		$file_name            = $file_prefix . $post_id . $lang . '.mp3';
		$file_temp_full_name  = trailingslashit($upload_dir) . 'temp_' . $file_name;
		$dir_final_full_name  = trailingslashit($upload_dir);
		if ( get_option('uploads_use_yearmonth_folders') ) {
		   $dir_final_full_name .= get_the_date( 'Y', $post_id ) . '/' . get_the_date( 'm', $post_id ) . "/";
		}
		$file_final_full_name = $dir_final_full_name . $file_name;


		// Delete temporary file if already exists.
		if ( $wp_filesystem->exists( $file_temp_full_name ) ) {
			$wp_filesystem->delete( $file_temp_full_name );
		}
		// Delete final file if already exists.
		if ( $wp_filesystem->exists( $file_final_full_name ) ) {
			$wp_filesystem->delete( $file_final_full_name );
		}

		// We might be stiching multiple smaller audio files. This variable will
		// be used to detact the first part.
		$first_part = true;

		// Iterating through each of text parts.
		foreach ( $sentences as $key => $text_content ) {

			// Remove all tags
			$text_content = strip_tags($text_content);

			// Adding breaths sounds (if enabled).
			$text_content = $this->add_breaths($common, $text_content);

			// If plugin SSML support option is enabled, plugin will try to decode all SSML tags.
			$text_content = $this->ssml_support($common, $text_content);

			// Adding special polly mark.
			$text_content = $this->add_mark_tag($common, $text_content);

			// Adding speak polly mark.
			$text_content = $this->add_speak_tags($common, $text_content);

			//Preparing lexicons which will be used create audio.
			$lexicons       = $common->get_lexicons();
			$lexicons_array = explode( ' ', $lexicons );

			//Preparing Amazon Polly client object.
			$polly_client = $common->get_polly_client();

			//Call Amazon Polly service.
			if ( ! empty( $lexicons ) and ( count( $lexicons_array ) > 0 ) ) {

				$result = $polly_client->synthesizeSpeech(
					array(
						'OutputFormat' => 'mp3',
						'SampleRate'   => $sample_rate,
						'Text'         => $text_content,
						'TextType'     => 'ssml',
						'VoiceId'      => $voice_id,
						'LexiconNames' => $lexicons_array,
					)
				);
			} else {

				$result = $polly_client->synthesizeSpeech(
					array(
						'OutputFormat' => 'mp3',
						'SampleRate'   => $sample_rate,
						'Text'         => $text_content,
						'TextType'     => 'ssml',
						'VoiceId'      => $voice_id,
					)
				);
			}

			// Grab the stream and output to a file.
			$contents = $result['AudioStream']->getContents();

			// Save first part of the audio stream in the parial temporary file.
			$wp_filesystem->put_contents( $file_temp_full_name . '_part_' . $key, $contents );

			// Merge new temporary file with previous ones.
			if ( $first_part ) {
				$wp_filesystem->put_contents( $file_temp_full_name, $contents );
				$first_part = false;
			} else {
				$common->remove_id3( $file_temp_full_name . '_part_' . $key );
				$merged_file = $wp_filesystem->get_contents( $file_temp_full_name ) . $wp_filesystem->get_contents( $file_temp_full_name . '_part_' . $key );
				$wp_filesystem->put_contents( $file_temp_full_name, $merged_file );
			}

			// Deleting partial audio file.
			$wp_filesystem->delete( $file_temp_full_name . '_part_' . $key );

		}

		// Saving audio file in final destination.
		$fileHandler = $common->get_file_handler();
		$audio_location_link = $fileHandler->save($wp_filesystem, $file_temp_full_name, $dir_final_full_name, $file_final_full_name, $post_id, $file_name);

		// This will bust the browser cache when a content revision is made.
		$audio_location_link = add_query_arg( 'version', time(), $audio_location_link );

		// We are using a hash of these values to improve the speed of queries.
		$amazon_polly_settings_hash = md5( $voice_id . $sample_rate . "s3" );

		if ( $lang == '' ) {
			update_post_meta( $post_id, 'amazon_polly_audio_link_location', $audio_location_link );
			update_post_meta( $post_id, 'amazon_polly_audio_location', $fileHandler->get_type() );
		} else {
			update_post_meta( $post_id, 'amazon_polly_translation_' . $lang, '1' );
		}

		// Update post meta data.
		update_post_meta( $post_id, 'amazon_polly_enable', 1 );
		update_post_meta( $post_id, 'amazon_polly_voice_id', $voice_id );
		update_post_meta( $post_id, 'amazon_polly_sample_rate', $sample_rate );
		update_post_meta( $post_id, 'amazon_polly_settings_hash', $amazon_polly_settings_hash );

	}

	/**
	 * Method add SSML breaths tags if functionality is enabled.
	 *
	 * @param           string $common					Plugin common object.
	 * @param           string $text_content		Existing text.
	 * @since           2.5.0
	 */
	private function add_breaths($common, $text_content) {
		// Depending on the plugin configuration SSML tags for automated breaths sound will be added.
		$is_breaths_enabled = $common->is_auto_breaths_enabled();
		if ( $is_breaths_enabled ) {
			$text_content = '<amazon:auto-breaths>' . $text_content . '</amazon:auto-breaths>';
		}

		return $text_content;
	}

	/**
	 * Method decode SSML tags  if functionality is enabled.
	 *
	 * @param           string $common					Plugin common object.
	 * @param           string $text_content		Existing text.
	 * @since           2.5.0
	 */
	private function ssml_support($common, $text_content) {
		// If plugin SSML support option is enabled, plugin will try to decode all SSML tags.
		$is_ssml_enabled = $common->is_ssml_enabled();
		if ( $is_ssml_enabled ) {
			$text_content = $common->decode_ssml_tags( $text_content );
		}

		$text_content = str_replace( '**AMAZONPOLLY*SSML*BREAK*time=***500ms***SSML**', '<break time="500ms"/>', $text_content );
		$text_content = str_replace( '**AMAZONPOLLY*SSML*BREAK*time=***1s***SSML**', '<break time="500ms"/>', $text_content );

		return $text_content;
	}

	/**
	 * Method add special mark tag for output text.
	 *
	 * @param           string $common					Plugin common object.
	 * @param           string $text_content		Existing text.
	 * @since           2.5.0
	 */
	private function add_mark_tag($common, $text_content) {

		$amazon_polly_mark_value = 'wp-plugin-awslabs';
		$amazon_polly_mark_value = apply_filters( 'amazon_polly_mark_value', $amazon_polly_mark_value );

		$text_content = '<mark name="' . esc_attr( $amazon_polly_mark_value ) . '"/>' . $text_content . '';

		return $text_content;
	}

	/**
	 * Method add speak tags for output text.
	 *
	 * @param           string $common					Plugin common object.
	 * @param           string $text_content		Existing text.
	 * @since           2.5.0
	 */
	private function add_speak_tags($common, $text_content) {

		$text_content = '<speak>' . $text_content . '</speak>';

		return $text_content;
	}


		/**
		 * Batch process the post transcriptions.
		 *
		 * @since  1.0.0
		 */
		public function ajax_bulk_synthesize() {
			check_ajax_referer( 'pollyajaxnonce', 'nonce' );

			$batch_size                  = 1;
			$common = new AmazonAI_Common();
			$common->init();
			$post_types_supported        = $common->get_posttypes_array();
			$amazon_polly_voice_id       = get_option( 'amazon_polly_voice_id' );
			$amazon_polly_sample_rate    = get_option( 'amazon_polly_sample_rate' );
			$amazon_polly_audio_location = ( 'on' === get_option( 'amazon_polly_s3' ) ) ? 's3' : 'local';

			// We are using a hash of these values to improve the speed of queries.
			$amazon_polly_settings_hash = md5( $amazon_polly_voice_id . $amazon_polly_sample_rate . $amazon_polly_audio_location );

			$args     = array(
				'posts_per_page' => $batch_size,
				'post_type'      => $post_types_supported,
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => 'amazon_polly_audio_link_location',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => 'amazon_polly_voice_id',
						'value'   => $amazon_polly_voice_id,
						'compare' => '!=',
					),
					array(
						'key'     => 'amazon_polly_sample_rate',
						'value'   => $amazon_polly_sample_rate,
						'compare' => '!=',
					),
					array(
						'key'     => 'amazon_polly_audio_location',
						'value'   => $amazon_polly_audio_location,
						'compare' => '!=',
					),
				),
			);
			$query    = new WP_Query( $args );
			$post_ids = wp_list_pluck( $query->posts, 'ID' );

			if ( is_array( $post_ids ) && ! empty( $post_ids ) ) {
				foreach ( $post_ids as $post_id ) {
					$clean_text    = $common->clean_text( $post_id, true, false);
					$sentences     = $common->break_text( $clean_text );
					$wp_filesystem = $common->prepare_wp_filesystem();
					$this->convert_to_audio( $post_id, $amazon_polly_sample_rate, $amazon_polly_voice_id, $sentences, $wp_filesystem, '' );

				}
			} else {
				$step = 'done';
			}

			$percentage = $this->get_percentage_complete();
			echo wp_json_encode(
				array(
					'step'       => $step,
					'percentage' => $percentage,
				)
			);
			wp_die();
		}

		/**
		 * Calculate the percentage complete.
		 *
		 * @since  1.0.0
		 */
		private function get_percentage_complete() {
			$total_posts               = 0;
			$common = new AmazonAI_Common();
			$common->init();
			$post_types_supported      = $common->get_posttypes_array();
			$posts_needing_translation = $this->get_num_posts_needing_transcription();

			foreach ( $post_types_supported as $post_type ) {
				$post_type_count = wp_count_posts( $post_type )->publish;
				$total_posts    += $post_type_count;
			}

			if ( 0 >= $total_posts || 0 >= $posts_needing_translation ) {
				$percentage = 100;
			} else {
				$percentage = round( $posts_needing_translation / $total_posts * 100, 2 );
			}

			return $percentage;
		}


			/**
			 * Checks how many posts should be converted.
			 *
			 * @since           1.0.0
			 */
			public function get_num_posts_needing_transcription() {

				$common = new AmazonAI_Common();
				$common->init();
				$post_types_supported        = $common->get_posttypes_array();
				$amazon_polly_voice_id       = get_option( 'amazon_polly_voice_id' );
				$amazon_polly_sample_rate    = get_option( 'amazon_polly_sample_rate' );
				$amazon_polly_audio_location = ( 'on' === get_option( 'amazon_polly_s3' ) ) ? 's3' : 'local';

				$args  = array(
					'posts_per_page' => '-1',
					'post_type'      => $post_types_supported,
					'meta_query'     => array(
						'relation' => 'AND',
						array(
							'key'     => 'amazon_polly_audio_link_location',
							'compare' => 'EXISTS',
						),
						array(
							'key'     => 'amazon_polly_voice_id',
							'value'   => $amazon_polly_voice_id,
							'compare' => '=',
						),
						array(
							'key'     => 'amazon_polly_sample_rate',
							'value'   => $amazon_polly_sample_rate,
							'compare' => '=',
						),
						array(
							'key'     => 'amazon_polly_audio_location',
							'value'   => $amazon_polly_audio_location,
							'compare' => '=',
						),
					),
				);
				$query = new WP_Query( $args );
				return count( $query->posts );
			}


}
