<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Puts the production countries into French.
 *
 * TMDB translates its genres when asked for fr-FR and never translates production_countries,
 * so an otherwise entirely French interface listed "United States of America" and "Belgium".
 * FrenchCountryNames now localises every country as it is enriched; this catches up the rows
 * already in the table.
 *
 * The pairs are written out rather than computed from FrenchCountryNames, so that replaying
 * this migration years from now does exactly what it did the day it was written, whatever
 * that class has become since. They cover the countries this library actually holds;
 * anything added later arrives translated on its own.
 *
 * Each update is guarded on the current English name. A row somebody has already corrected
 * by hand is left alone, and running this twice changes nothing the second time.
 */
final class Version20260830090000 extends AbstractMigration
{
    /**
     * ISO code, TMDB's English label, the French name. Note that two of TMDB's are not even
     * good English: "Guadaloupe" is misspelt and "Czech Republic" has been Czechia since 2016.
     *
     * @var list<array{string, string, string}>
     */
    private const NAMES = [
        ['AE', 'United Arab Emirates', 'Émirats arabes unis'],
        ['AR', 'Argentina', 'Argentine'],
        ['AT', 'Austria', 'Autriche'],
        ['AU', 'Australia', 'Australie'],
        ['BE', 'Belgium', 'Belgique'],
        ['BG', 'Bulgaria', 'Bulgarie'],
        ['CH', 'Switzerland', 'Suisse'],
        ['CN', 'China', 'Chine'],
        ['CO', 'Colombia', 'Colombie'],
        ['CZ', 'Czech Republic', 'Tchéquie'],
        ['DE', 'Germany', 'Allemagne'],
        ['DK', 'Denmark', 'Danemark'],
        ['DO', 'Dominican Republic', 'République dominicaine'],
        ['ES', 'Spain', 'Espagne'],
        ['FI', 'Finland', 'Finlande'],
        ['GB', 'United Kingdom', 'Royaume-Uni'],
        ['GP', 'Guadaloupe', 'Guadeloupe'],
        ['GR', 'Greece', 'Grèce'],
        ['HU', 'Hungary', 'Hongrie'],
        ['ID', 'Indonesia', 'Indonésie'],
        ['IN', 'India', 'Inde'],
        ['IS', 'Iceland', 'Islande'],
        ['IT', 'Italy', 'Italie'],
        ['JP', 'Japan', 'Japon'],
        ['KR', 'South Korea', 'Corée du Sud'],
        ['MT', 'Malta', 'Malte'],
        ['NO', 'Norway', 'Norvège'],
        ['NZ', 'New Zealand', 'Nouvelle-Zélande'],
        ['RS', 'Serbia', 'Serbie'],
        ['RU', 'Russia', 'Russie'],
        ['SE', 'Sweden', 'Suède'],
        ['TR', 'Turkey', 'Turquie'],
        ['US', 'United States of America', 'États-Unis'],
        ['UZ', 'Uzbekistan', 'Ouzbékistan'],
    ];

    public function getDescription(): string
    {
        return 'Renames production countries from TMDB English to French.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::NAMES as [$iso, $english, $french]) {
            $this->addSql(
                'UPDATE country SET name = :name WHERE iso_code = :iso AND name = :current',
                ['name' => $french, 'iso' => $iso, 'current' => $english]
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::NAMES as [$iso, $english, $french]) {
            $this->addSql(
                'UPDATE country SET name = :name WHERE iso_code = :iso AND name = :current',
                ['name' => $english, 'iso' => $iso, 'current' => $french]
            );
        }
    }
}
