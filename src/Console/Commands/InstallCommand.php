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
        $this->call('vendor:publish', ['--tag' => 'larafoundry-permissions']);

        $this->info('LaraFoundry: config published. Next steps:');
        $this->line('  1. php artisan migrate');
        $this->line('  2. php artisan larafoundry:permissions:sync   (seeds permissions, the `authenticated` role and role templates)');

        $this->newLine();
        $this->line('Integrating into an existing app (User model, frontend wiring, OAuth/QR, super-admin)?');
        $this->line('See docs/integrating-into-an-existing-app.md (installed at vendor/dmitryisaenko/larafoundry/docs/).');

        return self::SUCCESS;
    }
}
