<?php

declare(strict_types=1);

namespace App\Mapper;

/**
 * Rewrites TMDB's series-only genres into the film vocabulary.
 *
 * TMDB maintains two genre lists. Most ids appear on both and carry the same name (18 Drame,
 * 35 Comédie, 80 Crime, 99 Documentaire…), so a series and a film tagged Drame land on the
 * same row and there is nothing to reconcile. Two series-only ids are the problem: each
 * bundles a pair of concepts the film list keeps apart, so the library ends up counting the
 * same idea twice under two spellings — 186 films under "Science-Fiction" and 8 series under
 * "Science-Fiction & Fantastique", neither total meaning what it says.
 *
 *   10759 Action & Adventure            → 28 Action          + 12 Aventure
 *   10765 Science-Fiction & Fantastique → 878 Science-Fiction + 14 Fantastique
 *
 * Both splits are lossless: every half exists on the film side, so nothing is dropped and
 * nothing is invented. The rest of the television vocabulary is deliberately left as it is:
 *
 *   10768 Guerre & Politique — the halves are not interchangeable and only one of them has a
 *         film counterpart. Folding it into Guerre would file a political drama under war,
 *         which is a worse error than the double count it would fix.
 *   10762 Enfants, 10763 Actualité, 10764 Réalité, 10766 Feuilleton, 10767 Talk — genuinely
 *         television. There is no film genre to merge them into.
 *
 * The replacement names are written out here rather than read from the database because the
 * target row may not exist yet: a library whose first enriched work is a series would have
 * no "Aventure" to point at. They are the exact fr-FR names TMDB returns for those four ids,
 * which is the only language TmdbClient ever asks for.
 *
 * Static on purpose. This is a lookup table, not a collaborator — it holds no state and
 * depends on nothing, and injecting it would only add a constructor argument to both mappers
 * and to every test that builds one by hand.
 */
final class TvGenreVocabulary
{
    /**
     * @var array<int, list<array{int, string}>>
     */
    private const TRANSLATIONS = [
        10759 => [[28, 'Action'], [12, 'Aventure']],
        10765 => [[878, 'Science-Fiction'], [14, 'Fantastique']],
    ];

    /**
     * @param array<int, array{id: int, name: string}> $genres a TMDB `genres` block, from
     *                                                         either catalogue
     *
     * @return list<array{id: int, name: string}> the same block with any compound television
     *                                            genre replaced by its film-side halves
     */
    public static function translate(array $genres): array
    {
        $translated = [];

        foreach ($genres as $genre) {
            // Anything without an entry passes through untouched, which is every film genre
            // and most series ones.
            $replacements = self::TRANSLATIONS[$genre['id']] ?? [[$genre['id'], $genre['name']]];

            foreach ($replacements as [$id, $name]) {
                // Keyed by id so a payload that reaches the same genre twice — directly and
                // through a split — yields it once. The caller's addGenre() also guards, but
                // this way the returned list is honest on its own.
                $translated[$id] = ['id' => $id, 'name' => $name];
            }
        }

        return array_values($translated);
    }
}
