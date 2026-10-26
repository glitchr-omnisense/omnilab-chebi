# omnilab/chebi

ChEBI for [omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): a chemical entity of
biological interest by its ChEBI id, an InChIKey, a CAS number, a KEGG id or a name; its
definition, formula, masses, charge, structure (SMILES, InChI, InChIKey, an SVG depiction), its
place in ChEBI's ontology - what it is a kind of, its roles, its functional parents, its
conjugates - and its identifiers in other bases for the Collector to follow; a search. Through the
REST API of ChEBI 2.0, no key.

```php
$chebi = (new ChebiSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$caffeine = $chebi->entity('CHEBI:27732');
$caffeine->description;                          // A trimethylxanthine in which the three methyl groups are located at positions 1, 3, and 7. ...
$caffeine->property('molecular_weight');         // 194.194 g/mol
$caffeine->properties('is_a');                   // purine alkaloid, trimethylxanthine
$caffeine->properties('has_role');               // immunosuppressive agent, mutagen, ... central nervous system stimulant ... (34)
$caffeine->identifier(Scheme::CAS);              // 58-08-2
$caffeine->image;                                // https://www.ebi.ac.uk/chebi/backend/api/public/compound/27732/structure/ (SVG)
$chebi->entity('RYYVLZVUVIJVGH-UHFFFAOYSA-N');   // the same, by its InChIKey; or '58-08-2', 'C07481', 'theine'
$chebi->search(new Query('caffeine', limit: 10));
```

```yaml
omnilab:
    sources:
        chebi:
            factory: chebi
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent: whom the EBI writes to before it blocks a caller
                synonyms: 20                   # how many to keep; 0: none
```

With PubChem and Wikidata configured too, the `Collector` follows a compound from one to the
others: `$collector->collect('pubchem', 'caffeine')` reaches ChEBI by caffeine's InChIKey.

## Terms of use

ChEBI is run by EMBL-EBI, free and without a key. Its data is under
[CC BY 4.0](https://creativecommons.org/licenses/by/4.0/) - cite ChEBI where you show it - and
governed by [EMBL-EBI's terms of use](https://www.ebi.ac.uk/about/terms-of-use), which reserve
the right to block a client that loads the services unreasonably. No rate is published for
ChEBI's API: the source makes its calls in series, one per entity by ChEBI id, two otherwise;
cache what it answers.

See [docs/](docs/index.md) for what is read, how it maps, and what ChEBI does not give here.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by EMBL-EBI (ChEBI). It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
