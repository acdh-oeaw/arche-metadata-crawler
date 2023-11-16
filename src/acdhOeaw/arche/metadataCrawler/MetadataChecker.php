<?php

/*
 * The MIT License
 *
 * Copyright 2023 zozlak.
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
use quickRdf\Dataset;
use rdfInterface\DatasetInterface;
use quickRdf\DataFactory as DF;
use quickRdf\Literal;
use quickRdf\NamedNode;
use termTemplates\QuadTemplate as QT;
use termTemplates\PredicateTemplate as PT;
use acdhOeaw\arche\lib\schema\Ontology;
use acdhOeaw\arche\lib\schema\ClassDesc;
use acdhOeaw\arche\lib\schema\PropertyDesc;
use acdhOeaw\arche\lib\Schema;
use zozlak\RdfConstants as RDF;

/**
 * Description of MetadataChecker
 *
 * @author zozlak
 */
class MetadataChecker {

    private Ontology $ontology;
    private Schema $schema;
    private LoggerInterface $log;

    public function __construct(Ontology $ontology, Schema $schema,
                                LoggerInterface | null $log = null) {
        $this->ontology = $ontology;
        $this->schema   = $schema;
        $this->log      = $log;
    }

    /**
     * 
     * @param iterable<QuadInterface> $metaIter
     * @return void
     */
    public function check(iterable $metaIter): void {
        $classTmpl = new PT(DF::namedNode(RDF::RDF_TYPE));
        $meta      = new Dataset();
        $meta->add($metaIter);
        foreach ($meta->listSubjects() as $sbj) {
            $errors = [];

            $sbjMeta    = $meta->copy(new QT($sbj));
            $sbjClasses = $sbjMeta->listObjects($classTmpl)->getValues();
            if (count($sbjClasses) === 0) {
                $errors[] = "rdf:type property missing";
            }
            foreach ($sbjClasses as $class) {
                $classDesc = $this->ontology->getClass($class);
                $this->checkClass($sbjMeta, $classDesc, $errors);
            }

            if (count($errors) > 0) {
                $this->log->error("$sbj errors: \n" . print_r($errors, true));
            }
        }
    }

    public function checkClass(DatasetInterface $sbjMeta,
                               ClassDesc | string $class, array &$errors): void {
        $classDesc     = $class instanceof ClassDesc ? $class : $this->ontology->getClass($class);
        $sbjProperties = $sbjMeta->listPredicates()->getValues();
        $sbjProperties = array_filter($sbjProperties, fn($x) => (string) $x !== RDF::RDF_TYPE);

        // required by class
        $missing = array_filter(
            $classDesc->getProperties(),
            fn(PropertyDesc $x) => $x->min > 0 && !$x->automatedFill && count(array_intersect($x->property, $sbjProperties)) === 0
        );
        foreach ($missing as $i) {
            $errors[] = "required property $i->uri is missing";
        }

        // existing properties
        foreach ($sbjProperties as $sbjProp) {
            if (!isset($classDesc->properties[(string) $sbjProp])) {
                $errors[] = "unknown property $sbjProp used";
                continue;
            }
            /* @var $propDesc PropertyDesc */
            $propDesc  = $classDesc->properties[(string) $sbjProp];
            $sbjValues = $sbjMeta->listObjects(new PT($sbjProp));
            $langs     = [];
            foreach ($sbjValues as $value) {
                $valueType = $value instanceof Literal ? RDF::OWL_DATATYPE_PROPERTY : RDF::OWL_OBJECT_PROPERTY;
                if ($propDesc->type != $valueType) {
                    $errors[] = "wrong type of value for a $valueType property $propDesc->uri";
                    continue;
                }
                if ($propDesc->langTag && $value instanceof Literal) {
                    $lang = (string) $value->getLang();
                    if ($lang === '') {
                        $errors[] = "value $value of property $propDesc->uri misses the language tag";
                    } elseif (isset($langs[$lang]) && $propDesc->max === 1) {
                        $errors[] = "value $value of property $propDesc->uri has duplicated lang tag $lang";
                    }
                    $langs[$lang] = true;
                }
                if ($propDesc->type === RDF::OWL_OBJECT_PROPERTY) {
                    $this->checkNamedEntity($value, $errors);
                }
            }
        }
    }

    private function checkNamedEntity(NamedNode $value, array &$errors): void {
        //TODO
    }
}
