<?php

namespace App\Providers;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mailer\Bridge\Sendgrid\Transport\SendgridTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Schema::defaultStringLength(191);

        // SendGrid over the HTTPS v3 API (config/mail.php 'sendgrid' mailer).
        // Needed because some hosts (Railway) blackhole outbound SMTP ports;
        // HTTPS/443 always works. Uses symfony/sendgrid-mailer.
        Mail::extend('sendgrid', function (array $config = []) {
            return (new SendgridTransportFactory())->create(
                new Dsn(
                    'sendgrid+api',
                    'default',
                    $config['key'] ?? config('mail.mailers.sendgrid.key')
                )
            );
        });
    }
}
