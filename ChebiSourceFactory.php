<?php

namespace Omnilab\Chebi;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * ChEBI: chemical entities of biological interest, their structure, their
 * ontology (classes, roles) and cross-references - no key needed.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent: whom the EBI writes to before it blocks a caller
 *     synonyms: 20                     # how many synonyms to keep (0: none)
 *     throttle: 0                      # seconds between two calls: the EBI publishes no figure for ChEBI
 *     base_uri: 'https://www.ebi.ac.uk/chebi/backend/api/public/'
 */
final class ChebiSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'chebi',
            'omnilab.required_options' => [],
            'synonyms' => 20,
            'throttle' => 0.0,
            'base_uri' => ChebiSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new ChebiSource(
            $this->http ?? HttpClient::create(),
            (int) $c->get('synonyms'),
            (string) $c->get('base_uri', ChebiSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
