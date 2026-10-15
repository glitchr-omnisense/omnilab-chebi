<?php

namespace Omnilab\Chebi\Tests;

use Omnilab\Chebi\ChebiSourceFactory;
use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\RateLimitedException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Point;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\Query;
use Omnilab\Source\SeriesQuery;
use Omnilab\Source\SourceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from www.ebi.ac.uk/chebi/backend/api/public on
 * 2026-10-15: compound-27732.json (caffeine; its 117 citations cut to 2),
 * compound-26385.json (purine alkaloid, a class without a structure),
 * compound-notfound.json (an id ChEBI does not know: 404), the searches
 * (es_search) for caffeine's InChIKey, its CAS number, its KEGG id, the
 * synonym "theine", the text "caffeine" (pages 1 and 2, three a page) and
 * a name ChEBI does not know (404). A lookup by name asks five results:
 * the recorded first page of three stands for it.
 */
final class ChebiSourceTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    private function source(?MockResponse $response = null, array $options = []): SourceInterface
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $this->calls[] = substr($path, \strlen('/chebi/backend/api/public/')).($query ? '?'.http_build_query($query) : '');
            if ($response) {
                return $response;
            }
            $search = str_ends_with($path, '/es_search/');
            $fixture = match (true) {
                (bool) preg_match('~/compound/(27732|3295)/$~', $path) => 'compound-27732.json',
                str_ends_with($path, '/compound/26385/') => 'compound-26385.json',
                $search && 'RYYVLZVUVIJVGH-UHFFFAOYSA-N' === $query['term'] => 'search-inchikey.json',
                $search && '58-08-2' === $query['term'] => 'search-cas.json',
                $search && 'C07481' === $query['term'] => 'search-kegg.json',
                $search && 'theine' === $query['term'] => 'search-theine.json',
                $search && 'caffeine' === $query['term'] => '2' === ($query['page'] ?? null) ? 'search-caffeine-2.json' : 'search-caffeine-1.json',
                default => null,
            };

            return $fixture
                ? new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture))
                : new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.($search ? 'search-notfound.json' : 'compound-notfound.json')), ['http_code' => 404]);
        });

        return (new ChebiSourceFactory($http))->create($options);
    }

    public function testCaffeineByItsChebiIdInOneCall(): void
    {
        $caffeine = $this->source()->entity('CHEBI:27732');

        self::assertSame('chebi:27732', $caffeine->id->key());
        self::assertSame('caffeine', $caffeine->label);
        self::assertSame(Domain::CHEMISTRY, $caffeine->domain);
        self::assertSame('compound', $caffeine->kind);
        self::assertStringStartsWith('A trimethylxanthine in which the three methyl groups are located at positions 1, 3, and 7.', $caffeine->description);
        self::assertSame('C8H10N4O2', $caffeine->value('molecular_formula'));
        self::assertEquals(new Quantity(194.194, 'g/mol'), $caffeine->value('molecular_weight'));
        self::assertEquals(new Quantity(194.08038, 'Da'), $caffeine->value('monoisotopic_mass'));
        self::assertSame(0, $caffeine->value('charge'));
        self::assertSame('1,3,7-trimethyl-3,7-dihydro-1H-purine-2,6-dione', $caffeine->value('iupac_name'), 'ChEBI\'s markup taken out');
        self::assertSame('Cn1c(=O)c2c(ncn2C)n(C)c1=O', $caffeine->value('smiles'));
        self::assertSame('RYYVLZVUVIJVGH-UHFFFAOYSA-N', $caffeine->value('inchikey'));
        self::assertSame(3, $caffeine->value('chebi_stars'));
        self::assertSame('chebi', $caffeine->property('molecular_weight')->source);
        self::assertSame('https://www.ebi.ac.uk/chebi/CHEBI:27732', $caffeine->url);
        self::assertSame('https://www.ebi.ac.uk/chebi/backend/api/public/compound/27732/structure/', $caffeine->image);
        self::assertSame(['compound/27732/'], $this->calls);
    }

    public function testItsOntologyRolesAndParents(): void
    {
        $caffeine = $this->source()->entity(Identifier::chebi(27732));

        self::assertSame(['purine alkaloid', 'trimethylxanthine'], array_map('strval', $caffeine->properties('is_a')));
        $roles = array_map('strval', $caffeine->properties('has_role'));
        self::assertCount(34, $roles);
        self::assertContains('central nervous system stimulant', $roles);
        self::assertContains('adenosine A2A receptor antagonist', $roles);
        self::assertSame('Has role', $caffeine->property('has_role')->label);
    }

    public function testItsCrossReferencesAndSynonyms(): void
    {
        $caffeine = $this->source()->entity('27732');

        self::assertSame(['chebi:27732', 'chebi:3295', 'chebi:22982', 'chebi:41472', 'inchikey:RYYVLZVUVIJVGH-UHFFFAOYSA-N', 'inchi:InChI=1S/C8H10N4O2/c1-10-4-9-6-5(10)7(13)12(3)8(14)11(6)2/h4H,1-3H3', 'cas:58-08-2', 'kegg:C07481', 'kegg:D00528'], $caffeine->identifiers()->keys(), 'its own id, its secondary ids, its structure, CAS once, KEGG COMPOUND before KEGG DRUG');
        self::assertSame('27732', $caffeine->identifier(Scheme::CHEBI));

        self::assertCount(20, $caffeine->synonyms);
        self::assertSame('1,3,7-trimethyl-2,6-dioxopurine', $caffeine->synonyms[0]);
        self::assertContains('guaranine', $caffeine->synonyms);
        self::assertContains('3,7-Dihydro-1,3,7-trimethyl-1H-purin-2,6-dion', $caffeine->synonyms, 'the markup taken out');
        foreach (['caffeine', 'Caffeine', 'CAFFEINE', 'NSC-5036', 'FEMA NO. 2224', 'Caffeine component of excedrin'] as $name) {
            self::assertNotContains($name, $caffeine->synonyms, 'not the label, a code or a brand name');
        }
        self::assertSame([], $this->source(null, ['synonyms' => 0])->entity('27732')->synonyms);
    }

    public function testASecondaryIdGivesThePrimaryEntry(): void
    {
        self::assertSame('chebi:27732', $this->source()->entity('CHEBI:3295')?->id->key());
    }

    public function testByInchikeyCasKeggOrNameASearchFirst(): void
    {
        $source = $this->source();
        foreach (['RYYVLZVUVIJVGH-UHFFFAOYSA-N', '58-08-2', 'C07481', 'kegg:C07481', Identifier::cas('58-08-2'), 'theine', 'caffeine'] as $id) {
            $this->calls = [];
            self::assertSame('chebi:27732', $source->entity($id)?->id->key(), 'by '.$id);
            self::assertCount(2, $this->calls, 'a search, then the entry: '.$id);
        }
        $this->calls = [];
        $source->entity('RYYVLZVUVIJVGH-UHFFFAOYSA-N');
        self::assertSame(['es_search/?term=RYYVLZVUVIJVGH-UHFFFAOYSA-N&size=5', 'compound/27732/'], $this->calls);
    }

    public function testAClassHasNoStructure(): void
    {
        $class = $this->source()->entity('CHEBI:26385');

        self::assertSame('purine alkaloid', $class->label);
        self::assertSame('class', $class->kind);
        self::assertNull($class->image);
        self::assertNull($class->value('molecular_formula'));
        self::assertSame(['purines', 'alkaloid'], array_map('strval', $class->properties('is_a')));
    }

    public function testUnknownIsNull(): void
    {
        self::assertNull($this->source()->entity('CHEBI:999999999'));
        self::assertNull($this->source()->entity('nonexistentcompoundxyz'));
        self::assertNull($this->source()->entity(''));
    }

    public function testAnIdentifierChebiDoesNotReadIsRefusedBeforeAnyCall(): void
    {
        foreach ([Identifier::cid(2519), Identifier::wikidata('Q60235'), 'CHEMBL113', 'ENSG00000141510', Identifier::of(Scheme::UNIPROT, 'P69905')] as $id) {
            try {
                $this->source()->entity($id);
                self::fail('not read: '.$id);
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testSearchPageByPage(): void
    {
        $source = $this->source();
        $page = $source->search(new Query('caffeine', limit: 3));

        self::assertSame(['caffeine', 'caffeine citrate', 'sodium caffeine benzoate'], array_map(static fn ($e) => $e->label, $page->items));
        self::assertSame(18, $page->total);
        self::assertSame('2', $page->next);
        self::assertEquals(new Quantity(386.317, 'g/mol'), $page->items[1]->value('molecular_weight'));
        self::assertSame('RCQXSQPPHJPGOF-UHFFFAOYSA-N', $page->items[1]->identifier(Scheme::INCHIKEY));

        $second = $source->search(new Query('caffeine', limit: 3, cursor: $page->next));
        self::assertSame(['caffeine-d9', 'caffeine-(trimethyl-13C3)', 'Caffeine-D3'], array_map(static fn ($e) => $e->label, $second->items), 'the markup taken out');
        self::assertSame('3', $second->next);
        self::assertSame(['es_search/?term=caffeine&size=3', 'es_search/?term=caffeine&size=3&page=2'], $this->calls);

        self::assertSame([], $source->search(new Query('caffeine', Domain::ASTRONOMY))->items, 'nothing of another domain');
        self::assertCount(2, $this->calls, 'and no call for it');
        $none = $source->search(new Query('nonexistentcompoundxyz'));
        self::assertSame([0, true], [$none->total, $none->isLast()], 'ChEBI\'s 404 is an empty page');
    }

    public function testErrorsMapTheSameWayAsEverySource(): void
    {
        try {
            $this->source(new MockResponse('<html>Bad Gateway</html>', ['http_code' => 502]))->entity('CHEBI:27732');
            self::fail('a 502 is an UnavailableException');
        } catch (UnavailableException $e) {
            self::assertSame(502, $e->status);
        }
        $this->expectException(RateLimitedException::class);
        $this->source(new MockResponse('', ['http_code' => 429]))->entity('CHEBI:27732');
    }

    public function testWhatItCanDo(): void
    {
        self::assertSame([Capability::ENTITY, Capability::SEARCH, Capability::STRUCTURE, Capability::CROSS_REFERENCES], $this->source()->capabilities());
        $this->expectException(NotSupportedException::class);
        $this->source()->series(new SeriesQuery(['temperature_2m'], new Point(48.57, 7.75)));
    }
}
