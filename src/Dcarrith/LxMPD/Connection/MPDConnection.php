<?php namespace Dcarrith\LxMPD\Connection;
/**
* MPDConnection.php: A class for opening a socket connection to MPD
*/

use Config;
use Dcarrith\LxMPD\MPDException as MPDException; 

/**
* This class is responsible for establishing a socket connection to MPD
* @package LxMPD
*/
class MPDConnection {

        // MPD Responses
        const MPD_OK = 'OK';
        const MPD_ERROR = 'ACK';

        // Connection, read, write errors
        const MPD_CONNECTION_FAILED = -1;
        const MPD_CONNECTION_NOT_OPENED = -2;
	const MPD_DISCONNECTION_FAILED = -6;      
 
        // Connection and details
        private $_host = 'localhost';
        private $_port = 6600;
        private $_password = null;
        private $_version = '0';

	private $_connection = null;
	private $_local = true;

	// Default socket timeout
	private $_timeout = 5;

	// Variable to switch on and off debugging
	private $_debugging = false;

	// Variable to track whether or not we're connected to MPD
	private $_connected = false;

        /**
         * Empty constructor
         * @return void
         */
        function __construct() {}

        /**
         * Establishes a connection to the MPD server
         * @return bool
         */
        public function establish() {

                // Check whether the socket is already connected
                if( $this->isEstablished() ) {
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

                                // Parse the MPD version from the response and replace the ending 0 with an x 
                                $this->_version = preg_replace('/[0]$/','x', current( sscanf( $response, self::MPD_OK . " MPD %s\n" )));

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
         * Closes the connection to the MPD server
         * @return bool
         */
        public function close() {

		// Make sure nothing unexpected happens
		try {
		
			// Check that a connection exists first
                	if( !is_null( $this->_connection ) ) {

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
         * Sets the MPD host 
         * @return void
         */
	public function setHost( $host ) {

		$this->_host = $host;
	}

	/**
         * Sets the MPD port
         * @return void
         */
	public function setPort( $port ) {

		$this->_port = $port;
	}

	/**
         * Sets the MPD password
         * @return void
         */
	public function setPassword( $password ) {

		$this->_password = $password;
	}

	/**
         * Sets the timeout for the stream socket connection to MPD
         * @return void
         */
        public function setStreamTimeout( $timeout ) {

		// Set the timeout in seconds
		stream_set_timeout( $this->_connection, $timeout );
        }

	/**
         * Gets the MPD password
         * @return string
         */
	public function getPassword() {

		return $this->_password;
	}

	/**
         * Gets the timeout for the stream socket connection to MPD
         * @return timeout
         */
        public function getTimeout() {

		return $this->_timeout;
        }

	/**
         * Gets the socket connection to MPD
         * @return connection
         */
        public function getSocket() {

                return $this->_connection;
        }

	/**
         * Checks whether the connection object has a password to use for communicating with MPD
         * @return bool
         */
        public function hasPassword() {

                return !( is_null( $this->_password ) && ( $this->_password != "" ));
        }

	/**
         * Checks whether the connection to MPD has been established yet
         * @return bool
         */
        public function isEstablished() {

                return $this->_connected;
        }
	
	/**
         * Checks whether the connection to MPD is a local connection
         * @return bool
         */
        public function isLocal() {

                return $this->_local;
        }

	/**
	 * determineIfLocal tries to determine if the connection to MPD is local
	 * @return bool
	 */
	public function determineIfLocal() {

		// Compare the MPD host a few different ways to try and determine if it's local to the Apache server
		if( 	( stream_is_local( $this->_connection ))    || 
			( $this->_host == (isset($_SERVER["SERVER_ADDR"]) ? $_SERVER["SERVER_ADDR"] : getHostByName( getHostName() ))) ||
			( $this->_host == 'localhost' ) 	     || 
			( $this->_host == '127.0.0.1' )) {

			$this->_local = true;
		}

		$this->_local = false;
	}

	/**
	 * PHP magic methods __get(), __set(), __isset(), __unset()
	 *
	 */
 
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
