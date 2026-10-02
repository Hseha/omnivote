<?php

namespace App\Console\Commands;

use App\Support\ProductionConfigGuard;
use Illuminate\Console\Command;

/**
 * Fails a deploy when the production .env is unsafe (security assessment L-7).
 *
 * `ProductionConfigGuard` runs on every HTTP request, but it is deliberately
 * HTTP-only so a bad .env cannot lock an operator out of artisan. That means a
 * deploy would happily install a release and only discover `APP_DEBUG=true` when
 * the panel serves a stack trace.
 *
 * This command surfaces the same checks at the right moment: deploy.sh calls it
 * BEFORE `config:cache`, so an insecure configuration aborts the deploy instead
 * of going live. Use `--check` to report without failing (useful for auditing an
 * existing box).
 */
class AssertProductionConfig extends Command
{
    protected $signature = 'security:assert-production-config
                            {--check : Report findings but always exit 0}';

    protected $description = 'Verify the production environment is not insecure (assessment L-7)';

    public function handle(): int
    {
        $environment = (string) config('app.env');

        if ($environment !== 'production') {
            $this->error("APP_ENV is '{$environment}', not 'production'.");

            if ($this->option('check')) {
                $this->warn('--check given: reporting only, exiting 0.');

                return self::SUCCESS;
            }

            $this->error('Refusing to deploy without APP_ENV=production.');

            return self::FAILURE;
        }

        $problems = ProductionConfigGuard::problems();

        if ($problems === []) {
            $this->info('Production configuration looks safe.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        if ($this->option('check')) {
            $this->warn('--check given: reporting only, exiting 0.');

            return self::SUCCESS;
        }

        $this->error('Refusing to deploy with an insecure production configuration.');

        return self::FAILURE;
    }
}
