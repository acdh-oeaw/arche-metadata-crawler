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
use acdhOeaw\uriNormalizer\UriNormalizer;
use acdhOeaw\uriNormalizer\UriNormalizerResolveConfig;
use acdhOeaw\uriNormalizer\UriNormalizerCache;
use acdhOeaw\uriNormalizer\UriNormalizerException;
use acdhOeaw\UriNormRules;
use zozlak\RdfConstants as RDF;

/**
 * Description of MetadataChecker
 *
 * @author zozlak
 */
class MetadataChecker {

    const URI_NORMALIZER_TTL = 'P3D';

    private string $cacheDir;
    private UriNormalizerResolveConfig $resolveCfg;
    private DatasetInterface $meta;

    public function __construct(private Ontology $ontology,
                                private Schema $schema,
                                private LoggerInterface | null $log = null,
                                UriNormalizerResolveConfig | null $resolveCfg = null,
                                string $cacheDir = '') {
        $this->cacheDir = !empty($cacheDir) ? $cacheDir : sys_get_temp_dir();

        $resolveCfg       ??= new UriNormalizerResolveConfig(3, 2, UriNormalizerResolveConfig::SCALE_POWER, ttl: self::URI_NORMALIZER_TTL);
        $this->resolveCfg = $resolveCfg;
    }

    public function check(DatasetInterface $meta, bool $reportProgress = true): bool {
        $resolveCfg = null;
        $cacheDir   = sys_get_temp_dir();

        $this->meta = $meta;
        $classTmpl  = new PT(DF::namedNode(RDF::RDF_TYPE));
        $noErrors   = true;
        $sbjs       = iterator_to_array($this->meta->listSubjects());
        $N          = count($sbjs);
        $doorkeeper = null;
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
                $tmp = new DatasetNode($sbj);
                if ($doorkeeper === null) {
                    $doorkeeper = new Doorkeeper($tmp->withDataset($sbjMeta), $this->schema, $this->ontology, null, $this->log, $this->resolveCfg, $this->cacheDir);
                } else {
                    $doorkeeper->setResource($tmp);
                }
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
            if ($error instanceof UriNormalizerException && $error->getUri() !== null) {
                $sbj = DF::namedNode(urldecode($error->getUri()));
                if ($meta->any(new QT($sbj)) || $meta->any(new PT($this->schema->id, $sbj))) {
                    $this->log?->debug("Skipping the unresolvable $sbj error because it's defined locally");
                    unset($errors[$i]);
                }
            }
        }
    }
}
