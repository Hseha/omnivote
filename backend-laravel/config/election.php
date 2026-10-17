<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SSG President seat
    |--------------------------------------------------------------------------
    |
    | Slug of the Position that represents the student-body SSG Presidency.
    | Once the election results are published (see ResultsController::finalize
    | and App\Support\ResultsPublisher) the elected winner of this seat is
    | automatically handed the single-seat `ssg_president` role. When the seat
    | is contested by a tie, no automatic grant happens and an administrator
    | resolves it via the manual grant action.
    |
    */

    'ssg_president_position_slug' => env('SSG_PRESIDENT_POSITION_SLUG', 'president'),

];
