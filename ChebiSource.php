<?php

namespace Omnilab\Chebi;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;
use Omnilab\Model\Property;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\Page;
use Omnilab\Source\Query;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * ChEBI, the EMBL-EBI's dictionary of chemical entities of biological
 * interest, through the REST API of ChEBI 2.0 (www.ebi.ac.uk/chebi/backend/api):
 * an entity by its ChEBI id (a secondary id gives its primary entry), an
 * InChIKey, a CAS number, a KEGG id or a name; its definition, formula,
 * masses, charge, structure (SMILES, InChI, InChIKey, an SVG depiction),
 * its ontology - what it is a kind of, its roles, its functional parents,
 * its conjugates - and its identifiers in other bases; a search by text.
 *
 * No key, no account. An entity by its ChEBI id costs one call; by
 * anything else, a search first (two). The SOAP web service of ChEBI 1 is
 * gone (its WSDL answers 500).
 */
final class ChebiSource extends HttpSource
{
    public const BASE_URI = 'https://www.ebi.ac.uk/chebi/backend/api/public/';

    /** The most entities one search page is asked for. */
    public const MAX_PER_PAGE = 100;

    /** The schemes ChEBI finds an entity by: its own, and those its search reads (checked against the entity found). */
    private const SCHEMES = [Scheme::CHEBI, Scheme::INCHIKEY, Scheme::CAS, Scheme::KEGG];

    /** Prefixes of ChEBI's cross-references (database_accessions) => the scheme they are of. */
    private const PREFIXES = [
        'kegg.compound' => Scheme::KEGG,
        'kegg.drug' => Scheme::KEGG,
        'chembl.compound' => Scheme::CHEMBL,
        'chembl' => Scheme::CHEMBL,
        'pubchem.compound' => Scheme::PUBCHEM_CID,
        'wikidata' => Scheme::WIKIDATA,
        'uniprot' => Scheme::UNIPROT,
    ];

    /** The kinds of names kept as synonyms: not the brand names ("Caffeine component of excedrin"). */
    private const SYNONYM_TYPES = ['SYNONYM', 'INN'];

    /**
     * @param int $synonyms how many synonyms to keep (0: none)
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly int $synonyms = 20,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 0.0,
    ) {
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'chebi';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::SEARCH, Capability::STRUCTURE, Capability::CROSS_REFERENCES];
    }

    public function entity(Identifier|string $id): ?Entity
    {
        if ($id instanceof Identifier) {
            $identifier = \in_array($id->scheme, self::SCHEMES, true) ? $id : throw NotSupportedException::identifier('chebi', (string) $id);
        } else {
            $text = trim($id);
            $identifier = Identifier::parse($text) ?? (ctype_digit($text) ? Identifier::tryOf(Scheme::CHEBI, $text) : null)
                ?? (preg_match('~^(?:CPD:|DR:)?[CD]\d{5}$~i', $text) ? Identifier::tryOf(Scheme::KEGG, $text) : null);
            if (null !== $identifier && !\in_array($identifier->scheme, self::SCHEMES, true)) {
                throw NotSupportedException::identifier('chebi', $id);
            }
            if (null === $identifier) {
                return '' === $text ? null : $this->byName($text);
            }
        }

        return match ($identifier->scheme) {
            Scheme::CHEBI => $this->compound($identifier->value),
            Scheme::INCHIKEY => $this->byInchikey($identifier->value),
            default => $this->byAccession($identifier),
        };
    }

    /**
     * ChEBI's own search (es_search): an exact match first when there is one,
     * then the entries whose names, definition or identifiers hold the text;
     * read from the search's documents - entity() reads the rest.
     */
    public function search(Query $query): Page
    {
        if (null !== $query->domain && Domain::CHEMISTRY !== $query->domain) {
            return new Page();
        }
        $text = trim($query->text);
        if ('' === $text) {
            return new Page();
        }
        $page = max(1, (int) $query->cursor);
        $size = max(1, min(self::MAX_PER_PAGE, $query->limit));
        $data = $this->find($text, $size, $page);
        if (null === $data) {
            return new Page([], null, 0);
        }
        $entities = [];
        foreach ($data['results'] ?? [] as $result) {
            if (\is_array($result['_source'] ?? null)) {
                $entities[] = $this->fromDocument($result['_source']);
            }
        }
        // Asking past the last page does not answer: the next cursor stops at number_pages.
        $pages = (int) ($data['number_pages'] ?? 0);

        return new Page($entities, $page < $pages ? (string) ($page + 1) : null, isset($data['total']) ? (int) $data['total'] : null);
    }

    /** The whole entry: GET compound/{id}/. Null when ChEBI does not know the id (404). */
    private function compound(int|string $id): ?Entity
    {
        $data = $this->getJson('compound/'.$id.'/');

        return null === $data || !isset($data['chebi_accession']) ? null : $this->fromCompound($data);
    }

    /** The entry whose structure has that InChIKey: the search finds it, its document is checked. */
    private function byInchikey(string $key): ?Entity
    {
        foreach ($this->find($key, 5)['results'] ?? [] as $result) {
            if (($result['_source']['inchikey'] ?? null) === $key) {
                return $this->compound((int) $result['_id']);
            }
        }

        return null;
    }

    /** The entry the search finds first for a CAS number or a KEGG id, when it does carry it. */
    private function byAccession(Identifier $identifier): ?Entity
    {
        $first = $this->find($identifier->value, 1)['results'][0]['_id'] ?? null;
        $entity = null === $first ? null : $this->compound((int) $first);

        return null !== $entity && $entity->identifiers()->has($identifier) ? $entity : null;
    }

    /**
     * The entry of that name: the first found whose name is the text
     * (whatever its case), else the only one found (a synonym ChEBI matched
     * exactly: "theine"); null when the search found several and none is
     * named so.
     */
    private function byName(string $name): ?Entity
    {
        $data = $this->find($name, 5);
        $results = $data['results'] ?? [];
        foreach ($results as $result) {
            foreach ([$result['_source']['name'] ?? null, $result['_source']['ascii_name'] ?? null] as $candidate) {
                if (null !== $candidate && mb_strtolower((string) self::text($candidate)) === mb_strtolower($name)) {
                    return $this->compound((int) $result['_id']);
                }
            }
        }

        return 1 === (int) ($data['total'] ?? 0) && isset($results[0]['_id']) ? $this->compound((int) $results[0]['_id']) : null;
    }

    /** GET es_search/: null when nothing matches (ChEBI answers 404). */
    private function find(string $term, int $size, int $page = 1): ?array
    {
        return $this->getJson('es_search/', ['term' => $term, 'size' => $size, 'page' => $page > 1 ? $page : null]);
    }

    /** @param array<string, mixed> $data what compound/{id}/ answers */
    private function fromCompound(array $data): Entity
    {
        $chemical = $data['chemical_data'] ?? [];
        $structure = $data['default_structure'] ?? null;
        $names = $data['names'] ?? [];
        $iupac = self::text($names['IUPAC NAME'][0]['name'] ?? null);

        $properties = $this->chemistry([
            'formula' => $chemical['formula'] ?? null,
            'mass' => $chemical['mass'] ?? null,
            'monoisotopic_mass' => $chemical['monoisotopic_mass'] ?? null,
            'charge' => $chemical['charge'] ?? null,
            'iupac_name' => $iupac,
            'smiles' => $structure['smiles'] ?? null,
            'inchi' => $structure['standard_inchi'] ?? null,
            'inchikey' => $structure['standard_inchi_key'] ?? null,
            'stars' => $data['stars'] ?? null,
        ]);
        foreach ($data['ontology_relations']['outgoing_relations'] ?? [] as $relation) {
            $type = (string) ($relation['relation_type'] ?? '');
            $name = self::text($relation['final_name'] ?? null);
            if ('' !== $type && null !== $name) {
                $properties[] = new Property(str_replace(' ', '_', $type), ucfirst($type), $name, 'chebi');
            }
        }

        $identifiers = [];
        foreach ($data['secondary_ids'] ?? [] as $secondary) {
            $identifiers[] = Identifier::tryOf(Scheme::CHEBI, (string) $secondary);
        }
        $identifiers[] = Identifier::tryOf(Scheme::INCHIKEY, $structure['standard_inchi_key'] ?? null);
        $identifiers[] = Identifier::tryOf(Scheme::INCHI, $structure['standard_inchi'] ?? null);
        foreach ($data['database_accessions']['CAS'] ?? [] as $accession) {
            $identifiers[] = Identifier::tryOf(Scheme::CAS, $accession['accession_number'] ?? null);
        }
        // KEGG COMPOUND before KEGG DRUG, whatever order ChEBI lists them in.
        $xrefs = $data['database_accessions']['MANUAL_X_REF'] ?? [];
        usort($xrefs, static fn (array $a, array $b) => ('kegg.drug' === ($a['prefix'] ?? null)) <=> ('kegg.drug' === ($b['prefix'] ?? null)));
        foreach ($xrefs as $accession) {
            if (null !== ($scheme = self::PREFIXES[$accession['prefix'] ?? ''] ?? null)) {
                $identifiers[] = Identifier::tryOf($scheme, $accession['accession_number'] ?? null);
            }
        }

        $label = self::text($data['name'] ?? null) ?? (string) $data['chebi_accession'];
        $synonyms = [];
        foreach (self::SYNONYM_TYPES as $type) {
            foreach ($names[$type] ?? [] as $name) {
                $synonym = self::text($name['name'] ?? null);
                // Registry numbers and catalogue codes are not names: "NSC-5036", "FEMA NO. 2224".
                if (null === $synonym || (preg_match('~^[A-Z0-9]+(?:[\s:_-]+[A-Z0-9.]+)*$~', $synonym) && preg_match('~\d~', $synonym))) {
                    continue;
                }
                $synonyms[mb_strtolower($synonym)] ??= $synonym;
            }
        }
        unset($synonyms[mb_strtolower($label)]);

        return $this->toEntity((string) $data['chebi_accession'], $label, self::text($data['definition'] ?? null), $properties, $identifiers, \array_slice(array_values($synonyms), 0, $this->synonyms), null !== $structure);
    }

    /** @param array<string, mixed> $document a search result's _source */
    private function fromDocument(array $document): Entity
    {
        $properties = $this->chemistry([
            'formula' => $document['formula'] ?? null,
            'mass' => $document['mass'] ?? null,
            'monoisotopic_mass' => $document['monoisotopicmass'] ?? null,
            'charge' => $document['charge'] ?? null,
            'smiles' => $document['smiles'] ?? null,
            'inchi' => $document['inchi'] ?? null,
            'inchikey' => $document['inchikey'] ?? null,
            'stars' => $document['stars'] ?? null,
        ]);
        $identifiers = [Identifier::tryOf(Scheme::INCHIKEY, $document['inchikey'] ?? null), Identifier::tryOf(Scheme::INCHI, $document['inchi'] ?? null)];
        $label = self::text($document['name'] ?? null) ?? (string) $document['chebi_accession'];

        return $this->toEntity((string) $document['chebi_accession'], $label, self::text($document['definition'] ?? null), $properties, $identifiers, [], !empty($document['default_structure']));
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return list<Property>
     */
    private function chemistry(array $values): array
    {
        $number = static fn (mixed $v): int|float|null => is_numeric($v) ? (str_contains((string) $v, '.') ? (float) $v : (int) $v) : null;
        $properties = [];
        $add = static function (string $key, string $label, mixed $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'chebi');
            }
        };
        $add('molecular_formula', 'Molecular formula', $values['formula'] ?? null);
        $add('molecular_weight', 'Molecular weight (average mass)', null === ($v = $number($values['mass'] ?? null)) ? null : new Quantity($v, 'g/mol'));
        $add('monoisotopic_mass', 'Monoisotopic mass', null === ($v = $number($values['monoisotopic_mass'] ?? null)) ? null : new Quantity($v, 'Da'));
        $add('charge', 'Net charge', $number($values['charge'] ?? null));
        $add('iupac_name', 'IUPAC name', $values['iupac_name'] ?? null);
        $add('smiles', 'SMILES', $values['smiles'] ?? null);
        $add('inchi', 'InChI', $values['inchi'] ?? null);
        $add('inchikey', 'InChIKey', $values['inchikey'] ?? null);
        $add('chebi_stars', 'ChEBI stars (3: annotated by ChEBI\'s curators)', $number($values['stars'] ?? null));

        return $properties;
    }

    /**
     * @param list<Property>        $properties
     * @param list<Identifier|null> $identifiers
     * @param list<string>          $synonyms
     */
    private function toEntity(string $accession, string $label, ?string $definition, array $properties, array $identifiers, array $synonyms, bool $structure): Entity
    {
        $id = Identifier::chebi($accession);

        return new Entity(
            id: $id,
            label: $label,
            domain: Domain::CHEMISTRY,
            // An entry with a structure is a compound (or an ion, a group...); one without is a class or a role.
            kind: $structure ? 'compound' : 'class',
            synonyms: $synonyms,
            description: $definition,
            properties: $properties,
            identifiers: new Identifiers($identifiers),
            url: 'https://www.ebi.ac.uk/chebi/'.$id->display(),
            image: $structure ? rtrim($this->baseUri, '/').'/compound/'.$id->value.'/structure/' : null,
            source: 'chebi',
        );
    }
}
