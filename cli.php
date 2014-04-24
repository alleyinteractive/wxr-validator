<?php

WP_CLI::add_command( 'validate-import', 'Validate_Import_CLI_Command' );

class Validate_Import_CLI_Command extends WP_CLI_Command {

	private $home_url = '';

	/**
	 * Verify the images in WXR files all return 200 response.
	 *
	 * @subcommand images
	 * @synopsis --dir=<dir>
	 */
	public function images( $args, $assoc_args ) {
		if ( empty( $assoc_args['dir'] ) ) {
			WP_CLI::error( "You must specify a directory in which to find XML files." );
		}

		# Output everything as it happens rather than waiting until the script finishes
		@ob_end_flush();

		$counts['global']['error'] = $counts['global']['success'] = $counts['global']['files'] = 0;

		foreach ( glob( trailingslashit( $assoc_args['dir'] ) . '*.xml' ) as $file ) {
			$counts['global']['files']++;
			$counts[ $file ]['error'] = $counts[ $file ]['success'] = $counts[ $file ]['found'] = 0;

			WP_CLI::line( "Currently parsing `$file`" );

			require_once( dirname( __FILE__ ) . '/xml-parser.php' );

			// First, run through XML nodes looking for image attachments
			$attachment_urls = array();
			list( $posts, $this->home_url ) = $this->parse_xml( $file );
			foreach ( $posts as $post ) {
				if ( 'attachment' == $post['post_type'] ) {
					$attachment_urls[ $post['post_id'] ] = ! empty( $post['attachment_url'] ) ? $post['attachment_url'] : $post['guid'];
				}
			}
			unset( $posts );

			if ( ! empty( $attachment_urls ) ) {
				$attachment_urls = array_unique( $attachment_urls );
				$attachment_urls = array_filter( $attachment_urls, function( $image_url ) {
					return in_array( pathinfo( $image_url, PATHINFO_EXTENSION ), array( 'jpg', 'jpeg', 'gif', 'png' ) );
				} );
				$refs_found = count( $attachment_urls );
				$counts[ $file ]['found'] += $refs_found;
				WP_CLI::line( "Found {$refs_found} jpg, jpeg, gif, and png attachments in {$file}\n" );
				$this->process_images( $attachment_urls, $counts, $file, true );
			}

			// Next, locate image references and check those, too
			$xml = file_get_contents( $file );
			if ( ! empty( $xml ) ) {
				// This regex may look intimidating, but it's relatively simple: open quote (single or double), optional domain,
				// extension of gif or png or jpg or jpeg, can have a query string (gets ignored), and then closing the same quote.
				// Much of the regex syntax comes from allowing a quote mark if it's not the same one enclosing the whole URL, e.g.
				// "/foo/bar's.jpg". While this seems unlikely, there's no harm in being precise.
				$misc_images = preg_match_all( '#([\'"])((?:https?://(?:(?!\1).)+?)?/(?:(?!\1).)+?\.(?:png|gif|jpe?g)(?:\?(?:(?!\1).)*?)?)\1#i', $xml, $matches );
				unset( $xml );

				if ( $misc_images && ! empty( $matches[2] ) ) {
					$matches[2] = array_map( array( $this, 'add_domain_to_path' ), $matches[2] );
					$matches[2] = array_filter( $matches[2], function( $image_url ) use ( $attachment_urls ) {
						$image_url = preg_replace( '/-\d+x\d+(\.(?:png|gif|jpe?g))/i', '$1', $image_url );
						return ! in_array( $image_url, $attachment_urls );
					} );
					$matches[2] = array_unique( $matches[2] );
					$refs_found = count( $matches[2] );
					$counts[ $file ]['found'] += $refs_found;
					WP_CLI::line( "Found {$refs_found} additional image references in {$file}\n" );
					$this->process_images( $matches[2], $counts, $file );
				}
			}

		}

		WP_CLI::success( "Process complete!" );
		WP_CLI::success( "Files parsed:       \t{$counts['global']['files']}" );
		WP_CLI::success( "Successful fetches: \t{$counts['global']['success']}" );
		WP_CLI::success( "Errors encountered: \t{$counts['global']['error']}" );
		WP_CLI::success( "" );
		WP_CLI::success( "File Summaries:" );
		WP_CLI::success( "===================================" );
		unset( $counts['global'] );
		foreach ( $counts as $file => $file_counts ) {
			WP_CLI::success( "{$file}:" );
			WP_CLI::success( "Images found:       \t{$file_counts['found']}" );
			WP_CLI::success( "Successful fetches: \t{$file_counts['success']}" );
			WP_CLI::success( "Errors encountered: \t{$file_counts['error']}" );
		}
	}

	private function parse_xml( $file ) {
		$parser = new WXR_Parser();
		$import_data = $parser->parse( $file );
		if ( is_wp_error( $import_data ) ) {
			WP_CLI::error( "Sorry, there was an error parsing `$file`. Is it a valid WXR file?" );
		}
		return array( $import_data['posts'], $import_data['base_blog_url'] );
	}

	private function head( $url ) {
		$response = wp_remote_head( $url );
		if ( ! $response ) {
			return "Empty response";
		} elseif ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		} elseif ( '200' != wp_remote_retrieve_response_code( $response ) ) {
			return 'HTTP Response != 200: ' . wp_remote_retrieve_response_code( $response );
		}
		return false;
	}

	private function process_images( $image_urls, &$counts, $file, $reference_index = false ) {
		foreach ( (array) $image_urls as $i => $url ) {
			// WP_CLI::line( "Checking {$url}..." );
			$error = $this->head( $url );
			if ( $error ) {
				$attachment = $reference_index ? " (attachment ID {$i})" : '';
				$counts['global']['error']++;
				$counts[ $file ]['error']++;
				WP_CLI::warning( "{$file}: Error retrieving {$url}{$attachment}; {$error}" );
			} else {
				$counts['global']['success']++;
				$counts[ $file ]['success']++;
			}
		}
	}

	public function add_domain_to_path( $path ) {
		return preg_match( '/^https?:/i', $path ) ? $path : $this->home_url . $path;
	}
}