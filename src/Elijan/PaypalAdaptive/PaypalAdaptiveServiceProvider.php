<?php namespace Elijan\PaypalAdaptive;

use Illuminate\Support\ServiceProvider;

class PaypalAdaptiveServiceProvider extends ServiceProvider {

	/**
	 * Indicates if loading of the provider is deferred.
	 *
	 * @var bool
	 */
	protected $defer = false;


    public function boot()
    {
        $this->package('elijan/paypal-adaptive');

    }
	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register()
	{
        $this->app['paypal_adaptive'] = $this->app->share(function($app)
        {
              return new PaypalAdaptive($app['config']);

        });

	}

	/**
	 * Get the services provided by the provider.
	 *
	 * @return array
	 */
	public function provides()
	{
		return array();
	}

}
