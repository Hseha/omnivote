<?php

namespace App\Support;

use RuntimeException;

/**
 * The election archive could not capture a cycle's roster.
 *
 * Raised only in strict mode, which the reset path uses: a reset is about to
 * delete the live candidate rows, so the record of them has to exist first. A
 * best-effort archive that fails quietly means the history is deleted along
 * with the rows it described — which is exactly what happened on 2026-10-17
 * to 19, when the snapshot insert failed on a missing `updated_at` column and
 * the catch swallowed it.
 */
class ElectionArchiveFailed extends RuntimeException
{
}
