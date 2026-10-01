<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // The catalog is structural, not demo data: production needs it, because
        // it is how the five colleges, their programs and the messy legacy
        // spellings the registrar importer resolves against are (re)created. It
        // is updateOrCreate-based, so re-running it on a live database is safe,
        // and running it FIRST means the demo student below finds a catalog that
        // already has its department.
        $this->call(CatalogSeeder::class);

        // Everything past this point is a development convenience, and both
        // seeders refuse to run in production on their own. Warning here as well
        // makes a `php artisan db:seed` on a live host say out loud what it did
        // and did not do, instead of looking like it worked.
        if (app()->environment('production')) {
            $this->command?->warn('UserSeeder and StudentSeeder skipped in production: they create demo panel logins and a demo voter.');

            return;
        }

        $this->call(UserSeeder::class);
        $this->call(StudentSeeder::class);
    }
}
