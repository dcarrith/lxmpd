<?php namespace Dcarrith\LxMPD;

use Auth;
use Config;
use Illuminate\Support\ServiceProvider;
use Dcarrith\LxMPD\LxMPD;
use Dcarrith\LxMPD\Connection\MPDConnection as MPDConnection;
use Dcarrith\LxMPD\Exception\MPDConnectionException as MPDConnectionException;
use Log;

class LxMPDServiceProvider extends ServiceProvider {

	/**
	 * Indicates if loading of the provider is deferred.
	 *
	 * @var bool
	 */
	protected $defer = false;

	/**
	 * Bootstrap the application events.
	 *
	 * @return void
	 */
	public function boot()
	{
		//$this->package('dcarrith/lxmpd');
		$this->publishes(array(
			__DIR__.'/../../config/config.php' => config_path('lxmpd.php')
		));
	}

	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register()
	{
		//$this->app['lxmpd'] = $this->app->share(function($app)
		//{

		$this->app->bind('lxmpd', function()
		{
			// These three calls to Log::info show the three different ways to access configs
			Log::info( 'LxMPDServiceProvider', array('host' => config('lxmpd.host')));
			//Log::info( 'LxMPDServiceProvider', array('port' => $app['config']->get('lxmpd::port')));
			//Log::info( 'LxMPDServiceProvider', array('password' => $app['config']['lxmpd::password']));
			Log::info( 'LxMPDServiceProvider', array('port' => config('lxmpd.port')));
			Log::info( 'LxMPDServiceProvider', array('password' => config('lxmpd.password')));

			try {
				// Instantiate a new MPDCOnnection object using the host, port and password set in configs
				$connection = new MPDConnection( config('lxmpd.host'), config('lxmpd.port'), config('lxmpd.password') );

				// Determine if the connection to MPD is local to the Web Server
				$connection->determineIfLocal();

				// Establish the connection
				$connection->establish();

				// Instantiate a new LxMPD object and inject the connection dependency
				$lxmpd = new LxMPD($connection);

				// Authenticate to the MPD server
				$lxmpd->authenticate();

				// Update the statistics for MPD
				$lxmpd->refreshInfo();

				// Return the connected, authenticated and refreshed MPD object
				return $lxmpd;

			} catch (MPDConnectionException $e) {

				Log::info( 'LxMPDServiceProvider caught MPDConnectionException', array($e));

				// Get the default path to the mpd binary from the project configuration
				$binary = config('defaults.default_path_to_mpd_binary');

				Log::info( 'LxMPDServiceProvider default_path_to_mpd_binary', array($binary));

				// Get the logged in user
				$user = Auth::user();

				// Get the usersConfig object from the user object
				$usersConfig = $user->usersConfig;

				// Get the users config array from the model
				$configs = $usersConfig->config();

				// Get the path to the mpd.conf file from the user's config array
				$conf = ltrim($configs['mpd']['mpd_dir'], "/") . 'mpd.conf';

				Log::info( 'LxMPDServiceProvider path to mpd.conf', array($conf));

				// Instantiate an LxMPD object without a connection object
				$lxmpd = new LxMPD();

				$starting = true;

				// We want to wait until the start command returns before trying to make the connection again
				while($starting = !($lxmpd->start($binary, $conf))) {}

				// Instantiate a new MPDCOnnection object using the host, port and password set in configs
				$connection = new MPDConnection( config('lxmpd.host'), config('lxmpd.port'), config('lxmpd.password') );

				// Determine if the connection to MPD is local to the Web Server
				$connection->determineIfLocal();

				// Establish the connection
				$connection->establish();

				// Instantiate a new LxMPD object and inject the connection dependency
				$lxmpd = new LxMPD($connection);

				// Authenticate to the MPD server
				$lxmpd->authenticate();

				// Update the statistics for MPD
				$lxmpd->refreshInfo();

				// Return the connected, authenticated and refreshed MPD object
				return $lxmpd;
			}
		});
	}

	/**
	 * Get the services provided by the provider.
	 *
	 * @return array
	 */
	public function provides()
	{
		return array('lxmpd');
	}

}
