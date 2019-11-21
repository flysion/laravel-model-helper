<?php

namespace ModelHelper;

use Illuminate\Support\ServiceProvider;

class ModelHelperServiceProvider extends ServiceProvider
{
    protected $commands = [
        \ModelHelper\Console\BuildModel::class,
    ];

    /**
     * Bootstrap any application services.
     * @return void
     */
    public function boot()
    {
        
    }

    /**
     * Register any application services.
     * @return void
     */
    public function register()
    {
        $this->commands($this->commands);
    }
}