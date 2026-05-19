<?php

/*
 * The MIT License
 *
 * Copyright 2026 zozlak.
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

use PDO;
use Throwable;
use acdhOeaw\arche\lib\Repo;
use acdhOeaw\arche\lib\RepoDb;
use acdhOeaw\arche\lib\RepoInterface;
use acdhOeaw\arche\lib\schema\Ontology;

/**
 * Description of InitUtil
 *
 * @author zozlak
 */
class InitUtil {

    /**
     * Tries to initialized Repo and Ontology using a direct database connection
     * with connection parameters read from the ARCHE_DB_CONN_STR env var.
     * 
     * If the direct database connection initiliazation fails, returns Repo and
     * Ontology objects using the REST interface.
     * 
     * @return array{0: RepoInterface, 1: Ontology}
     */
    static public function getRepoOntology(string $repositoryUrl,
                                           string $ontologyCacheFile = '',
                                           int $ontologyCacheTtl = 36000,
                                           bool $forceRest = false): array {
        if (empty($ontologyCacheFile)) {
            $ontologyCacheFile = sys_get_temp_dir() . '/ontology.cache';
        }

        $repo     = null;
        $ontology = null;

        try {
            $dbConn = getenv('ARCHE_DB_CONN_STR');
            if (!empty($dbConn) && !$forceRest) {
                $pdo = new PDO("pgsql: $dbConn");
                $pdo->query("SET application_name TO metadata_crawler");

                $repo = RepoDb::factoryFromUrl($repositoryUrl, $pdo);

                $schema   = $repo->getSchema();
                $schema   = (object) [
                        'ontologyNamespace' => (string) $schema->namespaces->ontology,
                        'parent'            => (string) $schema->parent,
                        'label'             => (string) $schema->label,
                ];
                $ontology = Ontology::factoryDb($pdo, $schema, $ontologyCacheFile, $ontologyCacheTtl);
            }
        } catch (Throwable $e) {
            
        }

        $repo     ??= Repo::factoryFromUrl($repositoryUrl);
        $ontology ??= Ontology::factoryRest($repositoryUrl, $ontologyCacheFile, $ontologyCacheTtl);
        return [$repo, $ontology];
    }
}
