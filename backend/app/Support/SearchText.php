<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Folds text into the single form a free-text search compares against.
 *
 * WHY THIS IS PHP AND NOT SQL. The suite runs against SQLite and PostgreSQL,
 * and the two disagree about case in ways that hid a real defect: SQLite's
 * `LIKE` is case-insensitive for ASCII while PostgreSQL's is case-sensitive, so
 * lowering in the database means picking whose `LOWER` you get. Folding in PHP
 * means both engines compare bytes that this one function produced, and a
 * search behaves the same in a test as it does in production.
 *
 * WHY ASCII COMES BEFORE LOWER, which is the whole reason this is a named
 * function rather than an inline `Str::lower`. `mb_strtolower('İ')` is `i̇`: a
 * dotless i carrying a COMBINING DOT ABOVE, two code points, not equal to `i`.
 * Lowering first therefore leaves a Turkish title unmatchable by the word an
 * operator typed. `Str::ascii` folds `İ` to `I` and `ı` to `i` before case
 * enters the picture, so all four spellings of Istanbul (`İstanbul`,
 * `ISTANBUL`, `istanbul`, `ıstanbul`) land on one string.
 *
 * The same pass drops the rest of the diacritics, so `siguoc` finds `ŞIĞÜÖÇ`
 * and `odeme` finds `Ödeme`. That is deliberate rather than incidental: a
 * Turkish operator types the unaccented spelling into a search box far more
 * often than the accented one, and a search that demanded the accents would be
 * correct and useless.
 */
final class SearchText
{
    /**
     * The comparable form of a piece of text.
     *
     * Both sides of a search go through here: the stored column when it is
     * written, and the operator's term when it is read. Neither side is
     * meaningful on its own, so a caller that folds one and not the other has
     * a bug this function cannot see.
     */
    public static function fold(string $value): string
    {
        return Str::lower(Str::ascii($value));
    }
}
