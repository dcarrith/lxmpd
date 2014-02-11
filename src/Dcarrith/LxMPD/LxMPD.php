<?php namespace Dcarrith\LxMPD;
/**
* LxMPD.php: A Laravel-ready class for controlling MPD
*/

use Dcarrith\LxMPD\Exception\MPDException as MPDException; 

/**
* A Laravel-ready class for controlling MPD
* @package MPD
*/
class LxMPD { //extends \Thread {

        // MPD Responses
        const MPD_OK = 'OK';
        const MPD_ERROR = 'ACK';

        // Connection, read, write errors
        const MPD_CONNECTION_FAILED = -1;
        const MPD_CONNECTION_NOT_OPENED = -2;
        const MPD_WRITE_FAILED = -3;
        const MPD_STATUS_EMPTY = -4;
        const MPD_UNEXPECTED_OUTPUT = -5;
        const MPD_TIMEOUT = -5;
	const MPD_DISCONNECTION_FAILED = -6;      
 
	// MPD ACK_ERROR constants from Ack.hxx
	const ACK_ERROR_NOT_LIST = 1;
	const ACK_ERROR_ARG = 2;
	const ACK_ERROR_PASSWORD = 3;
	const ACK_ERROR_PERMISSION = 4;
	const ACK_ERROR_UNKNOWN = 5;
	const ACK_ERROR_NO_EXIST = 50;
	const ACK_ERROR_PLAYLIST_MAX = 51;
	const ACK_ERROR_SYSTEM = 52;
	const ACK_ERROR_PLAYLIST_LOAD = 53;
	const ACK_ERROR_UPDATE_ALREADY = 54;
	const ACK_ERROR_PLAYER_SYNC = 55;
	const ACK_ERROR_EXIST = 56;

	const ESSENTIAL_ID3_TAGS_MISSING = 70;

	// A general command failed 
	const MPD_COMMAND_FAILED = -100;

	// Output array chunk sizes
	const PLAYLISTINFO_CHUNK_SIZE = 8;

        // Connection and details
	private $_local = true;
        private $_connection = null;
        private $_host = 'localhost';
        private $_port = 6600;
        private $_password = null;
        private $_version = '0';
	
	// Default socket timeout
	private $_timeout = 5;

	// Variable to switch on and off debugging
	private $_debugging = false;

	// Variable to track whether or not we're connected to MPD
	private $_connected = false;

	// Variable to store a list of commands for sending to MPD in bulk
	private $_commandQueue = "";

	// Variable for storing properties available via PHP magic methods: __set(), __get(), __isset(), __unset()
	private $_properties = array();

	// Variable to specify whether or not playlist tracks should be filtered down to only contain essential tags
	private $_tagFiltering = true;

	// Variable to specify whether or not to throw missing tag exceptions for tracks that are missing essetial tags
	private $_throwMissingTagExceptions = true;

	// The essential id3 tags that we need when chunking an array of output into chunks of 8 elements
	private $_essentialID3Tags = array( "Artist", "Album", "Title", "Track", "Time" );

	// The essential MPD tags that we need in combination with essentialID3Tags when chunking an array of output into chunks of 8 elements
	private $_essentialMPDTags = array( "file", "Pos", "Id" );

	// This will serve as a binary checklist for determining when we've parsed out all the elements of tracks in a playlist
	private $_trackElementsChecklist = array(); 

        // This is an array of commands whose output is expected to be an array
        private $_expectArrayOutput = array( 'commands', 'decoders', 'find', 'list', 'listall', 'listallinfo', 'listplaylist', 'listplaylistinfo', 'listplaylists', 'notcommands', 'lsinfo', 'outputs', 'playlist', 'playlistfind', 'playlistid', 'playlistinfo', 'playlistsearch', 'plchanges', 'plchangesposid', 'search', 'tagtypes', 'urlhandlers' );
      
	// The output from these commands require special parsing  
	private $_specialCases = array( 'listplaylists', 'lsinfo', 'decoders' );
 
	// This is an array of MPD commands that are available through the __call() magic method
	private $_methods = array( 'add', 'addid', 'clear', 'clearerror', 'close', 'commands', 'consume', 'count', 'crossfade', 'currentsong', 'decoders', 'delete', 'deleteid', 'disableoutput', 'enableoutput', 'find', 'findadd', 'idle', 'kill', 'list', 'listall', 'listallinfo', 'listplaylist', 'listplaylistinfo', 'listplaylists', 'load', 'lsinfo', 'mixrampdb', 'mixrampdelay', 'move', 'moveid', 'next', 'notcommands', 'outputs', 'password', 'pause', 'ping', 'play', 'playid', 'playlist', 'playlistadd', 'playlistclear', 'playlistdelete', 'playlistfind', 'playlistid', 'playlistinfo', 'playlistmove', 'playlistsearch', 'plchanges', 'plchangesposid', 'previous', 'random', 'rename', 'repeat', 'replay_gain_mode', 'replay_gain_status', 'rescan', 'rm', 'save', 'search', 'seek', 'seekid', 'setvol', 'shuffle', 'single', 'stats', 'status', 'sticker', 'stop', 'swap', 'swapid', 'tagtypes', 'update', 'urlhandlers' );

        /**
         * Set connection paramaters.
         * @param $host Host to connect to, (default: localhost)
         * @param $port Port to connect through, (default: 6600)
         * @param $password Password to send, (default: null)
         * @return void
         */
        function __construct( $host = 'localhost', $port = 6600, $password = null ) {

                $this->_host = $host;
                $this->_port = $port;
                $this->_password = $password;

		// Set the timeout to whatever is set as the php default
		$this->_timeout = ini_get( 'default_socket_timeout' );

		// We will need this in order to determine when we've parsed each track from a playlist
		$this->_trackElementsChecklist = array_fill_keys( array_merge( $this->_essentialMPDTags, $this->_essentialID3Tags ), 0 );	
        }

        /**
         * Connects to the MPD server
         * @return bool
         */
        public function connect() {

                // Check whether the socket is already connected
                if( $this->isConnected() ) {
                        return true;
                }
                
		// Try to open the socket connection to MPD with a 5 second timeout
		if( !$this->_connection = @fsockopen( $this->_host, $this->_port, $errn, $errs, 5 ) ) {

			// Throw an MPDException along with the connection errors
			throw new MPDException( 'Connection failed: '.$errs, self::MPD_CONNECTION_FAILED );
		}

                // Clear connection messages
                while( !feof( $this->_connection ) ) {

                        $response = trim( fgets( $this->_connection ) );

                        // If the connection messages have cleared
                        if( strncmp( self::MPD_OK, $response, strlen( self::MPD_OK ) ) == 0 ) {

				// Successully connected
                                $this->_connected = true;

				// The default is to assume MPD is remote from the web server, but if it's local, we want to make a note of it
				if ( $this->isLocal( $this->_connection, $this->_host )) {
					$this->_local = true;
				}

                                // Parse the MPD version from the response and replace the ending 0 with an x since it seems to only report major versions
                                $this->_version = preg_replace('/[0]$/','x', current( sscanf( $response, self::MPD_OK . " MPD %s\n" )));

                                // Send the connection password
                                if( !is_null( $this->_password ) ) {
                                        $this->password( $this->_password );
                                }

				// Refresh all the status and statistics variables
				$this->RefreshInfo();

				// Connected successfully
				return true;
                        }

                        // Check to see if there is a connection error message that was sent in the response
                        if( strncmp( self::MPD_ERROR, $response, strlen( self::MPD_ERROR ) ) == 0 ) {

				// Parse out the error message from the response
                                preg_match( '/^ACK \[(.*?)\@(.*?)\] \{(.*?)\} (.*?)$/', $response, $matches );

				// Throw an exception and include the response errors
                                throw new MPDException( 'Connection failed: '.$matches[4], self::MPD_CONNECTION_FAILED );
                        }
                }
      
		// Throw a general connection failed exception 
		throw new MPDException( 'Connection failed', self::MPD_CONNECTION_FAILED );
        }

        /**
         * Disconnects from the MPD server
         * @return bool
         */
        public function disconnect() {

		// Make sure nothing unexpected happens
		try {
		
			// Check that a connection exists first
                	if( !is_null( $this->_connection ) ) {

				// Send the close command to MPD
                        	$this->close();

				// Close the socket
                        	fclose( $this->_connection );

				// Adjust our class properties to denote that we disconnected
                        	$this->_connection = null;
                        	$this->_connected = false;
                	}

		} catch (Exception $e) {

			throw new MPDException( 'Disconnection failed: '.$e->getMessage(), self::MPD_DISCONNECTION_FAILED );		
		}

		// We'll assume it was successful
                return true;
        }

        /**
         * Writes data to the MPD socket
         * @param string $data The data to be written
         * @return bool
         */
        private function write( $data ) {
 
		if( !$this->isConnected() ) {
                        $this->connect();
                }

		if( !fputs( $this->_connection, "$data\n" ) ) {
			throw new MPDException( 'Failed to write to MPD socket', self::MPD_WRITE_FAILED );
                }

                return true;
        }

        /**
         * Reads data from the MPD socket
         * @return array Array of lines of data
         */
        private function read() {

                // Check for a connection
                if( !$this->isConnected() ) {
                        $this->connect();
                }

                // Set up output array and get stream information
                $output = array();
                $info = stream_get_meta_data( $this->_connection );

                // Wait for output to finish or time out
                while( !feof( $this->_connection ) && !$info['timed_out'] ) {

                        $line = trim( fgets( $this->_connection ) );

			$info = stream_get_meta_data( $this->_connection );

                        $matches = array();

                        // We get empty lines sometimes. Ignore them.
                        if( empty( $line ) ) {

                                continue;

                        } else if( strcmp( self::MPD_OK, $line ) == 0 ) {

                                break;

                        } else if( strncmp( self::MPD_ERROR, $line, strlen( self::MPD_ERROR ) ) == 0 && preg_match( '/^ACK \[(.*?)\@(.*?)\] \{(.*?)\} (.*?)$/', $line, $matches ) ) {

				$errorConstant = $matches[1];
				$indexOfFailedCommand = $matches[2];
				$command = $matches[3];
				$errorMessage = $matches[4];

				throw new MPDException( 'Command failed: '.$errorMessage, self::MPD_COMMAND_FAILED );
                                //throw new MPDException( 'Command failed: '.$line, self::MPD_COMMAND_FAILED );
                        
			} else {
                        
			        $output[] = $line;
                        }
                }

		//var_dump($output);

                if( $info['timed_out'] ) {

                        // I can't work out how to rescue a timed-out socket and get it working again. So just throw it away.
                        fclose( $this->_connection );
                        $this->_connection = null;
                        $this->_connected = false;

                        throw new MPDException( 'Command timed out', self::MPD_TIMEOUT );

                } else {

                        return $output;
                }
        }

        /**
         * Runs a given command with arguments
         * @param string $command The command to execute
         * @param string|array $args The command's argument(s)
         * @param int $timeout The script's timeout, in seconds
         * @return array Array of parsed output
         */
        public function runCommand( $command, $args = array(), $timeout = null ) {

		// Set a timeout so it's always set to either the default or the passed in parameter
		$timeout = ( isset( $timeout ) ? intval( $timeout ) : $this->_timeout );

                // Trim and then cast the command to a string, just to make sure
                $toWrite = strval( trim( $command ));

		// If the args is an array, then first escape double quotes in every element, then implode to strings delimted by enclosing quotes
		if( is_array( $args ) && ( count( $args ) > 0 )) {
	 	
			$toWrite .= ' "' . implode('" "', str_replace( '"', '\"', $args )) . '"';
		}

                // Write command to MPD socket
                $this->write( $toWrite );

		// Set the timeout in seconds
		stream_set_timeout( $this->_connection, $timeout );

                // Read the output from the MPD socket
                $output = $this->read();

		// Reset the timeout
		stream_set_timeout( $this->_connection, $this->_timeout );

                // Return parsed output
                return $this->parseOutput( $output, $command, $args );
        }

        /**
         * Parses an array of output lines from MPD into a common array format
         * @param array $output the output read from the connection to MPD
         * @return mixed (string || array)
         */
        private function parseOutput( $output, $command = '', $args = array() ) {

                $parsedOutput = array();

		//var_dump( $command );
		//var_dump( $args );
		//var_dump( $output );		

		if( !count($output) ) {
			return $parsedOutput;
		}

		$test = array();
	
		switch( $command ) {

			// The current playlist needs to be parsed out and chunked into an array of tracks for easier handling
			case 'playlistinfo' :

				// This is for keeping track of the track being parsed out
				$count = 0;

				// This is in case we need to report on tracks that are missing essential tags
				$incompleteTracks = array();

				// This is also in case we need to report on tracks that are missing essential tags
				$essentialTags = array_merge( $this->_essentialMPDTags, $this->_essentialID3Tags );

				if( !$this->_tagFiltering ) {

					$track = array();

					foreach( $output as $line ) {

						// Get the key value pairs from the line of output
						preg_match('/(.*?):\s(.*)/', $line, $matches);

						// Put the cleaned up matched pieces into the variables we'll be using
						list( $subject, $key, $value ) = $matches;
	
						if( array_key_exists( $key, $track ) ) {

							// Append the track array onto the array of parsedOutput to be returned
							$test[] = $track;
						
							// Initialize a new track to compile
							$track = array( $key => $value );
					
						} else {

							// Set the key value pair in the track array
							$track[ $key ] = $value;
						}
					}
				
					// Append the last track that was compiled onto the array of parsedOutput to return
					$test[] = $track;

					if( $this->_throwMissingTagExceptions ) {

						// Re-initialize the trackElementsChecklist 
						$this->_trackElementsChecklist = array_fill_keys( $essentialTags, 0 );

						// Now, let's check for any missing essential tags in each track so we can report on them in an exception
						foreach( $test as $track ) {
		
							foreach( $track as $key => $value ) {
	
								// We only need certain key value pairs to fill out a track 
								if ( in_array( $key, $essentialTags )) {

									// Let's first make sure that the current track being compiled doesn't already have this key value
									if( !$this->_trackElementsChecklist[ $key ] ) {

										// Check off this key so we know we've gotten it for the current track
										$this->_trackElementsChecklist[ $key ] = 1;

									} else {
				
										if( !(count( array_keys( $this->_trackElementsChecklist, '1' )) == count( $this->_trackElementsChecklist ))) {
											// Loop through the missing elements so we can keep a record of it
											foreach( array_keys( $this->_trackElementsChecklist, '0' ) as $missing ) {
	
												// Store which tracks are missing what tags
												$incompleteTracks[ $count ][] = $missing;
											}
										}

										// Moving on to the next track to compile
										$count++;
	
										// Re-initialize the trackElementsChecklist 
										$this->_trackElementsChecklist = array_fill_keys( $essentialTags, 0 );
	
										// Check off this key so we know we've gotten it for the next track to compile
										$this->_trackElementsChecklist[ $key ] = 1;
									}
								}
							}
						}

						//var_dump($test);
						//var_dump($this->_trackElementsChecklist);
						//var_dump($incompleteTracks);
	
						// If we have any tracks that are missing essential tags, then throw an exception to alert the user
						if( count($incompleteTracks) ) {
					
							$detailedMessage = "";

							foreach( $incompleteTracks as $num => $missing ) {
								
								$detailedMessage .= "Track #".$num." is missing tags: ".implode( ", ", $missing ).".  ";
							}

							// There must be some essential tags missing from one or more tracks in the playlist
							throw new MPDException( 'The command "'.$command.'" has retrieved some tracks that are missing essential tag elements.  Please clean up any deficient id3 tags and try again.  The essentials tags are as follows: '.implode(", ", $this->_essentialID3Tags).'.  Details: '.$detailedMessage, self::ESSENTIAL_ID3_TAGS_MISSING );
						}

					}				

				} else {

					// Re-initialize the trackElementsChecklist 
					$this->_trackElementsChecklist = array_fill_keys( $essentialTags, 0 );

					foreach( $output as $line ) {

						// Get the key value pairs from the line of output
						preg_match('/(.*?):\s(.*)/', $line, $matches);

						// Put the cleaned up matched pieces into the variables we'll be using
						list( $subject, $key, $value ) = $matches;

						// We only need certain key value pairs to fill out a track 
						if ( in_array( $key, $essentialTags )) {

							// Let's first make sure that the current track being compiled doesn't already have this key value
							if( !$this->_trackElementsChecklist[ $key ] ) {

								// Set the key value pair for the current track being compiled
								$test[ $count ][ $key ] = $value;

								// Check off this key so we know we've gotten it for the current track
								$this->_trackElementsChecklist[ $key ] = 1;

							} else {
					
								if( !(count( array_keys( $this->_trackElementsChecklist, '1' )) == count( $this->_trackElementsChecklist ))) {
									// Loop through the missing elements so we can keep a record of it
									foreach( array_keys( $this->_trackElementsChecklist, '0' ) as $missing ) {

										// Store which tracks are missing what tags
										$incompleteTracks[ $count ][] = $missing;
									}
								}

								// Moving on to the next track to compile
								$count++;

								// Re-initialize the trackElementsChecklist 
								$this->_trackElementsChecklist = array_fill_keys( $essentialTags, 0 );

								// Set the first key value pair for the next track to compile
								$test[ $count ][ $key ] = $value;
		
								// Check off this key so we know we've gotten it for the next track to compile
								$this->_trackElementsChecklist[ $key ] = 1;
							}
						}
					}

					//var_dump($this->_trackElementsChecklist);
					//var_dump($incompleteTracks);
	
					if( $this->_throwMissingTagExceptions ) {

						// If we have any tracks that are missing essential tags, then throw an exception to alert the user
						if( count($incompleteTracks) ) {

							$detailedMessage = "";

							foreach( $incompleteTracks as $num => $missing ) {
								
								$detailedMessage .= "Track #".$num." is missing tags: ".implode( ", ", $missing ).".  ";
							}

							// There must be some essential tags missing from one or more tracks in the playlist
							throw new MPDException( 'The command "'.$command.'" has retrieved some tracks that are missing essential tag elements.  Please clean up any deficient id3 tags and try again.  The essentials tags are as follows: '.implode(", ", $this->_essentialID3Tags).'.  Details: '.$detailedMessage, self::ESSENTIAL_ID3_TAGS_MISSING );
						}
					}
				}

				return $test;

				break;

			// This will parse out the playlists into an simple array of playlist names
			case 'listplaylists' :

				foreach( $output as $line ) {

					// Get the key value pairs from the line of output
					preg_match('/(.*?):\s(.*)/', $line, $matches);

					// Put the cleaned up matched pieces into the variables we'll be using
					list( $subject, $key, $value ) = $matches;

					if ($key == "playlist") {

						$test[]['name'] = $value;
					}			
				}

				return $test;

				break;

			// This will parse out a specific playlist into a simple array of playlist tracks
			case 'list' :
			case 'listplaylist' :

				foreach( $output as $line ) {

					// Get the key value pairs from the line of output
					preg_match('/(.*?):\s(.*)/', $line, $matches);

					if( count($matches) != 3 ) {
			
						continue;
					}

					// Put the cleaned up matched pieces into the variables we'll be using
					list( $subject, $key, $value ) = $matches;
	
					// For playlists that aren't the current playlist, we only need an array of values
					$test[] = $value;
				}

				return $test;

				break;

			// statistics
			// stats
			// list	
			// idle	
			default :

				$items = array();

				foreach( $output as $line ) {

					// Get the key value pairs from the line of output
					preg_match('/(.*?):\s(.*)/', $line, $matches);

					// Put the cleaned up matched pieces into the variables we'll be using
					list( $subject, $key, $value ) = $matches;
	
					if( array_key_exists( $key, $items ) ) {

						// Append the track array onto the array of parsedOutput to be returned
						$test[] = $items;
						
						// Initialize a new track to compile
						$items = array( $key => $value );
					
					} else {

						// Set the key value pair in the track array
						$items[ $key ] = $value;
					}
				}
			
				if( in_array( $command, $this->_expectArrayOutput ) ) {

					// Append the last items array onto the array of parsedOutput to return
					$test[] = $items;

					//var_dump("here is the collection of items");
					//var_dump($test);

					return $test;

				} else {
				
					//var_dump("here are the items");
					//var_dump($items);

					return $items;
				}	
			

				// This is an array of commands whose output is expected to be an array
				//private $_expectArrayOutput = array( 'commands', 'decoders', 'find', 'list', 'listall', 'listallinfo', 'listplaylist', 'listplaylistinfo', 'listplaylists', 'notcommands', 'lsinfo', 'outputs', 'playlist', 'playlistfind', 'playlistid', 'playlistinfo', 'playlistsearch', 'plchanges', 'plchangesposid', 'search', 'tagtypes', 'urlhandlers' );

				//return ( in_array( $command, $this->_expectArrayOutput ) ? array( $test ) : $test );

				break;
		}
	
		return false;
        }

	/* RefreshInfo() 
	 * 
	 * Updates all class properties with the values from the MPD server.
     	 *
	 * NOTE: This function is automatically called upon Connect() as of v1.1.
	 */
	public function RefreshInfo() {
        	
		// Get the Server Statistics
		$this->statistics = $this->stats();
        	
		// Get the Server Status
		$this->status = $this->status();
        	
		// Get the Playlist
		$this->playlist = $this->playlistinfo();

		// Get a count of how many tracks are in the playlist    		
		$this->playlist_count = count( $this->playlist );

        	// Let's store the state for easy access as a property
		$this->state = $this->status['state'];
		
		if ( ($this->state == "play") || ($this->state == "pause") ) {

			$this->current_track_id = $this->status['song'];
			list ($this->current_track_position, $this->current_track_length ) = explode(":", $this->status['time']);

		} else {

			$this->current_track_id = 0;
			$this->current_track_position = 0;
			$this->current_track_length = 0;
		}

		// This stuff doesn't seem to exist anymore
		$this->uptime 		= $this->statistics['uptime'];
		$this->playtime 	= $this->statistics['playtime'];

		// These status variables are simple integers
		$this->repeat 		= $this->status['repeat'];
		$this->random 		= $this->status['random'];
		$this->single 		= $this->status['single'];
		$this->consume 		= $this->status['consume'];
		$this->volume 		= $this->status['volume'];

		// Adding some new fields that are reported on in the RefreshInfo results
		$this->playlist_id 	= ( isset($this->status['playlist']) 		? $this->status['playlist'] : 		'' );
		$this->playlist_length 	= ( isset($this->status['playlist_length']) 	? $this->status['playlist_length'] : 	'' );
		$this->song 		= ( isset($this->status['song']) 		? $this->status['song'] : 		'' );
		$this->songid 		= ( isset($this->status['songid']) 		? $this->status['songid'] : 		'' );
		$this->nextsong 	= ( isset($this->status['nextsong']) 		? $this->status['nextsong'] : 		'' );
		$this->nextsongid 	= ( isset($this->status['nextsongid']) 		? $this->status['nextsongid'] : 	'' );
		$this->time 		= ( isset($this->status['time']) 		? $this->status['time'] : 		'' );
		$this->elapsed		= ( isset($this->status['elapsed'])		? $this->status['elapsed'] : 		'' );
		$this->bitrate 		= ( isset($this->status['bitrate']) 		? $this->status['bitrate'] : 		'' );
		$this->xfade 		= ( isset($this->status['xfade']) 		? $this->status['xfade'] : 		'' );
		$this->mixrampdb 	= ( isset($this->status['mixrampdb']) 		? $this->status['mixrampdb'] : 		'' );
		$this->mixrampdelay 	= ( isset($this->status['mixrampdelay']) 	? $this->status['mixrampdelay'] : 	'' );
		$this->audio	 	= ( isset($this->status['audio']) 		? $this->status['audio'] : 		'' );

		return true;
	}

	/* QueueCommand() 
	 *
	 * Queues a generic command for later sending to the MPD server. The CommandQueue can hold 
	 * as many commands as needed, and are sent all at once, in the order they are queued, using
	 * the SendCommandQueue() method. The syntax for queueing commands is identical to SendCommand(). 
	 */
	public function QueueCommand($cmdStr, $arg1 = "", $arg2 = "") {

		if ( $this->_debugging ) echo "mpd->QueueCommand() / cmd: ".$cmdStr.", args: ".$arg1." ".$arg2."\n";

		if ( ! $this->_connected ) {

			echo "mpd->QueueCommand() / Error: Not connected\n";
			return null;

		} else {

			if ( strlen($this->_commandQueue) == 0 ) {

				$this->_commandQueue = "command_list_begin" . "\n";
			}

			if (strlen($arg1) > 0) $cmdStr .= " \"$arg1\"";
			if (strlen($arg2) > 0) $cmdStr .= " \"$arg2\"";

			$this->_commandQueue .= $cmdStr ."\n";

			if ( $this->_debugging ) echo "mpd->QueueCommand() / return\n";
		}
		return true;
	}

	/* SendCommandQueue() 
	 *
	 * Sends all commands in the Command Queue to the MPD server. See also QueueCommand().
	 */
	public function SendCommandQueue() {

		if ( $this->_debugging ) echo "mpd->SendCommandQueue()\n";

		if ( ! $this->_connected ) {

			echo "mpd->SendCommandQueue() / Error: Not connected\n";
			return null;

		} else {

			$this->_commandQueue .= "command_list_end" . "\n";

			if ( is_null( $respStr = $this->runCommand( $this->_commandQueue ))) {

				return null;

			} else {

				$this->_commandQueue = null;
				if ( $this->_debugging ) echo "mpd->SendCommandQueue() / response: '".$respStr."'\n";
			}
		}

		return $respStr;
	}

	/* PLAddBulk() 
	 * 
     	 * Adds each track listed in a single-dimensional <trackArray>, which contains filenames 
	 * of tracks to add, to the end of the playlist. This is used to add many, many tracks to 
	 * the playlist in one swoop.
	 */
	public function PLAddBulk($trackArray) {

		if ( $this->_debugging ) echo "mpd->PLAddBulk()\n";

		$numFiles = count($trackArray);

		for ( $i = 0; $i < $numFiles; $i++ ) {
			$this->QueueCommand("add", $trackArray[$i]);
		}

		$resp = $this->SendCommandQueue();

		$this->RefreshInfo();

		if ( $this->_debugging ) echo "mpd->PLAddBulk() / return\n";

		return $resp;
	}


	public function GetFirstTrack( $scope_key = "album", $scope_value = null) {

		$album = $this->find( "album", $scope_value );

		return $album[0]['file'];
	}

	public function GetPlaylists() {

		if ( is_null( $resp = $this->SendCommand( "lsinfo" ))) return NULL;
        	
		$playlistsArray = array();
        	$playlistLine = strtok($resp,"\n");
        	$playlistName = "";
        	$playlistCounter = -1;

        	while ( $playlistLine ) {

            		list ( $element, $value ) = explode(": ",$playlistLine);

            		if ( $element == "playlist" ) {
            			$playlistCounter++;
            			$playlistName = $value;
            			$playlistsArray[$playlistCounter] = $playlistName;
            		}

            		$playlistLine = strtok("\n");
        	}

        	return $playlistsArray;
	}

	/* SendCommand()
	 * 
	 * Sends a generic command to the MPD server. Several command constants are pre-defined for 
	 * use (see MPD_CMD_* constant definitions above). 
	 */
	public function SendCommand( $cmdStr, $arg1 = "", $arg2 = "", $arg3 = "" ) {
		if ( ! $this->_connected ) {
			echo "mpd->SendCommand() / Error: Not connected\n";
		} else {
			// Clear out the error String
			$this->errStr = "";
			$respStr = "";

			if (strlen($arg1) > 0) $cmdStr .= " \"$arg1\"";
			if (strlen($arg2) > 0) $cmdStr .= " \"$arg2\"";
			if (strlen($arg3) > 0) $cmdStr .= " \"$arg3\"";

			fputs( $this->_connection,"$cmdStr\n" );

			while( !feof( $this->_connection )) {

				$response = fgets( $this->_connection,1024 );

				// An OK signals the end of transmission -- we'll ignore it
				if ( strncmp( "OK", $response,strlen( "OK" )) == 0 ) {
					break;
				}

				// An ERR signals the end of transmission with an error! Let's grab the single-line message.
				if ( strncmp( "ACK", $response, strlen( "ACK" )) == 0 ) {
					list ( $junk, $errTmp ) = explode("ACK" . " ",$response );
					$this->errStr = strtok( $errTmp,"\n" );
				}

				if ( strlen( $this->errStr ) > 0 ) {
					return NULL;
				}

				// Build the response string
				$respStr .= $response;
			}
		}
		return $respStr;
	}

        /**
         * Excecuting the 'idle' function requires turning off timeouts, since it could take a long time
         * @param array $subsystems An array of particular subsystems to watch
         * @return string|array
         */
        public function idle( $subsystems = array() ) {
                
		return $this->runCommand( 'idle', $subsystems, 1800 );

		//$idle = $this->runCommand( 'idle', $subsystems, 1800 );
                // When two subsystems are changed, only one is printed before the OK
                // line. Anyone repeatedly polling a PHP script to simulate continuous
                // listening will miss events as MPD creates a new 'client' on every
                // request. This will frequently happen as it isn't uncommon for 'player'
                // 'mixer', and 'playlist' events to fire at the same time (e.g. when someone
                // double-clicks on a file to add it to the playlist and play in one go
                // while playback is stopped)

                // This is annoying. The best workaround I can think of is to use the
                // 'subsystems' argument to split the idle polling into ones that
                // are unlikely to collide.

                // If the stream is local (so we can assume an extremely fast connection to it)
                // then try to avoid missing changes by running new 'idle' requests with a
                // short timeout. This will allow us to clear the queue of any additional
                // changed systems without slowing down the script too much.
                // This works reasonably well, but YMMV.
                /*$idleArray = array( $idle );
                if( stream_is_local( $this->_connection ) || $this->_host == 'localhost' ) {
                        try { while( 1 ) { array_push( $idleArray, $this->runCommand( 'idle', $subsystems, 0.1 ) ); } }
                        catch( MPDException $e ) { ; }
                }
                return (count( $idleArray ) == 1)? $idleArray[0] : $idleArray;*/
        }

        /**
         * Checks whether the socket has connected
         * @return bool
         */
        public function isConnected() {

                return $this->_connected;
        }

	/**
	 * isLocal tries to determine if the connection to MPD is local
	 * @param connection resource
	 * @param host string 
	 * @return bool
	 */
	public function isLocal( $connection, $host ) {

		if( 	( stream_is_local( $connection ))    || 
			( $host == (isset($_SERVER["SERVER_ADDR"]) ? $_SERVER["SERVER_ADDR"] : $host )) ||
			( $host == 'localhost' ) 	     || 
			( $host == '127.0.0.1' )) {

			return true;
		}

		return false;
	}

	/**
	 * PHP magic methods __call(), __get(), __set(), __isset(), __unset()
	 *
	 */
 
        public function __call( $name, $arguments ) {
	
                if( in_array( $name, $this->_methods ) ) {
                        return $this->runCommand( $name, $arguments );
                }
        }

	public function __get($name) {

		if ( array_key_exists( $name, $this->_properties )) {
			return $this->_properties[$name];
		}

		$trace = debug_backtrace();

		trigger_error(	'Undefined property via __get(): ' . $name .
				' in ' . $trace[0]['file'] .
				' on line ' . $trace[0]['line'],
				E_USER_NOTICE	);
		
		return null;
	}

	public function __set( $name, $value ) {
		$this->_properties[$name] = $value;
	}

	public function __isset( $name ) {
		return isset( $this->_properties[$name] );
	}

	public function __unset( $name ) {
		unset( $this->_properties[$name] );
	}
}
