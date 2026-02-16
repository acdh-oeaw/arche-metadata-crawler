<?php

/*
 * The MIT License
 *
 * Copyright 2023 Austrian Centre for Digital Humanities.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace acdhOeaw\arche\metadataCrawler;

use Psr\Log\LoggerInterface;
use zozlak\ProxyClient;
use rdfInterface\DatasetInterface;
use rdfInterface\NamedNodeInterface;
use quickRdf\DataFactory as DF;
use quickRdf\Literal;
use quickRdf\NamedNode;
use quickRdf\DatasetNode;
use termTemplates\QuadTemplate as QT;
use termTemplates\PredicateTemplate as PT;
use termTemplates\NotTemplate as NT;
use termTemplates\AnyOfTemplate as AT;
use acdhOeaw\arche\lib\schema\Ontology;
use acdhOeaw\arche\lib\schema\ClassDesc;
use acdhOeaw\arche\lib\schema\PropertyDesc;
use acdhOeaw\arche\lib\Schema;
use acdhOeaw\arche\doorkeeper\DoorkeeperException;
use acdhOeaw\arche\doorkeeper\PreCheckAttribute;
use acdhOeaw\arche\doorkeeper\CheckAttribute;
use acdhOeaw\arche\doorkeeper\Resource as Doorkeeper;
use acdhOeaw\UriNormalizer;
use acdhOeaw\UriNormalizerRetryConfig;
use acdhOeaw\UriNormalizerCache;
use acdhOeaw\UriNormalizerException;
use acdhOeaw\UriNormRules;
use zozlak\RdfConstants as RDF;

/**
 * Description of MetadataChecker
 *
 * @author zozlak
 */
class MetadataChecker {

    private Ontology $ontology;
    private Schema $schema;
    private LoggerInterface | null $log;

    /**
     * 
     * @var array<string, UriNormalizer>
     * @phpstan-ignore property.onlyWritten
     */
    private array $normalizers;

    /**
     * 
     * @var array<string, array<string>>
     * @phpstan-ignore property.onlyWritten
     */
    private array $checkRanges;

    /**
     * 
     * @var array<string, array<string, string>>
     * @phpstan-ignore property.onlyWritten
     */
    private array $vocabularies;
    private DatasetInterface $meta;

    public function __construct(Ontology $ontology, Schema $schema,
                                LoggerInterface | null $log = null,
                                ?UriNormalizerRetryConfig $retryCfg = null) {
        $this->ontology = $ontology;
        $this->schema   = $schema;
        $this->log      = $log;

        $this->checkRanges = [];
        foreach ($this->schema->checkRanges ?? [] as $range => $nmsps) {
            $this->checkRanges[$range] = array_map(fn($x) => (string) $x, iterator_to_array($nmsps));
        }

        $client            = ProxyClient::factory();
        $cache             = new UriNormalizerCache();
        $retryCfg          ??= new UriNormalizerRetryConfig(3, 2, UriNormalizerRetryConfig::SCALE_POWER);
        $this->normalizers = [
            '' => new UriNormalizer(cache: $cache, retryCfg: $retryCfg),
        ];
        /** @phpstan-ignore property.notFound */
        foreach ($schema->checkRanges as $class => $ranges) {
            $rules                     = UriNormRules::getRules(array_map(fn($x) => (string) $x, iterator_to_array($ranges)));
            $this->normalizers[$class] = new UriNormalizer($rules, '', $client, $cache, retryCfg: $retryCfg);
        }

        foreach ($this->ontology->getProperties() as $propDesc) {
            if (!empty($propDesc->vocabs)) {
                $tmp = [];
                /** @phpstan-ignore property.private */
                foreach ($propDesc->vocabularyValues as $concept) {
                    foreach ($concept->concept as $id) {
                        $tmp[$id] = $concept->uri;
                    }
                }
                $this->vocabularies[$propDesc->uri] = $tmp;
            }
        }
    }

    public function check(DatasetInterface $meta, bool $reportProgress = true): bool {
        $this->meta = $meta;
        $classTmpl  = new PT(DF::namedNode(RDF::RDF_TYPE));
        $noErrors   = true;
        $sbjs       = iterator_to_array($this->meta->listSubjects());
        $N          = count($sbjs);
        foreach ($sbjs as $n => $sbj) {
            if ($n % 10 === 0) {
                $msg = "Check progress: $n/$N " . round(100 * $n / $N, 1) . "%";
                $reportProgress ? $this->log?->info($msg) : $this->log?->debug($msg);
            }
            $errors = [];

            $sbjMeta    = $meta->copy(new QT($sbj));
            $sbjClasses = $sbjMeta->listObjects($classTmpl)->getValues();
            if (count($sbjClasses) === 0) {
                $errors[] = "rdf:type property missing";
            } elseif (count($sbjClasses) > 1) {
                $errors[] = "multiple rdf:types: " . implode(', ', $sbjClasses);
            } else {
                $tmp           = new DatasetNode($sbj);
                $doorkeeper    = new Doorkeeper($tmp->withDataset($sbjMeta), $this->schema, $this->ontology, null, $this->log);
                $doorkeeperErr = array_merge(
                    $doorkeeper->runTests(PreCheckAttribute::class, throwException: false),
                    $doorkeeper->runTests(CheckAttribute::class, throwException: false)
                );
                $this->checkForLocalEntities($doorkeeperErr, $meta);
                $doorkeeperErr = array_map(fn($x) => $x->getMessage(), $doorkeeperErr);
                $errors        = array_merge($errors, $doorkeeperErr);
            }

            if (count($errors) > 0) {
                $noErrors = false;
                foreach ($errors as $e) {
                    $this->log?->error($sbj . ';"' . str_replace('"', '""', $e) . '"');
                }
            }
        }
        return $noErrors;
    }

    /**
     * Removes "Failed to fetch RDF data from {URI}" errors related to locally defined entities
     * (having a given subject or identifier).
     * 
     * Doesnt' check if entities themselves are valid (but this is checked in the check() method loop)
     * 
     * @param array<DoorkeeperException> $errors
     */
    private function checkForLocalEntities(array &$errors,
                                           DatasetInterface $meta): void {
        for ($i = 0; $i < count($errors); $i++) {
            $error = $errors[$i]->getPrevious();
            if ($error instanceof UriNormalizerException && str_starts_with($error->getMessage(), 'Failed to fetch data from ')) {
                $sbj = DF::namedNode(urldecode(preg_replace('/ .*/', '', str_replace('Failed to fetch RDF data from ', '', $error->getMessage()))));
                if ($meta->any(new QT($sbj)) || $meta->any(new PT($this->schema->id, $sbj))) {
                    $this->log?->debug("Skipping the unresolvable $sbj error because it's defined locally");
                    unset($errors[$i]);
                }
            }
        }
    }
}
