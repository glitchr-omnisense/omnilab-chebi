# omnilab/chebi

ChEBI 2.0 (Chemical Entities of Biological Interest, EMBL-EBI), through its REST API
(`https://www.ebi.ac.uk/chebi/backend/api/public/`), for [omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab):
chemical entities by ChEBI id, InChIKey, CAS number, KEGG id or name - masses, structure, ontology
(classes, roles), cross-references - as entities; a search. No key.

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

## Credentials

None: ChEBI's REST API takes no key and no account. Give `mailto`, the address the EBI writes to
before it blocks a caller.

## Options

The factory's options, with the defaults it applies (`ChebiSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `synonyms` | `int` | `20` | no | how many synonyms to keep (ChEBI's synonyms and INNs, not its brand names nor registry codes); 0: none |
| `mailto` | `?string` | null | no | a contact address, in the User-Agent: whom the EBI writes to before it blocks a caller |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0` | no | seconds left between two calls: the EBI publishes no figure for ChEBI |
| `base_uri` | `string` | `https://www.ebi.ac.uk/chebi/backend/api/public/` | no | the API the source calls |

## Capabilities

`capabilities()` answers `entity`, `search`, `structure`, `cross_references`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | yes | a ChEBI id (primary or secondary, `CHEBI:27732`, `27732`, its page): one call; an InChIKey, a CAS number, a KEGG id or a name: the search first, two calls; another scheme refused before any call |
| `search` | yes | ChEBI's search, 100 a page at most, with a `total`; a page's entities hold the chemistry, `entity()` the rest; a domain other than chemistry answers an empty page |
| `series` | no |  |
| `products` | no |  |
| `occurrences` | no |  |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | yes | the image is the SVG of its default structure (null for a class without one) |
| `cross_references` | yes | the secondary ChEBI ids, the InChIKey and InChI, the CAS numbers, then KEGG, ChEMBL, PubChem, Wikidata and UniProt ids when the entry keeps them |
| `forecast` | no |  |
| `historical` | no |  |
| `area` | no |  |
| `station` | no |  |
| `cloud_cover` | no |  |

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

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Chebi\ChebiSourceFactory;
use Omnilab\Model\Scheme;
use Omnilab\Source\Query;
use Symfony\Component\HttpClient\HttpClient;

$chebi = (new ChebiSourceFactory(HttpClient::create()))->create(['mailto' => 'you@example.org', 'synonyms' => 3]);

$caffeine = $chebi->entity('CHEBI:27732');
echo $caffeine->label, ' (', $caffeine->id->display(), ', ', $caffeine->value('chebi_stars'), ' stars): ', $caffeine->value('molecular_formula'), ', ', $caffeine->property('molecular_weight'), "\n";
echo '  is a: ', implode(', ', array_map('strval', $caffeine->properties('is_a'))), '; ', count($caffeine->properties('has_role')), " roles\n";
echo '  CAS ', $caffeine->identifier(Scheme::CAS), ', KEGG ', implode(', ', $caffeine->identifiers()->values(Scheme::KEGG)), "\n";
echo 'theine is ', $chebi->entity('theine')?->id->display(), "\n";

$page = $chebi->search(new Query('caffeine', limit: 3));
printf("%d entries for \"caffeine\"; the first %d:\n", $page->total, count($page));
foreach ($page as $entry) {
    echo '  ', $entry->id->display(), '  ', $entry->label, "\n";
}
```

```
caffeine (CHEBI:27732, 3 stars): C8H10N4O2, 194.194 g/mol
  is a: purine alkaloid, trimethylxanthine; 34 roles
  CAS 58-08-2, KEGG C07481, D00528
theine is CHEBI:27732
18 entries for "caffeine"; the first 3:
  CHEBI:27732  caffeine
  CHEBI:749278  caffeine citrate
  CHEBI:32140  sodium caffeine benzoate
```

(as answered on 2026-10-27: five calls)

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        chebi: { factory: chebi, options: { mailto: '%env(OMNILAB_MAILTO)%', synonyms: 20 } }
```

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

ChEBI's own: an unknown id is its 404, and a search that finds nothing its 404 too: null, an empty
page. A name the search finds several entries for, none of that name, is null rather than a
guess. Before any call: an identifier of a scheme ChEBI does not read (a PubChem CID, ChEMBL,
Wikidata, UniProt...) (`NotSupportedException`).

## Verified, and not

Against the real service on 2026-10-15, without a key: caffeine by its ChEBI id, by a secondary id
(CHEBI:3295), by its InChIKey, its CAS number, its KEGG COMPOUND id and the synonym "theine"; its
masses, classes and 34 roles; a class without a structure (CHEBI:26385, purine alkaloid); an
unknown id (404) and an unknown name (404 from the search); a search for "caffeine" (18 entries,
6 pages of 3) and its second page; the SVG of the structure (200, `image/svg+xml`); the old
`searchId.do` page answering 301 to the new one. The fixtures in `Tests/Fixtures/` are its answers,
caffeine's 117 citations cut to 2.

Again on 2026-10-27: caffeine by its ChEBI id (its classes, 34 roles, CAS and KEGG ids), the
synonym "theine", the search for "caffeine" (18 entries; the example above).

Not verified: ChEBI's answer to a client that calls too fast (no 429 met, no rate published - the
handling is the core's); a page asked past the last one, which did not answer within two minutes:
the source never asks it (`next` stops at `number_pages`); an entry that is not released.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by ChEBI or the EMBL-EBI. ChEBI's data are under
[CC BY 4.0](https://creativecommons.org/licenses/by/4.0/) - cite ChEBI where they are shown - and
the [EMBL-EBI's terms of use](https://www.ebi.ac.uk/about/terms-of-use) reserve the right to
block a client that loads the services unreasonably, as the README's
[Terms of use](https://github.com/glitchr-omnisense/omnilab-chebi#terms-of-use) says. Whoever runs
the package is responsible for complying with them and for any use made of what it returns: see
the README's [Responsibility](https://github.com/glitchr-omnisense/omnilab-chebi#responsibility)
section.
