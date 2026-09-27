<?php

namespace Tests\Unit;

use App\Support\ElectionTally;
use Tests\TestCase;

/**
 * Pure winner/tie determination for a single position. These cover the
 * enumeration of multi-seat (seat_count > 1) edge cases: boundary ties,
 * full-group wins, zero-quota seats, and any sized candidate field.
 */
class ElectionTallyTest extends TestCase
{
    /** @return array<string, array{candidate_ref: string, votes: int}>[] */
    private function rows(array $votes): array
    {
        $letters = range('A', 'Z');
        $out = [];
        $i = 0;
        foreach ($votes as $votesForCandidate) {
            $out[] = ['candidate_ref' => "ref-{$letters[$i++]}", 'votes' => $votesForCandidate];
        }

        return $out;
    }

    public function test_single_seat_goes_to_the_clear_leader(): void
    {
        $result = ElectionTally::resolve($this->rows([10, 7, 3]), 1);

        $this->assertSame(['ref-A' => 1], $result['elected']);
        $this->assertSame([], $result['tied']);
    }

    public function test_clear_top_two_fill_two_seats_in_rank_order(): void
    {
        // ref-A:12 > ref-B:9, so ranks stay in that order.
        $result = ElectionTally::resolve($this->rows([12, 9, 4, 1]), 2);

        $this->assertSame(['ref-A' => 1, 'ref-B' => 2], $result['elected']);
        $this->assertSame([], $result['tied']);
    }

    public function test_multi_seat_group_fitting_exactly_is_no_tie(): void
    {
        // Three candidates at 10, three seats available: everyone is elected.
        $result = ElectionTally::resolve($this->rows([10, 10, 10]), 3);

        $this->assertCount(3, $result['elected']);
        $this->assertSame(1, $result['elected']['ref-A']);
        $this->assertSame(3, $result['elected']['ref-C']);
        $this->assertSame([], $result['tied']);
    }

    public function test_ties_inside_the_fill_region_are_all_elected(): void
    {
        // Two seats, but the top two are tied: both fit, so no tie-break needed.
        $result = ElectionTally::resolve($this->rows([10, 10, 5]), 2);

        $this->assertSame(['ref-A' => 1, 'ref-B' => 2], $result['elected']);
        $this->assertSame([], $result['tied']);
    }

    public function test_boundary_tie_flags_everyone_on_the_last_seat(): void
    {
        // Two seats; candidates ref-B and ref-C tie for the second seat.
        $result = ElectionTally::resolve($this->rows([8, 6, 6]), 2);

        $this->assertSame(['ref-A' => 1], $result['elected']);
        $this->assertSame(['ref-B', 'ref-C'], $result['tied']);
    }

    public function test_three_way_tie_for_one_seat_flags_all_three(): void
    {
        $result = ElectionTally::resolve($this->rows([10, 10, 10]), 1);

        $this->assertSame([], $result['elected']);
        $this->assertSame(['ref-A', 'ref-B', 'ref-C'], $result['tied']);
    }

    public function test_overflowing_group_at_the_boundary_deprives_the_lower_group(): void
    {
        // One seat; a 4-way tie on top. Nobody may win arbitrarily.
        $result = ElectionTally::resolve($this->rows([9, 9, 9, 9]), 1);

        $this->assertSame([], $result['elected']);
        $this->assertSame(['ref-A', 'ref-B', 'ref-C', 'ref-D'], $result['tied']);
    }

    public function test_zero_vote_candidates_never_elect(): void
    {
        $result = ElectionTally::resolve($this->rows([0, 0, 0]), 1);
        $this->assertSame([], $result['elected']);
        $this->assertSame([], $result['tied']);
    }

    public function test_empty_tally_produces_no_winners(): void
    {
        $result = ElectionTally::resolve([], 1);
        $this->assertSame([], $result['elected']);
        $this->assertSame([], $result['tied']);
    }

    public function test_seat_count_lower_than_candidate_field_skips_no_seats(): void
    {
        // Four candidates of increasing votes, 3 seats. ref-D (8) ranks 1st.
        $result = ElectionTally::resolve($this->rows([2, 4, 6, 8]), 3);

        $this->assertSame(['ref-D' => 1, 'ref-C' => 2, 'ref-B' => 3], $result['elected']);
        $this->assertSame([], $result['tied']);
    }
}
