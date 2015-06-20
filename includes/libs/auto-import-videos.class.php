<?php
if( !class_exists( 'YouTube_API_Query' ) ){
	require_once( YTI_PATH . 'includes/libs/youtube-api-query.class.php' );
}

class YTI_Automatic_Import_Videos extends YouTube_API_Query{
	
	/**
	 * Plugin transient name for automatic updates
	 */
	private $transient = '__yti_playlists_update';
	
	/**
	 * Plugin option that stores details about the last update.
	 * Default value of the option is:
	 * array(
	 * 	  'post_id' 	=> 'WP post id of last queried playlist',
	 *    'time' 		=> 'timestamp when the last query was made',
	 *    'running' 	=> 'is update still running or has completed successfully'
	 *    'empty'		=> 'no playlists found'
	 * )
	 */
	private $last_updated = '__yti_last_playlist_updated';
	
	/**
	 * Store plugin options
	 * @var array
	 */
	private $options;
	
	/**
	 * Stores WP errors 
	 */
	private $error = false;
	
	/**
	 * When automatic import is triggered, the plugin issues 
	 * a http request having some special variables on it. Those variables
	 * are defined here.
	 */
	private $request_vars = array(
		'var' => array(
			'name'  => 'yti_import_request',
			'value' => 'true'
		),
		'key' => array(
			'name'  	=> 'yti_key'
		)
	);
	
	
	/**
	 * Class constructor, will check if an update should run and 
	 * will set the actions to trigger imports.
	 */
	public function __construct(){
		// get plugin options
		$this->options = yti_get_settings();
		
		// only cron calls can trigger imports
		if( !$this->_is_import_request() ){
			// on shutdown, make autoimport call
			add_action( 'shutdown', array( $this, 'trigger_auto_import' ) );
			return;		
		}
		
		// if it's a request to autoimport, don't allow all page to display
		if( $this->_is_import_request() && !has_action( 'init', array( $this, 'terminate' ) ) ){
			add_action( 'init', array( $this, 'terminate' ), 9999 );
		}
		
		// check if an update should run
		if( !$this->_run_update() ){
			return false;
		}
		
		// hook the import function on init
		if( !has_action( 'init', array( $this, 'import' ) ) ){
			add_action( 'init', array( $this, 'import' ), 999 );
		}		
	}
	
	/**
	 * "shutdown" action callback
	 * If the plugin should start imports, make a call to start the 
	 * process. 
	 */
	public function trigger_auto_import(){
		// if transient is expired, it's time to start imports
		if( $this->_transient_expired( false ) && !$this->_prevent_import() ){
			$time_start = microtime( true );
			
			$url = $this->_get_import_request_url();
			$r = wp_remote_get( $url, array(
				'sslverify' 	=> false,
				'blocking' 		=> false,
				'timeout' 		=> 1,
				'decompress' 	=> false
			));
			
			$time_end 	= microtime( true );
			$time 		= $time_end - $time_start;
			/**
			 * A simple action that is triggered after a remote request to trigger an
			 * update was sent.
			 */
			do_action( 'yti_update_request_time', $time );
		}
	}
	
	/**
	 * Init callback, terminates page output
	 */
	public function terminate(){
		die();
	}
	
	/**
	 * Callback function set on init action to run imports.
	 */
	public function import(){
		// check the request including the key
		if( !$this->_is_import_request( true ) ){
			return;
		}
		
		// increase the time limit
		@set_time_limit( 300 );
		
		// get playlist ID that should be updated
		$playlist_id = $this->get_playlist_post_id();
		if( !$playlist_id ){
			// reset update details
			$update_data = array(
				'post_id' 			=> false,
				'time'				=> time(),
				'running_update'	=> false
			);
			update_option( $this->last_updated, $update_data );
			return;
		}
		
		// get latest registered update
		$update = get_option( 
			$this->last_updated,
			array( 
				'post_id' 			=> false,
				'running_update' 	=> false 
			)
		);
		
		// check if last update is flagged as still running.
		// If it is, apply a 5 minutes delay in case updates overlap (maybe low update time of 1 minute between them)
		if( isset( $update['running_update']) && $update['running_update'] ){
			// get the update time
			$update_time = isset( $update['time'] ) ? $update['time'] : false;
			if( $update_time ){
				// give a 5 minute delay between updates
				if( time() - $update_time < 300 ){
					// if same playlist ID, apply a delay
					//if( isset( $update['post_id'] ) && $playlist_id == $update['post_id'] ){			
						$this->error = new WP_Error();
						$this->error->add(
							'yti_double_import', 
							__('An automatic import process is already running. Once that import is done, automatic import will continue imports as scheduled.', 'yti_video'), 
							array( 'pid' => $update['post_id'] )
						);
						
						/**
						 * If registered delay between imports is > 5 minutes (the delay needed to wait if an update is still running)
						 * update the transient to check after 5 minutes so we don't experience any update delays
						 */
						if( $this->get_delay() > 300 ){
							set_transient( $this->transient, time(), 305 );
						}						
						return;
					//}		
				}
			}				
		}
		
		// store update details
		$update_data = array(
			'post_id' 			=> $playlist_id,
			'time'				=> time(),
			'running_update'	=> true
		);
		update_option( $this->last_updated, $update_data );
		
		// get post type object
		global $YTI_POST_TYPE;
		// get playlist data
		$meta = get_post_meta( 
			$playlist_id,
			$YTI_POST_TYPE->get_playlist_meta_name(), 
			true 
		);
		// if meta isn't found, bail out and issue error
		if( !$meta ){
			if( !is_wp_error( $this->error ) ){
				$this->error = new WP_Error();
			}
			$this->error->add(
				'yti_no_playlist_details', 
				__('Some details about your playlsit could not be found. Please try to remove and recreate the playlist.', 'yti_video'), 
				array( 'pid' => $playlist_id )
			);	

			// flag this update as finished
			$update_data['running_update'] = false;
			update_option( $this->last_updated, $update_data );
			
			return;
		}
		
		// backwards compatibility, prevoius API didn't had page tokens
		if( !isset( $meta['page_token'] ) ){
			$meta['page_token'] = '';
		}
		
		// fire up the youtube query class
		parent::__construct( $this->options['import_quantity'], true );
		$items = array();
		
		switch( $meta['type'] ){
			case 'user':
				$items = parent::get_user_uploads( $meta['id'], $meta['page_token'] );
			break;
			case 'playlist':
				$items = parent::get_playlist( $meta['id'], $meta['page_token'] );
			break;
			case 'channel':
				$items = parent::get_channel_uploads( $meta['id'], $meta['page_token'] );
			break;	
			default:
				if( !is_wp_error( $this->error ) ){
					$this->error = new WP_Error();
				}
				$this->error->add(
					'yti_unknown_feed_type', 
					__('Sorry, we encountered an unknown feed type. Importing has stopped for this playlist.', 'yti_video'), 
					array( 'pid' => $playlist_id )
				);	
				
				// flag this update as finished
				$update_data['running_update'] = false;
				update_option( $this->last_updated, $update_data );
							
				return;
			break;	
		}
		
		// parent returns WP error if something went wrong. Pass this along.
		if( is_wp_error( $items ) ){
			$this->error = $items;
			// flag this update as finished
			$update_data['running_update'] = false;
			update_option( $this->last_updated, $update_data );
			
			// store the error returned by parent in playlist meta
			$meta['error'] = $items->get_error_message();
			update_post_meta( $playlist_id , $YTI_POST_TYPE->get_playlist_meta_name(), $meta );
			
			// if error was issued by YouTube, remove playlist from queue
			if( yti_is_youtube_error( $items ) ){
				wp_update_post( array(
					'post_status' 	=> 'draft',
					'ID' 			=> $playlist_id
				));
			}
			return;
		}
		
		// get feed info
		$feed_info = parent::get_list_info();
		
		// apply date restrictions and importing of only new videos to different playlist types
		$max_date = isset( $meta['start_date'] ) && !empty( $meta['start_date'] ) ? strtotime( $meta['start_date'] ) : false;
		if( 'user' == $meta['type'] || 'channel' == $meta['type'] ){
			if( $max_date ){
				// we assume no video was skipped
				foreach ( $items as $key => $entry ){
					$entry_timestamp = strtotime( $entry['published'] );
					// if entry is older than $max_date, skip all other entries as they are also older
					if( $entry_timestamp < $max_date ){
						$items = array_slice( $items, 0, $key );
						// don't save next page token since it's not needed
						$feed_info['next_page'] = '';					
						break;// stop foreach
					}						
				}
			}
		}		
		
		/**
		 * Set up first/last video boundaries to allow importing on only
		 * newly uploaded/added videos and prevent reiterating the entire playlist
		 */
		if( empty( $feed_info['prev_page'] ) && $items ){
			// mark first/last video ID
			if( isset( $meta['first_video'] ) && $meta['first_video'] != $items[0]['video_id'] ){
				$meta['last_video'] = $meta['first_video'];				
			}			
			$meta['first_video'] = $items[0]['video_id'];
		}
		// don't reiterate
		if( $meta['no_reiterate'] && isset( $meta['last_video'] ) ){
			// we assume no video was skipped
			foreach ( $items as $key => $entry ){
				if( $entry['video_id'] == $meta['last_video'] ){
					$items = array_slice( $items, 0, $key );
					// don't save next page token since it's not needed
					$feed_info['next_page'] = '';
					break;// stop foreach
				}						
			}
		}
		// if finished, set up last video as first video
		if( empty( $feed_info['next_page'] ) && isset( $meta['first_video'] ) ){
			$meta['last_video'] = $meta['first_video'];
		}
		// end video boundaries to prevent playlist reiteration
		
		// run the import
		$response = $YTI_POST_TYPE->run_import( $items, $meta );
		
		// store playlist meta details
		$meta['total'] 		= $feed_info['total_results'];
		$meta['imported']  += $response['imported'];
		$meta['updated'] 	= date('d M Y, H:i:s');
		$meta['page_token'] = $feed_info['next_page'];
		// remove error if any
		unset( $meta['error'] );		
		update_post_meta( $playlist_id, $YTI_POST_TYPE->get_playlist_meta_name(), $meta );
		
		// flag this update as finished
		$update_data['running_update'] = false;
		update_option( $this->last_updated, $update_data );	
		
		// kill the page if it's a cron call
		if( $this->_is_import_request() ){
			die();
		}
	}	
	
	/**
	 * Check if current request is an automatic import request
	 */
	private function _is_import_request( $verify_key = false ){
		extract( $this->request_vars['var'] );		
		if( isset( $_GET[ $name ] ) && $value === sanitize_text_field( $_GET[ $name ] ) ){
			$key = $this->request_vars['key'];
			if( isset( $_GET[ $key['name'] ] ) ){
				if( $verify_key ){
					$db_key = get_transient( 'yti_request_key' );	
					if( !empty( $db_key ) && $db_key === urldecode( $_GET[ $key['name'] ] ) ){
						return true;
					}					
				}else{
					return true;
				}	
			}
		}
		return false;
	}
	
	/**
	 * Automatic import requests are crafted having a variable set on them
	 * and additionally, a random, variable length, generated key that gets set up every 6 hours.
	 * This function generates the request URI.
	 * @return string - request URL that triggers the import
	 */
	private function _get_import_request_url(){
		$key = get_transient( 'yti_request_key' );
		if( !$key ){
			$key = wp_generate_password( wp_rand( 32, 64 ), true, false );
			set_transient( 'yti_request_key' , $key, ( 6 * HOUR_IN_SECONDS ) );
		}
		
		$url = get_bloginfo( 'url' ) . '/?' 
			   . $this->request_vars['var']['name'] . '=' . $this->request_vars['var']['value']
			   . '&' . $this->request_vars['key']['name'] . '=' . urlencode( $key );
		return $url;
	}
	
	/**
	 * Returns whether an update should run
	 */
	private function _run_update(){
		// check if set for server cron is set and bail out if it isn't a cron call
		if( !$this->_is_import_request() ){
			return false;
		}
		// check for extra rules set in certain situations
		if( $this->_prevent_import() ){
			return false;
		}
		
		return $this->_transient_expired();
	}
	
	/**
	 * Verifies transient and returns true if transient has expired allowing any
	 * neccessary actions that need to be taken on expiration.
	 */
	private function _transient_expired( $update_transient = true ){
		// check the transient
		$data = get_transient( $this->transient );
		// if transient is set, check its time, in some cases transient might not get removed automatically
		if( $data ){
			if( time() - $data > $this->get_delay() ){
				delete_transient( $this->transient );
				$data = false;
			}
		}	
		// if transient is expired, allow the automatic update to run
		if( !$data ){
			if( $update_transient ){
				set_transient( $this->transient, time(), $this->get_delay() );
			}	
			return true;
		}
		
		return false;
	}
	
	/**
	 * Disable automatic import on certain situations:
	 * - when $_POST requests are made
	 * - when AJAX requests are made
	 * - on certain plugin pages
	 */
	private function _prevent_import(){
		// prevent for POST submits
		if( 'POST' === $_SERVER['REQUEST_METHOD']){
			return true;
		}		
		// prevent imports on ajax calls	
		if( defined('DOING_AJAX') && DOING_AJAX ){
			return true;
		}
		// prevent on post edit pages
		if( isset( $_GET['action'] ) && 'edit' == $_GET['action'] ){
			return true;
		}
		
		// prevent imports on certain plugin pages
		if( is_admin() ){
			// if page isn't set, allow imports
			$page = isset( $_GET['page'] ) ? $_GET['page'] : false;
			if( !$page ){
				return false;
			}
			
			switch( $page ){
				// manual import page & settings page
				case 'yti_import':
				case 'yti_settings':						
					return true;
				break;
				// automatic import page
				case 'yti_auto_import':
					// prevent for actions only
					if( isset($_GET['action']) ){
						return true;
					}
				break;	
				// default allow all
				default:
					return false;	
				break;	
			}			
		}
		return false;
	}
	
	/**
	 * Returns the post ID of the playlist that should be updated next.
	 * @param bool $set_timer - set the current time on the playlist
	 */
	private function get_playlist_post_id( $set_timer = true ){
		global $YTI_POST_TYPE;
		$option = get_option( $this->last_updated, array() );
		
		if( $option && isset( $option['post_id'] ) ){
			$last_id = $option['post_id'];
		}
		
		// add a filter to get the next playlist from WP Query
		if( isset( $last_id ) ){
			$this->last_playlist_id = $last_id;
			add_filter( 'posts_where', array( $this, 'filter_where' ) );
		}
		
		// get all playlists
		$args = array(
			'post_type' 	=> $YTI_POST_TYPE->get_playlist_post_type(),
			'post_status' 	=> 'publish',
			'orderby' 		=> 'ID',
			'order'			=> 'ASC',
			'numberposts'	=> 1
		);		
		$playlists_query = new WP_Query();
		$playlists = $playlists_query->query( $args );
		
		// remove where filter
		remove_filter( 'posts_where' ,  array( $this, 'filter_where' ) );
		
		// if nothing found but last ID is set, get the first playlist ID
		if( !$playlists && isset( $last_id ) ){
			// no more playlists, get the first playlist post ID
			$playlists = get_posts( $args );			
		}
		
		// if no playlists are found, reset the last updated option
		if( !$playlists ){
			if( $set_timer ){
				$data = array(
					'post_id' 		 => false,
					'time'			 => time(),
					'empty' 		 => true,
					'running_update' => false
				);
				update_option($this->last_updated, $data);		
			}
			return;
		}
		
		return $playlists[0]->ID;	
	}
	
	/**
	 * Callback function for filter implemented in function $this->get_playlist_post_id.
	 * Will set a WHERE clause when querying for next playlist ID to be automatically updated.
	 * @param string $where
	 */
	public function filter_where( $where = '' ){
		$where .= sprintf( " AND ID > %d", $this->last_playlist_id );
		return $where;	
	}
	
	/**
	 * Get the registered delay between automatic updates in seconds
	 * @return int - number of seconds
	 */
	public function get_delay(){
		// the delay registered by user
		$delay = $this->options['import_frequency'];
		// allowed delays
		$registered_delays = yti_automatic_update_timing();
		// if delay isn't registered, set delay to default value
		if( !array_key_exists( $delay, $registered_delays ) ){
			$defaults = yti_plugin_settings_defaults();
			$delay = $defaults['import_frequency'];
		}		
		// delay is set in minutes and we need it in seconds
		$delay *= 60;
		
		return $delay;
	}
	
	/**
	 * Updates the automatic update transient 
	 */
	public function update_transient(){
		$update_data = $this->get_update();
		if( isset( $update_data['time'] ) ){
			$update_data['time'] = time();
			update_option( $this->last_updated, $update_data );
		}		
		set_transient( $this->transient, time(), $this->get_delay() );
	}

	/**
	 * Get details about the last imported playlist
	 */
	public function get_update(){
		$option 	= get_option( $this->last_updated, array() );
		$transient 	= get_transient( $this->transient );
		if( $transient ){		
			$option['time'] = $transient;
		}
		return $option;
	}
	
	/**
	 * Returns the next playlist to be updated
	 */
	public function get_next_playlist(){
		return $this->get_playlist_post_id( false );
	}
}

global $YTI_AUTOMATIC_IMPORT;
if( !$YTI_AUTOMATIC_IMPORT ){
	$YTI_AUTOMATIC_IMPORT = new YTI_Automatic_Import_Videos();
}