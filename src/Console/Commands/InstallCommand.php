<?php

namespace ParsDesign\Grow4MeDataConnector\Console\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'grow4me:install {--force : Overwrite a published configuration file}';

    protected $description = 'Publish the Grow4Me connector configuration and show installation steps';

    public function handle(): int
    {
        $arguments = ['--tag' => 'grow4me-connector-config'];

        if ($this->option('force')) {
            $arguments['--force'] = true;
        }

        $this->call('vendor:publish', $arguments);

        $this->newLine();
        $this->components->info('Grow4Me connector configuration published.');
        $this->line('1. Add your resource adapters to config/grow4me-connector.php.');
        $this->line('2. Configure GROW4ME_CONNECTOR_TOKEN and GROW4ME_CONNECTOR_CURSOR_SECRET.');
        $this->line('3. Configure GROW4ME_CONNECTOR_WEBHOOK_URL and GROW4ME_CONNECTOR_WEBHOOK_SECRET.');
        $this->line('4. Run php artisan migrate, then php artisan grow4me:doctor.');
        $this->warn('This command never writes credentials or generated secrets to your .env file.');

        return self::SUCCESS;
    }
}
