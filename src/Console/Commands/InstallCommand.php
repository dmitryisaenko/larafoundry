<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Console\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'larafoundry:install';

    protected $description = 'Install LaraFoundry core (publish config, prepare base data).';

    public function handle(): int
    {
        $this->info('LaraFoundry: installing…');

        $this->call('vendor:publish', ['--tag' => 'larafoundry-config']);

        $this->info('LaraFoundry: config published. Next: `php artisan migrate`.');

        return self::SUCCESS;
    }
}
