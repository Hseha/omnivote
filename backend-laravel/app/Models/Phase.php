<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Phase extends Model
{
    protected $table = 'phases';

    protected $fillable = ['name', 'description', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * The single currently-active phase (exactly one row has is_active = true).
     * Falls back to 'registration' so a fresh install behaves predictably.
     */
    public static function current(): ?self
    {
        return static::where('is_active', true)->first()
            ?? static::where('name', 'registration')->first();
    }

    /**
     * Activates the named phase and deactivates every other phase atomically.
     *
     * `forceFill` + `save` guarantees the write even when the target row was
     * already loaded as active (an `update()` on an already-true boolean would
     * otherwise short-circuit as "not dirty" and leave every phase inactive).
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public static function setCurrent(string $name): ?self
    {
        $phase = static::where('name', $name)->firstOrFail();

        DB::transaction(function () use ($phase) {
            static::query()->update(['is_active' => false]);
            $phase->forceFill(['is_active' => true])->save();
        });

        return $phase->fresh();
    }
}
