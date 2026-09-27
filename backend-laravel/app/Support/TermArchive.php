<?php

namespace App\Support;

use App\Models\Candidate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Winner term-archiving lifecycle ("End of Term").
 *
 * The admin configures a Term Ends instant in Settings → Voting
 * (`admin.settings.voting.termEndsAt`). Once the clock passes it (or the admin
 * archives manually), every currently certified winner is tagged with an
 * `archived_at` timestamp and the school-year label of the term, so the term
 * is closed retroactively but its winners stay queryable (Past Terms tab).
 */
class TermArchive
{
    private const TERM_ENDS_AT = 'admin.settings.voting.termEndsAt';

    /** The configured Term Ends instant (UTC), or null when unset. */
    public static function termEndsAt(): ?Carbon
    {
        try {
            $value = DB::table('election_settings')
                ->where('key', self::TERM_ENDS_AT)
                ->value('value');
        } catch (\Throwable) {
            return null;
        }

        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** True when the configured term has elapsed (or is now). */
    public static function due(): bool
    {
        $endsAt = self::termEndsAt();

        return $endsAt !== null && now()->gte($endsAt);
    }

    /**
     * School-year label for a term ending at the given instant, derived from
     * the Philippine school-year convention (June–May): a term that ends in
     * the first half of the calendar year belongs to the SY that started the
     * previous year (e.g. a May 2027 end → "SY 2026-2027"), otherwise to the
     * SY that started the same year (a Sep 2026 end → "SY 2026-2027").
     */
    public static function labelFor(?Carbon $at = null): string
    {
        $end = $at ?? now();
        $start = $end->month <= 5 ? $end->year - 1 : $end->year;

        return "SY {$start}-".($start + 1);
    }

    /**
     * Archive every currently-certified winner that has not already been
     * archived. Returns the number of candidates tagged. Idempotent: re-runs
     * only touch the winners left over from an earlier (partial) pass.
     */
    public static function archiveNow(?Carbon $at = null): int
    {
        $stamp = ($at ?? now())->toDateTimeString();
        $label = self::labelFor($at);

        return Candidate::query()
            ->where('certified_winner', true)
            ->whereNull('archived_at')
            ->update([
                'archived_at' => $stamp,
                'term_label' => $label,
            ]);
    }

    /**
     * Auto-archive when the configured Term Ends instant has passed.
     * No-op (0 rows) when the term is still live or nothing is configured.
     */
    public static function runIfDue(): int
    {
        if (! self::due()) {
            return 0;
        }

        return self::archiveNow(self::termEndsAt());
    }
}