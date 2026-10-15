# omnilab/chebi

## Installation

```sh
composer require omnilab/chebi
```

```php
use Omnilab\Chebi\ChebiSourceFactory;

$chebi = (new ChebiSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // optional
    'synonyms' => 20,                  // optional
]);
```

With the Symfony bundle, the factory is registered as `chebi` (see the core's docs).

## Which API

ChEBI 2.0 answers through a REST API, `https://www.ebi.ac.uk/chebi/backend/api/public/`,
described by an OpenAPI schema (`/chebi/backend/api/schema/`, version 2.0.0, browsable at
`/chebi/backend/api/docs/`). The SOAP web service of ChEBI 1 is gone: its WSDL answers 500. The
EBI's Ontology Lookup Service (OLS) loads ChEBI too, as an ontology; it is not used here: ChEBI's
own API gives a whole entry - chemistry, ontology and cross-references - in one call.

## Calls

| Method | Reads | ChEBI 2.0 |
|---|---|---|
| `entity()` | a ChEBI id (`CHEBI:27732`, `27732`, `chebi:27732`, ChEBI's page), a secondary id too | `compound/{id}/` |
| | an InChIKey | `es_search/?term=` first; the result whose InChIKey is that one |
| | a CAS number, a KEGG id (`C07481`, `cpd:C07481`, `D00528`) | `es_search/?term=` first; its first result, kept when it carries that identifier |
| | a name | `es_search/?term=` first; the first result of that name, whatever its case, else the only result (a synonym: `theine`) |
| `search()` | `Query::$text`; a domain other than chemistry answers nothing | `es_search/?term=&size=&page=` |

A secondary id gives its primary entry (`CHEBI:3295` is caffeine, `chebi:27732`). An identifier
of a scheme ChEBI does not read (a PubChem CID, ChEMBL, Wikidata, UniProt...) is refused before
any call; unknown is `null`. A name the search finds several entries for, none of that name, is
`null` rather than a guess.

## What an entity holds

`id` (its ChEBI id), `label` (ChEBI's name), `domain` chemistry, `kind` `compound` when it has a
structure and `class` when it has none (a class such as "purine alkaloid", a role), `description`
(ChEBI's definition), `url` (its page, `https://www.ebi.ac.uk/chebi/CHEBI:27732`), `image` (the SVG
of its structure: `Capability::STRUCTURE`; null without a structure), `synonyms` (ChEBI's synonyms
and INNs, not its brand names nor registry codes, 20 by default; the markup ChEBI writes in names,
`1<em>H</em>`, taken out) and these properties:

| Key | ChEBI | Value |
|---|---|---|
| `molecular_formula` | chemical_data.formula | text |
| `molecular_weight` | chemical_data.mass (the average mass) | Quantity, g/mol |
| `monoisotopic_mass` | chemical_data.monoisotopic_mass | Quantity, Da |
| `charge` | chemical_data.charge | number |
| `iupac_name` | the IUPAC NAME | text |
| `smiles`, `inchi`, `inchikey` | the default structure | text |
| `chebi_stars` | stars: 3 for an entry ChEBI's curators annotated | number |
| `is_a`, `has_role`, `has_functional_parent`, `is_conjugate_acid_of`, `is_conjugate_base_of`... | the ontology's outgoing relations, each type a key | text: the name of the related entry |

A key holds every relation of its type, in ChEBI's order (`properties('has_role')`: caffeine's 34
roles). Only their names are given, not their ChEBI ids.

`identifiers`: the secondary ChEBI ids, the InChIKey and the InChI, the CAS numbers, then the
cross-references ChEBI keeps of a scheme of the core - KEGG COMPOUND before KEGG DRUG, ChEMBL,
PubChem, Wikidata, UniProt when an entry has them. Caffeine's are its CAS 58-08-2 and its KEGG
C07481 and D00528: ChEBI 2.0 gives no PubChem CID nor Wikidata item for it. The Wikidata source
finds the item by the ChEBI id or the InChIKey.

A search result holds the name, the definition, the formula, the masses, the charge and the
structure: `entity()` reads the rest.

## What ChEBI does not give here

The citations, the origins (the species a compound was found in), the registry numbers of
Reaxys and Gmelin, the cross-references of schemes the core does not know (HMDB, DrugBank,
MetaCyc...), the incoming relations (what has caffeine as functional parent) and the other
structures than the default one are not read. Not read either: the advanced search (by formula,
mass, charge, ontology), the structure search, the children of a class.

## Verified, and not

Against the real service on 2026-10-15, without a key: caffeine by its ChEBI id, by a secondary id
(CHEBI:3295), by its InChIKey, its CAS number, its KEGG COMPOUND id and the synonym "theine"; its
masses, classes and 34 roles; a class without a structure (CHEBI:26385, purine alkaloid); an
unknown id (404) and an unknown name (404 from the search); a search for "caffeine" (18 entries,
6 pages of 3) and its second page; the SVG of the structure (200, `image/svg+xml`); the old
`searchId.do` page answering 301 to the new one. The fixtures in `Tests/Fixtures/` are its answers,
caffeine's 117 citations cut to 2.

Not verified: ChEBI's answer to a client that calls too fast (no 429 met, no rate published - the
handling is the core's); a page asked past the last one, which did not answer within two minutes:
the source never asks it (`next` stops at `number_pages`); an entry that is not released.
