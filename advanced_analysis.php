<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/user_context.php';

// Si appelé directement en GET (endpoint AJAX)
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'advanced_analysis.php' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    ini_set('display_errors', '0');
    ini_set('memory_limit', '512M');
    set_time_limit(120);
    error_reporting(0);

    $importId = $_GET['importId'] ?? '';
    $type = $_GET['type'] ?? '';

    if (!validerJobId($importId)) {
        repondreErreur([
            'fr' => 'Import ID invalide.',
            'en' => 'Invalid import ID.',
        ], 400);
    }

    $userId = verifierProprietaire($importId);
    $cheminSqlite = cheminImport($userId, $importId) . '/liens.sqlite';

    if (!file_exists($cheminSqlite)) {
        repondreErreur([
            'fr' => 'Base de données introuvable.',
            'en' => 'Database not found.',
        ], 404);
    }

    $typesValides = ['orphelins', 'diversite_ancres', 'hubs_autorites', 'distribution_sections', 'pagerank', 'dashboard', 'liste_ancres', 'cannibale_auto', 'detail_ancre'];
    if (!in_array($type, $typesValides, true)) {
        $valeursStr = implode(', ', $typesValides);
        repondreErreur([
            'fr' => 'Type d\'analyse invalide. Valeurs : ' . $valeursStr,
            'en' => 'Invalid analysis type. Values: ' . $valeursStr,
        ]);
    }

    if ($type === 'detail_ancre') {
        $ancre = $_GET['ancre'] ?? '';
        if ($ancre === '') {
            repondreErreur([
                'fr' => 'Paramètre "ancre" requis.',
                'en' => 'Parameter "ancre" required.',
            ]);
        }
        $db = new PDO('sqlite:' . $cheminSqlite);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        repondreJson(analyserDetailAncre($db, $ancre));
    }

    $resultats = executerAnalyse($cheminSqlite, $type);
    repondreJson($resultats);
}

// ══════════════════════════════════════════════════════════════════════
//  FONCTIONS D'ANALYSE
// ══════════════════════════════════════════════════════════════════════

/**
 * Exécute une analyse avancée et retourne les résultats.
 *
 * @return array<string, mixed>
 */
function executerAnalyse(string $cheminSqlite, string $type): array
{
    $db = new PDO('sqlite:' . $cheminSqlite);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode = WAL');

    return match ($type) {
        'orphelins' => analyserOrphelins($db),
        'diversite_ancres' => analyserDiversiteAncres($db),
        'hubs_autorites' => analyserHubsAutorites($db),
        'distribution_sections' => analyserDistributionSections($db),
        'pagerank' => analyserPageRank($db),
        'dashboard' => analyserDashboard($db),
        'liste_ancres' => analyserListeAncres($db),
        'cannibale_auto' => analyserCannibalisationAuto($db),
    };
}

// ══════════════════════════════════════════════════════════════════════
//  HELPERS
// ══════════════════════════════════════════════════════════════════════

/**
 * Classe une ancre selon sa nature.
 */
function classerAncre(string $ancre): string
{
    $ancreNorm = trim($ancre);

    if ($ancreNorm === '') {
        return 'vide';
    }

    $ancresGeneriques = ['cliquez ici', 'en savoir plus', 'lire la suite', 'voir plus',
        'click here', 'read more', 'learn more', 'ici', 'lien', 'link', 'plus'];

    if (in_array(mb_strtolower($ancreNorm), $ancresGeneriques, true)) {
        return 'generique';
    }

    if (str_starts_with($ancreNorm, 'http://') || str_starts_with($ancreNorm, 'https://') || str_starts_with($ancreNorm, 'www.')) {
        return 'url_nue';
    }

    $nbMots = str_word_count($ancreNorm);
    if ($nbMots <= 1) {
        return 'mot_cle';
    }

    return 'descriptif';
}

/**
 * Détecte la homepage du site dans la base de liens.
 */
function detecterHomepage(PDO $db): string
{
    $stmt = $db->query("
        SELECT url FROM (
            SELECT source as url FROM liens UNION SELECT destination FROM liens
        ) WHERE url LIKE '%/' OR url NOT LIKE '%/%/%'
        ORDER BY LENGTH(url) ASC
        LIMIT 50
    ");
    $candidats = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($candidats as $url) {
        $chemin = parse_url($url, PHP_URL_PATH) ?: '';
        if ($chemin === '/' || $chemin === '') {
            return $url;
        }
    }

    // Fallback : page avec le plus de liens combinés
    $stmt = $db->query("
        SELECT url, (COALESCE(s.cnt, 0) + COALESCE(e.cnt, 0)) as total
        FROM (SELECT source as url FROM liens UNION SELECT destination FROM liens) pages
        LEFT JOIN (SELECT source, COUNT(*) as cnt FROM liens GROUP BY source) s ON s.source = pages.url
        LEFT JOIN (SELECT destination, COUNT(*) as cnt FROM liens GROUP BY destination) e ON e.destination = pages.url
        ORDER BY total DESC
        LIMIT 1
    ");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    return $result ? $result['url'] : '';
}

/**
 * Calcule la profondeur des pages par BFS depuis la homepage.
 *
 * @return array{distribution: array<int|string, int>, moyenne: float, max: int, inaccessibles: int}
 */
function calculerProfondeur(PDO $db, string $homepage): array
{
    $nbPagesTotal = (int) $db->query('
        SELECT COUNT(*) FROM (SELECT source as url FROM liens UNION SELECT destination FROM liens)
    ')->fetchColumn();

    if ($homepage === '' || $nbPagesTotal === 0) {
        return ['distribution' => [], 'moyenne' => 0.0, 'max' => 0, 'inaccessibles' => $nbPagesTotal];
    }

    // Charger le graphe sortant
    $stmt = $db->query('SELECT source, destination FROM liens GROUP BY source, destination');
    $graphe = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $graphe[$row['source']][] = $row['destination'];
    }

    // BFS
    $profondeurs = [$homepage => 0];
    $file = [$homepage];
    $limiteNoeuds = 50000;
    $noeudsVisites = 1;

    while (!empty($file) && $noeudsVisites < $limiteNoeuds) {
        $page = array_shift($file);
        $profCourante = $profondeurs[$page];

        foreach ($graphe[$page] ?? [] as $voisin) {
            if (!isset($profondeurs[$voisin])) {
                $profondeurs[$voisin] = $profCourante + 1;
                $file[] = $voisin;
                $noeudsVisites++;
                if ($noeudsVisites >= $limiteNoeuds) {
                    break;
                }
            }
        }
    }

    // Distribution
    $distribution = [];
    $somme = 0;
    $maxProf = 0;
    foreach ($profondeurs as $prof) {
        if ($prof === 0) {
            continue;
        }
        $cle = $prof >= 4 ? '4+' : $prof;
        $distribution[$cle] = ($distribution[$cle] ?? 0) + 1;
        $somme += $prof;
        if ($prof > $maxProf) {
            $maxProf = $prof;
        }
    }

    $nbAccessibles = count($profondeurs);
    $nbAvecProfondeur = $nbAccessibles - 1; // Exclure la homepage (prof 0)
    $moyenne = $nbAvecProfondeur > 0 ? round($somme / $nbAvecProfondeur, 2) : 0.0;

    ksort($distribution);

    return [
        'distribution' => $distribution,
        'moyenne' => $moyenne,
        'max' => $maxProf,
        'inaccessibles' => $nbPagesTotal - $nbAccessibles,
    ];
}

// ── Pages orphelines ─────────────────────────────────────────────────

/**
 * @return array<string, mixed>
 */
function analyserOrphelins(PDO $db): array
{
    // Pages qui émettent des liens mais n'en reçoivent aucun
    $stmt = $db->query('
        SELECT source as url, COUNT(*) as nb_liens_sortants
        FROM liens
        WHERE source NOT IN (SELECT DISTINCT destination FROM liens)
        GROUP BY source
        ORDER BY nb_liens_sortants DESC
        LIMIT 5000
    ');
    $orphelins = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $nbTotal = (int) $db->query('
        SELECT COUNT(DISTINCT source) FROM liens
        WHERE source NOT IN (SELECT DISTINCT destination FROM liens)
    ')->fetchColumn();

    $nbPagesTotal = (int) $db->query('
        SELECT COUNT(*) FROM (
            SELECT source as url FROM liens UNION SELECT destination FROM liens
        )
    ')->fetchColumn();

    // Ajouter la section à chaque orphelin
    foreach ($orphelins as &$orphelin) {
        $orphelin['section'] = extraireSection($orphelin['url']);
    }
    unset($orphelin);

    // Quasi-orphelins : pages avec 1 ou 2 liens entrants
    $nbQuasiOrphelins = (int) $db->query('
        SELECT COUNT(*) FROM (
            SELECT destination FROM liens GROUP BY destination HAVING COUNT(*) BETWEEN 1 AND 2
        )
    ')->fetchColumn();

    $stmtQuasi = $db->query('
        SELECT destination as url, COUNT(*) as nb_liens_entrants
        FROM liens
        GROUP BY destination
        HAVING nb_liens_entrants BETWEEN 1 AND 2
        ORDER BY nb_liens_entrants ASC
        LIMIT 200
    ');
    $quasiOrphelins = $stmtQuasi->fetchAll(PDO::FETCH_ASSOC);

    foreach ($quasiOrphelins as &$quasi) {
        $quasi['section'] = extraireSection($quasi['url']);
    }
    unset($quasi);

    // Sections disponibles (union des sections orphelins + quasi)
    $sectionsSet = [];
    foreach ($orphelins as $o) {
        $sectionsSet[$o['section']] = true;
    }
    foreach ($quasiOrphelins as $q) {
        $sectionsSet[$q['section']] = true;
    }
    $sectionsDisponibles = array_keys($sectionsSet);
    sort($sectionsDisponibles);

    // Suggestions : pour les 20 premiers orphelins, trouver le meilleur hub de la même section
    $suggestions = [];
    $stmtHub = $db->prepare('
        SELECT source as url, COUNT(*) as nb_liens
        FROM liens
        WHERE source LIKE :pattern
        GROUP BY source
        ORDER BY nb_liens DESC
        LIMIT 1
    ');

    $orphelinsTop = array_slice($orphelins, 0, 20);
    foreach ($orphelinsTop as $orphelin) {
        $section = $orphelin['section'];
        $chemin = parse_url($orphelin['url'], PHP_URL_HOST) ?? '';
        $pattern = '%' . ($section !== '/' ? $section . '%' : '');

        // Trouver le hub de la même section
        $stmtHubSection = $db->prepare('
            SELECT s.source as url, COUNT(*) as nb_liens
            FROM liens s
            WHERE s.source != :orpheline
            GROUP BY s.source
            HAVING nb_liens > 0
            ORDER BY nb_liens DESC
        ');
        $stmtHubSection->execute([':orpheline' => $orphelin['url']]);
        $tousHubs = $stmtHubSection->fetchAll(PDO::FETCH_ASSOC);

        // Chercher un hub dans la même section
        $hubTrouve = null;
        foreach ($tousHubs as $hub) {
            if (extraireSection($hub['url']) === $section) {
                $hubTrouve = $hub;
                break;
            }
        }

        if ($hubTrouve !== null) {
            $suggestions[] = [
                'orpheline' => $orphelin['url'],
                'source_suggeree' => $hubTrouve['url'],
                'nb_liens_hub' => (int) $hubTrouve['nb_liens'],
            ];
        }
    }

    return [
        'type' => 'orphelins',
        'nb_orphelins' => $nbTotal,
        'nb_pages_total' => $nbPagesTotal,
        'ratio_orphelins' => $nbPagesTotal > 0 ? round(($nbTotal / $nbPagesTotal) * 100, 1) : 0,
        'orphelins' => $orphelins,
        'quasi_orphelins' => $quasiOrphelins,
        'nb_quasi_orphelins' => $nbQuasiOrphelins,
        'sections_disponibles' => $sectionsDisponibles,
        'suggestions' => $suggestions,
    ];
}

// ── Diversité des ancres ─────────────────────────────────────────────

/**
 * @return array<string, mixed>
 */
function analyserDiversiteAncres(PDO $db): array
{
    $stmt = $db->query('
        SELECT
            destination as url,
            COUNT(*) as total_liens,
            COUNT(DISTINCT ancre) as ancres_uniques,
            ROUND(CAST(COUNT(DISTINCT ancre) AS REAL) / COUNT(*) * 100, 1) as indice_diversite
        FROM liens
        GROUP BY destination
        HAVING total_liens >= 3
        ORDER BY indice_diversite ASC
        LIMIT 500
    ');
    $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ajouter le top ancres pour les 500 premières pages (les pires)
    // Au-delà on ne charge pas les détails pour la performance
    $stmtAncres = $db->prepare('
        SELECT ancre, COUNT(*) as nb
        FROM liens
        WHERE destination = :url
        GROUP BY ancre
        ORDER BY nb DESC
        LIMIT 10
    ');

    $nbSurOptimisees = 0;
    $nbARisque = 0;

    foreach ($pages as &$page) {
        $stmtAncres->execute([':url' => $page['url']]);
        $page['top_ancres'] = $stmtAncres->fetchAll(PDO::FETCH_ASSOC);
        $page['section'] = extraireSection($page['url']);

        if (!empty($page['top_ancres'])) {
            $page['ancre_dominante'] = $page['top_ancres'][0]['ancre'];
            $totalLiens = (int) $page['total_liens'];
            $page['pct_dominante'] = $totalLiens > 0
                ? round((int) $page['top_ancres'][0]['nb'] / $totalLiens * 100, 1)
                : 0.0;

            $nbMotsDominante = str_word_count($page['ancre_dominante']);
            $page['risque_sur_optimisation'] = $page['pct_dominante'] > 60 && $nbMotsDominante >= 2;

            if ($page['risque_sur_optimisation']) {
                $nbSurOptimisees++;
            }
            if ($page['pct_dominante'] > 70) {
                $nbARisque++;
            }
        } else {
            $page['ancre_dominante'] = '';
            $page['pct_dominante'] = 0.0;
            $page['risque_sur_optimisation'] = false;
        }
    }
    unset($page);

    $nbFaibleDiversite = 0;
    foreach ($pages as $p) {
        if ((float) $p['indice_diversite'] < 20) {
            $nbFaibleDiversite++;
        }
    }

    return [
        'type' => 'diversite_ancres',
        'nb_pages_analysees' => count($pages),
        'nb_faible_diversite' => $nbFaibleDiversite,
        'nb_sur_optimisees' => $nbSurOptimisees,
        'nb_a_risque' => $nbARisque,
        'pages' => $pages,
    ];
}

// ── Hubs & Autorités ─────────────────────────────────────────────────

/**
 * @return array<string, mixed>
 */
function analyserHubsAutorites(PDO $db): array
{
    // Top 100 hubs avec nb_entrants
    $hubs = $db->query('
        SELECT
            s.source as url,
            s.destinations_uniques,
            s.nb_liens,
            COALESCE(e.nb_entrants, 0) as nb_entrants
        FROM (
            SELECT source, COUNT(DISTINCT destination) as destinations_uniques, COUNT(*) as nb_liens
            FROM liens GROUP BY source
        ) s
        LEFT JOIN (
            SELECT destination, COUNT(*) as nb_entrants FROM liens GROUP BY destination
        ) e ON s.source = e.destination
        ORDER BY s.nb_liens DESC
        LIMIT 100
    ')->fetchAll(PDO::FETCH_ASSOC);

    foreach ($hubs as &$hub) {
        $hub['ratio_in_out'] = round((int) $hub['nb_entrants'] / max((int) $hub['nb_liens'], 1), 2);
    }
    unset($hub);

    // Top 100 autorités avec nb_sortants
    $autorites = $db->query('
        SELECT
            e.destination as url,
            e.sources_uniques,
            e.nb_liens,
            COALESCE(s.nb_sortants, 0) as nb_sortants
        FROM (
            SELECT destination, COUNT(DISTINCT source) as sources_uniques, COUNT(*) as nb_liens
            FROM liens GROUP BY destination
        ) e
        LEFT JOIN (
            SELECT source, COUNT(*) as nb_sortants FROM liens GROUP BY source
        ) s ON e.destination = s.source
        ORDER BY e.nb_liens DESC
        LIMIT 100
    ')->fetchAll(PDO::FETCH_ASSOC);

    foreach ($autorites as &$autorite) {
        $autorite['ratio_in_out'] = round((int) $autorite['nb_liens'] / max((int) $autorite['nb_sortants'], 1), 2);
    }
    unset($autorite);

    // Fuites d'équité
    $fuites = $db->query('
        SELECT s.source as url, s.nb_sortants, COALESCE(e.nb_entrants, 0) as nb_entrants
        FROM (SELECT source, COUNT(*) as nb_sortants FROM liens GROUP BY source) s
        LEFT JOIN (SELECT destination, COUNT(*) as nb_entrants FROM liens GROUP BY destination) e
            ON s.source = e.destination
        WHERE s.nb_sortants > 10 AND COALESCE(e.nb_entrants, 0) < 3
        ORDER BY s.nb_sortants DESC
        LIMIT 100
    ')->fetchAll(PDO::FETCH_ASSOC);

    foreach ($fuites as &$fuite) {
        $fuite['section'] = extraireSection($fuite['url']);
        $ratio = (int) $fuite['nb_sortants'] / max((int) $fuite['nb_entrants'], 1);
        if ($ratio > 20) {
            $fuite['recommandation'] = 'Ajouter des liens entrants ou réduire les sortants — déséquilibre majeur';
        } elseif ($ratio > 10) {
            $fuite['recommandation'] = 'Renforcer le maillage entrant vers cette page';
        } else {
            $fuite['recommandation'] = 'Surveiller le ratio entrants/sortants';
        }
    }
    unset($fuite);

    // Pages puits : beaucoup d'entrants, 0 sortants
    $pagesPuits = $db->query('
        SELECT destination as url, COUNT(*) as nb_entrants
        FROM liens
        WHERE destination NOT IN (SELECT DISTINCT source FROM liens)
        GROUP BY destination
        HAVING nb_entrants > 5
        ORDER BY nb_entrants DESC
        LIMIT 50
    ')->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pagesPuits as &$puits) {
        $puits['section'] = extraireSection($puits['url']);
    }
    unset($puits);

    return [
        'type' => 'hubs_autorites',
        'hubs' => $hubs,
        'autorites' => $autorites,
        'fuites_equite' => $fuites,
        'pages_puits' => $pagesPuits,
    ];
}

// ── Distribution par section ─────────────────────────────────────────

/**
 * @return array<string, mixed>
 */
function analyserDistributionSections(PDO $db): array
{
    // Construire la matrice par streaming (pas de fetchAll)
    $stmt = $db->query('SELECT source, destination, COUNT(*) as nb FROM liens GROUP BY source, destination');

    $matrice = [];
    $sectionsSet = [];
    $pagesParSection = [];

    while ($lien = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $sectionSource = extraireSection($lien['source']);
        $sectionDest = extraireSection($lien['destination']);

        $sectionsSet[$sectionSource] = true;
        $sectionsSet[$sectionDest] = true;

        $pagesParSection[$sectionSource][$lien['source']] = true;
        $pagesParSection[$sectionDest][$lien['destination']] = true;

        if (!isset($matrice[$sectionSource][$sectionDest])) {
            $matrice[$sectionSource][$sectionDest] = 0;
        }
        $matrice[$sectionSource][$sectionDest] += (int) $lien['nb'];
    }

    $sections = array_keys($sectionsSet);
    sort($sections);

    // Construire la matrice complète
    $matriceComplete = [];
    foreach ($sections as $source) {
        $ligne = [];
        foreach ($sections as $dest) {
            $ligne[$dest] = $matrice[$source][$dest] ?? 0;
        }
        $matriceComplete[$source] = $ligne;
    }

    // Totaux par section
    $totaux = [];
    $ilots = [];

    foreach ($sections as $section) {
        $entrants = 0;
        $sortants = 0;
        foreach ($sections as $autre) {
            $sortants += $matriceComplete[$section][$autre] ?? 0;
            $entrants += $matriceComplete[$autre][$section] ?? 0;
        }
        $intra = $matriceComplete[$section][$section] ?? 0;
        $isolation = $sortants > 0 ? round(($intra / $sortants) * 100, 1) : 0;

        $nbPages = count($pagesParSection[$section] ?? []);

        // Top flux sortants (hors intra-section)
        $fluxSortants = [];
        foreach ($sections as $autre) {
            if ($autre === $section) {
                continue;
            }
            $nb = $matriceComplete[$section][$autre] ?? 0;
            if ($nb > 0) {
                $fluxSortants[] = ['section' => $autre, 'nb' => $nb];
            }
        }
        usort($fluxSortants, fn(array $a, array $b): int => $b['nb'] <=> $a['nb']);
        $topFlux = array_slice($fluxSortants, 0, 3);
        $sortantsExterne = $sortants - $intra;
        foreach ($topFlux as &$flux) {
            $flux['pct'] = $sortantsExterne > 0 ? round($flux['nb'] / $sortantsExterne * 100, 1) : 0;
        }
        unset($flux);

        // Diagnostic
        if ($isolation > 90) {
            $diagnostic = 'Silo trop cloisonné — risque d\'isolement';
        } elseif ($isolation >= 60) {
            $diagnostic = 'Silo bien structuré';
        } elseif ($isolation >= 30) {
            $diagnostic = 'Maillage transversal correct';
        } else {
            $diagnostic = 'Pas de structure en silo';
        }

        if ($isolation >= 100) {
            $ilots[] = $section;
        }

        $totaux[$section] = [
            'entrants' => $entrants,
            'sortants' => $sortants,
            'intra' => $intra,
            'isolation' => $isolation,
            'nb_pages' => $nbPages,
            'top_flux' => $topFlux,
            'diagnostic' => $diagnostic,
        ];
    }

    return [
        'type' => 'distribution_sections',
        'sections' => $sections,
        'matrice' => $matriceComplete,
        'totaux' => $totaux,
        'ilots' => $ilots,
    ];
}

// ── PageRank avec surfeur raisonnable ────────────────────────────────

/**
 * @return array<string, mixed>
 */
function analyserPageRank(PDO $db): array
{
    $ancresGeneriques = ['cliquez ici', 'en savoir plus', 'lire la suite', 'voir plus',
        'click here', 'read more', 'learn more', 'ici', 'lien', 'link', 'plus'];

    // Phase 1 : construire le graphe par streaming SQL (pré-agrégé)
    // On ne charge jamais tous les liens bruts en mémoire
    $urlIndex = [];   // url → int (index compact)
    $indexUrl = [];   // int → url
    $nextId = 0;

    $stmt = $db->query('
        SELECT source, destination, MIN(ancre) as ancre, COUNT(*) as nb
        FROM liens GROUP BY source, destination
    ');

    // poidsLien[srcId][dstId] = float, entrant[dstId][] = srcId
    $poidsLien = [];
    $entrant = [];
    $nbSortants = []; // srcId → count
    $nbEntrants = []; // dstId → count

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $src = $row['source'];
        $dst = $row['destination'];

        if (!isset($urlIndex[$src])) { $urlIndex[$src] = $nextId; $indexUrl[$nextId] = $src; $nextId++; }
        if (!isset($urlIndex[$dst])) { $urlIndex[$dst] = $nextId; $indexUrl[$nextId] = $dst; $nextId++; }

        $srcId = $urlIndex[$src];
        $dstId = $urlIndex[$dst];

        // Pondération surfeur raisonnable
        $poids = 1.0;
        $ancre = $row['ancre'];
        $nbMots = str_word_count($ancre);
        if ($nbMots >= 2) {
            $poids *= 1.5;
        } elseif (in_array($ancre, $ancresGeneriques, true) || $ancre === '') {
            $poids *= 0.5;
        }

        $sectionSrc = extraireSection($src);
        $sectionDst = extraireSection($dst);
        if ($sectionSrc === $sectionDst && $sectionSrc !== '/') {
            $poids *= 1.3;
        } else {
            $poids *= 0.8;
        }

        $nbOcc = (int) $row['nb'];
        if ($nbOcc > 1) {
            $poids = $poids * 1.2 + $poids * 0.3 * ($nbOcc - 1);
            $poids /= $nbOcc;
        } else {
            $poids *= 1.2;
        }

        $poidsLien[$srcId][$dstId] = $poids;
        $entrant[$dstId][] = $srcId;

        $nbSortants[$srcId] = ($nbSortants[$srcId] ?? 0) + 1;
        $nbEntrants[$dstId] = ($nbEntrants[$dstId] ?? 0) + 1;
    }

    $n = $nextId;
    if ($n === 0) {
        return ['type' => 'pagerank', 'classement' => [], 'par_section' => [], 'nb_pages' => 0, 'distribution' => [], 'alertes' => []];
    }

    // Phase 2 : poids total sortant par page
    $poidsTotal = [];
    foreach ($poidsLien as $srcId => $destinations) {
        $total = 0.0;
        foreach ($destinations as $w) {
            $total += $w;
        }
        $poidsTotal[$srcId] = $total;
    }

    // Phase 3 : itération PageRank avec indices numériques
    $d = 0.85;
    $pr = array_fill(0, $n, 1.0 / $n);

    for ($iter = 0; $iter < 20; $iter++) {
        $nouveauPr = array_fill(0, $n, (1 - $d) / $n);

        foreach ($entrant as $dstId => $sources) {
            $somme = 0.0;
            foreach ($sources as $srcId) {
                if (($poidsTotal[$srcId] ?? 0) <= 0) {
                    continue;
                }
                $somme += $pr[$srcId] * (($poidsLien[$srcId][$dstId] ?? 0) / $poidsTotal[$srcId]);
            }
            $nouveauPr[$dstId] += $d * $somme;
        }

        $pr = $nouveauPr;
    }

    // Libérer les structures lourdes
    unset($poidsLien, $entrant, $poidsTotal, $nouveauPr);

    // Phase 4 : normalisation et classement
    $maxPr = max($pr) ?: 1;
    $classement = [];
    foreach ($pr as $id => $score) {
        $scoreNorm = round(($score / $maxPr) * 100, 2);
        $url = $indexUrl[$id];
        $nbEnt = $nbEntrants[$id] ?? 0;
        $classement[] = [
            'url' => $url,
            'score' => $scoreNorm,
            'section' => extraireSection($url),
            'nb_entrants' => $nbEnt,
            'nb_sortants' => $nbSortants[$id] ?? 0,
            'efficacite' => round($scoreNorm / max($nbEnt, 1), 2),
        ];
    }
    unset($pr, $indexUrl, $urlIndex);

    usort($classement, function (array $a, array $b): int {
        return $b['score'] <=> $a['score'];
    });

    // Distribution des scores (sur tout le classement)
    $distribution = calculerDistributionScores($classement);

    // PR thématique : désactivé si > 20K pages (trop coûteux)
    $parSection = [];
    if ($n <= 20000) {
        // Reconstruire liensParSource de manière compacte pour le PR thématique
        $liensParSource = [];
        $toutesPages = [];
        $stmtPR = $db->query('SELECT source, destination, MIN(ancre) as ancre FROM liens GROUP BY source, destination');
        while ($row = $stmtPR->fetch(PDO::FETCH_ASSOC)) {
            $liensParSource[$row['source']][$row['destination']][] = $row['ancre'];
            $toutesPages[$row['source']] = true;
            $toutesPages[$row['destination']] = true;
        }
        $parSection = calculerPageRankThematique($liensParSource, $toutesPages, $ancresGeneriques);
        unset($liensParSource, $toutesPages);
    }

    // Alertes (limitées à 50, sur le top 500 seulement)
    $alertes = [];
    foreach (array_slice($classement, 0, 500) as $item) {
        if (count($alertes) >= 50) break;
        if ($item['score'] < 10 && $item['nb_entrants'] > 15) {
            $alertes[] = [
                'url' => $item['url'],
                'type' => 'autorite_diluee',
                'message' => 'Page avec ' . $item['nb_entrants'] . ' liens entrants mais score PageRank faible (' . $item['score'] . ') — autorité diluée',
            ];
        }
        if ($item['score'] > 60 && $item['nb_entrants'] < 3 && $item['nb_sortants'] > 0) {
            $alertes[] = [
                'url' => $item['url'],
                'type' => 'pr_concentre',
                'message' => 'Page avec score élevé (' . $item['score'] . ') mais seulement ' . $item['nb_entrants'] . ' liens entrants — concentration de PageRank suspecte',
            ];
        }
    }

    return [
        'type' => 'pagerank',
        'nb_pages' => $n,
        'classement' => array_slice($classement, 0, 500),
        'par_section' => $parSection,
        'distribution' => $distribution,
        'alertes' => $alertes,
    ];
}

/**
 * Calcule le PageRank au sein de chaque section thématique.
 *
 * @param array<string, array<string, array<int, string>>> $liensParSource
 * @param array<string, bool> $toutesPages
 * @param array<int, string> $ancresGeneriques
 * @return array<string, array<int, array<string, mixed>>>
 */
function calculerPageRankThematique(array $liensParSource, array $toutesPages, array $ancresGeneriques): array
{
    // Regrouper les pages par section
    $pagesParSection = [];
    foreach (array_keys($toutesPages) as $page) {
        $section = extraireSection($page);
        $pagesParSection[$section][] = $page;
    }

    $resultats = [];

    foreach ($pagesParSection as $section => $pages) {
        if (count($pages) < 3) {
            continue; // Pas assez de pages pour un PageRank significatif
        }

        $pagesSet = array_flip($pages);
        $n = count($pages);
        $d = 0.85;

        // Construire le sous-graphe intra-section
        $pr = [];
        $graphe = [];
        $entrant = [];
        $poidsTotal = [];

        foreach ($pages as $p) {
            $pr[$p] = 1.0 / $n;
        }

        foreach ($liensParSource as $src => $destinations) {
            if (!isset($pagesSet[$src])) {
                continue;
            }
            foreach ($destinations as $dst => $ancres) {
                if (!isset($pagesSet[$dst])) {
                    continue;
                }

                $poids = 1.0;
                $nbMots = str_word_count($ancres[0]);
                if ($nbMots >= 2) {
                    $poids *= 1.5;
                } elseif (in_array($ancres[0], $ancresGeneriques, true)) {
                    $poids *= 0.5;
                }

                $graphe[$src][$dst] = $poids;
                $entrant[$dst][] = $src;

                if (!isset($poidsTotal[$src])) {
                    $poidsTotal[$src] = 0;
                }
                $poidsTotal[$src] += $poids;
            }
        }

        // 15 itérations (suffisant pour un sous-graphe)
        for ($i = 0; $i < 15; $i++) {
            $nouveau = [];
            foreach ($pages as $p) {
                $somme = 0.0;
                foreach ($entrant[$p] ?? [] as $src) {
                    if (($poidsTotal[$src] ?? 0) > 0) {
                        $somme += $pr[$src] * (($graphe[$src][$p] ?? 0) / $poidsTotal[$src]);
                    }
                }
                $nouveau[$p] = (1 - $d) / $n + $d * $somme;
            }
            $pr = $nouveau;
        }

        // Normaliser et trier
        $maxPr = max($pr) ?: 1;
        $classement = [];
        foreach ($pr as $page => $score) {
            $classement[] = [
                'url' => $page,
                'score' => round(($score / $maxPr) * 100, 2),
            ];
        }

        usort($classement, function (array $a, array $b): int {
            return $b['score'] <=> $a['score'];
        });

        $resultats[$section] = array_slice($classement, 0, 20);
    }

    return $resultats;
}

/**
 * Calcule la distribution des scores PageRank par tranches.
 *
 * @param array<int, array<string, mixed>> $classement
 * @return array<int, array<string, mixed>>
 */
function calculerDistributionScores(array $classement): array
{
    $tranches = [
        ['min' => 80, 'max' => 100, 'label' => '80-100 (Très fort)'],
        ['min' => 60, 'max' => 80, 'label' => '60-80 (Fort)'],
        ['min' => 40, 'max' => 60, 'label' => '40-60 (Moyen)'],
        ['min' => 20, 'max' => 40, 'label' => '20-40 (Faible)'],
        ['min' => 0, 'max' => 20, 'label' => '0-20 (Très faible)'],
    ];

    $distribution = [];
    foreach ($tranches as $tranche) {
        $count = 0;
        foreach ($classement as $page) {
            if ($page['score'] >= $tranche['min'] && $page['score'] < $tranche['max']) {
                $count++;
            }
        }
        // La tranche max inclut 100
        if ($tranche['max'] === 100) {
            foreach ($classement as $page) {
                if ($page['score'] == 100) {
                    $count++;
                }
            }
        }
        $distribution[] = [
            'label' => $tranche['label'],
            'nb_pages' => $count,
        ];
    }

    return $distribution;
}

// ── Dashboard (KPI légers) ────────────────────────────────────────────

/**
 * @return array<string, mixed>
 */
function analyserDashboard(PDO $db): array
{
    $nbPages = (int) $db->query('
        SELECT COUNT(*) FROM (SELECT source as url FROM liens UNION SELECT destination FROM liens)
    ')->fetchColumn();

    $nbLiens = (int) $db->query('SELECT COUNT(*) FROM liens')->fetchColumn();
    $nbAncres = (int) $db->query('SELECT COUNT(DISTINCT ancre) FROM liens')->fetchColumn();

    $moyEntrants = (float) $db->query('
        SELECT AVG(cnt) FROM (SELECT destination, COUNT(*) as cnt FROM liens GROUP BY destination)
    ')->fetchColumn();

    $top5 = $db->query('
        SELECT destination as url, COUNT(*) as nb_entrants
        FROM liens GROUP BY destination ORDER BY nb_entrants DESC LIMIT 5
    ')->fetchAll(PDO::FETCH_ASSOC);

    $nbOrphelins = (int) $db->query('
        SELECT COUNT(DISTINCT source) FROM liens
        WHERE source NOT IN (SELECT DISTINCT destination FROM liens)
    ')->fetchColumn();

    $nbFaibleDiversite = (int) $db->query('
        SELECT COUNT(*) FROM (
            SELECT destination,
                   CAST(COUNT(DISTINCT ancre) AS REAL) / COUNT(*) * 100 as idx
            FROM liens GROUP BY destination
            HAVING COUNT(*) >= 3 AND idx < 20
        )
    ')->fetchColumn();

    $ratioLiensPage = round($nbLiens / max($nbPages, 1), 1);

    $nbCulsDeSac = (int) $db->query('
        SELECT COUNT(*) FROM (
            SELECT DISTINCT destination FROM liens
            WHERE destination NOT IN (SELECT DISTINCT source FROM liens)
        )
    ')->fetchColumn();

    $homepage = detecterHomepage($db);
    $profondeur = calculerProfondeur($db, $homepage);

    // Ancres génériques
    $ancresGeneriques = ['cliquez ici', 'en savoir plus', 'lire la suite', 'voir plus',
        'click here', 'read more', 'learn more', 'ici', 'lien', 'link', 'plus'];

    $placeholders = implode(',', array_fill(0, count($ancresGeneriques), '?'));
    $stmtGen = $db->prepare("SELECT COUNT(*) FROM liens WHERE LOWER(ancre) IN ($placeholders)");
    $stmtGen->execute($ancresGeneriques);
    $nbAncresGeneriques = (int) $stmtGen->fetchColumn();
    $pctAncresGeneriques = $nbLiens > 0 ? round($nbAncresGeneriques / $nbLiens * 100, 1) : 0.0;

    // Score de santé (0-100)
    $scoreSante = 100;

    $ratioOrphelins = $nbPages > 0 ? ($nbOrphelins / $nbPages) * 100 : 0;
    if ($ratioOrphelins > 15) {
        $scoreSante -= 25;
    } elseif ($ratioOrphelins > 5) {
        $scoreSante -= 15;
    }

    $ratioFaibleDiv = $nbPages > 0 ? ($nbFaibleDiversite / $nbPages) * 100 : 0;
    if ($ratioFaibleDiv > 25) {
        $scoreSante -= 20;
    } elseif ($ratioFaibleDiv > 10) {
        $scoreSante -= 10;
    }

    $ratioCds = $nbPages > 0 ? ($nbCulsDeSac / $nbPages) * 100 : 0;
    if ($ratioCds > 25) {
        $scoreSante -= 20;
    } elseif ($ratioCds > 10) {
        $scoreSante -= 10;
    }

    if ($pctAncresGeneriques > 50) {
        $scoreSante -= 20;
    } elseif ($pctAncresGeneriques > 30) {
        $scoreSante -= 10;
    }

    if ($ratioLiensPage < 2) {
        $scoreSante -= 10;
    }

    if ($profondeur['moyenne'] > 6) {
        $scoreSante -= 15;
    } elseif ($profondeur['moyenne'] > 4) {
        $scoreSante -= 5;
    }

    $scoreSante = max(0, $scoreSante);

    // Top problèmes
    $topProblemes = [];

    if ($ratioOrphelins > 15) {
        $topProblemes[] = ['type' => 'orpheline', 'message' => $nbOrphelins . ' pages orphelines (' . round($ratioOrphelins, 1) . '%) — maillage entrant insuffisant', 'severite' => 'critique'];
    } elseif ($ratioOrphelins > 5) {
        $topProblemes[] = ['type' => 'orpheline', 'message' => $nbOrphelins . ' pages orphelines (' . round($ratioOrphelins, 1) . '%) — maillage à renforcer', 'severite' => 'elevee'];
    }

    if ($ratioFaibleDiv > 25) {
        $topProblemes[] = ['type' => 'diversite', 'message' => $nbFaibleDiversite . ' pages avec faible diversité d\'ancres — risque de sur-optimisation', 'severite' => 'critique'];
    } elseif ($ratioFaibleDiv > 10) {
        $topProblemes[] = ['type' => 'diversite', 'message' => $nbFaibleDiversite . ' pages avec faible diversité d\'ancres', 'severite' => 'elevee'];
    }

    if ($ratioCds > 25) {
        $topProblemes[] = ['type' => 'cul_de_sac', 'message' => $nbCulsDeSac . ' pages culs-de-sac (' . round($ratioCds, 1) . '%) — perte de jus de liens', 'severite' => 'critique'];
    } elseif ($ratioCds > 10) {
        $topProblemes[] = ['type' => 'cul_de_sac', 'message' => $nbCulsDeSac . ' pages culs-de-sac (' . round($ratioCds, 1) . '%)', 'severite' => 'elevee'];
    }

    if ($pctAncresGeneriques > 50) {
        $topProblemes[] = ['type' => 'generique', 'message' => round($pctAncresGeneriques, 1) . '% d\'ancres génériques — signal sémantique très faible', 'severite' => 'critique'];
    } elseif ($pctAncresGeneriques > 30) {
        $topProblemes[] = ['type' => 'generique', 'message' => round($pctAncresGeneriques, 1) . '% d\'ancres génériques', 'severite' => 'moderee'];
    }

    if ($profondeur['moyenne'] > 6) {
        $topProblemes[] = ['type' => 'profondeur', 'message' => 'Profondeur moyenne de ' . $profondeur['moyenne'] . ' clics — architecture trop profonde', 'severite' => 'elevee'];
    } elseif ($profondeur['moyenne'] > 4) {
        $topProblemes[] = ['type' => 'profondeur', 'message' => 'Profondeur moyenne de ' . $profondeur['moyenne'] . ' clics', 'severite' => 'moderee'];
    }

    // Garder les 5 premiers
    $topProblemes = array_slice($topProblemes, 0, 5);

    return [
        'type' => 'dashboard',
        'nb_pages' => $nbPages,
        'nb_liens' => $nbLiens,
        'nb_ancres' => $nbAncres,
        'moy_liens_entrants' => round($moyEntrants, 1),
        'top5_entrants' => $top5,
        'nb_orphelins' => $nbOrphelins,
        'nb_faible_diversite' => $nbFaibleDiversite,
        'ratio_liens_page' => $ratioLiensPage,
        'nb_culs_de_sac' => $nbCulsDeSac,
        'homepage' => $homepage,
        'profondeur' => $profondeur,
        'nb_ancres_generiques' => $nbAncresGeneriques,
        'pct_ancres_generiques' => $pctAncresGeneriques,
        'score_sante' => $scoreSante,
        'top_problemes' => $topProblemes,
    ];
}

// ── Liste des ancres ─────────────────────────────────────────────────

/**
 * @return array<string, mixed>
 */
function analyserListeAncres(PDO $db): array
{
    $ancres = $db->query('
        SELECT
            ancre,
            COUNT(*) as nb_occurrences,
            COUNT(DISTINCT destination) as nb_destinations
        FROM liens
        WHERE ancre != \'\'
        GROUP BY ancre
        ORDER BY nb_occurrences DESC
        LIMIT 5000
    ')->fetchAll(PDO::FETCH_ASSOC);

    // Top 3 destinations par ancre
    $stmtTopDest = $db->prepare('
        SELECT destination, COUNT(*) as nb
        FROM liens WHERE ancre = :ancre
        GROUP BY destination ORDER BY nb DESC LIMIT 3
    ');

    $nbGeneriques = 0;
    $nbSuspectesCannib = 0;
    $sommeMots = 0;
    $typesDistribution = ['vide' => 0, 'generique' => 0, 'url_nue' => 0, 'mot_cle' => 0, 'descriptif' => 0];

    foreach ($ancres as &$a) {
        $stmtTopDest->execute([':ancre' => $a['ancre']]);
        $a['top_destinations'] = $stmtTopDest->fetchAll(PDO::FETCH_ASSOC);

        $typeAncre = classerAncre($a['ancre']);
        $a['type'] = $typeAncre;
        $a['longueur_mots'] = str_word_count($a['ancre']);
        $a['suspect_cannibale'] = (int) $a['nb_destinations'] >= 3;

        $typesDistribution[$typeAncre] = ($typesDistribution[$typeAncre] ?? 0) + 1;

        if ($typeAncre === 'generique') {
            $nbGeneriques++;
        }
        if ($a['suspect_cannibale']) {
            $nbSuspectesCannib++;
        }
        $sommeMots += $a['longueur_mots'];
    }
    unset($a);

    $nbAncresTotal = count($ancres);
    $pctGeneriques = $nbAncresTotal > 0 ? round($nbGeneriques / $nbAncresTotal * 100, 1) : 0.0;
    $longueurMoyenne = $nbAncresTotal > 0 ? round($sommeMots / $nbAncresTotal, 1) : 0.0;

    // Ancres vides (non incluses dans la requête principale)
    $nbVides = (int) $db->query("SELECT COUNT(*) FROM liens WHERE ancre = ''")->fetchColumn();
    $typesDistribution['vide'] = $nbVides;

    return [
        'type' => 'liste_ancres',
        'nb_ancres_total' => $nbAncresTotal,
        'ancres' => $ancres,
        'nb_generiques' => $nbGeneriques,
        'pct_generiques' => $pctGeneriques,
        'nb_vides' => $nbVides,
        'longueur_moyenne' => $longueurMoyenne,
        'nb_suspectes_cannib' => $nbSuspectesCannib,
        'types_distribution' => $typesDistribution,
    ];
}

// ── Cannibalization automatique ──────────────────────────────────────

/**
 * Détecte automatiquement la cannibalisation d'ancres sans fichier CSV.
 *
 * @return array<string, mixed>
 */
function analyserCannibalisationAuto(PDO $db): array
{
    $ancresCandidates = $db->query('
        SELECT ancre, COUNT(DISTINCT destination) as nb_dest, COUNT(*) as nb_total
        FROM liens
        WHERE ancre != \'\'
        GROUP BY ancre
        HAVING nb_dest >= 3
        ORDER BY nb_total DESC
        LIMIT 200
    ')->fetchAll(PDO::FETCH_ASSOC);

    $stmtDest = $db->prepare('
        SELECT destination, COUNT(*) as nb
        FROM liens
        WHERE ancre = :ancre
        GROUP BY destination
        ORDER BY nb DESC
    ');

    $resultats = [];
    $nbCannibalisees = 0;

    foreach ($ancresCandidates as $candidat) {
        $stmtDest->execute([':ancre' => $candidat['ancre']]);
        $destinations = $stmtDest->fetchAll(PDO::FETCH_ASSOC);

        if (empty($destinations)) {
            continue;
        }

        $dominante = $destinations[0];
        $nbTotal = (int) $candidat['nb_total'];
        $pctDominant = $nbTotal > 0 ? round((int) $dominante['nb'] / $nbTotal * 100, 1) : 0.0;

        if ($pctDominant <= 40) {
            continue;
        }

        // Destinations parasites = toutes sauf la dominante
        $parasites = [];
        $nbLiensParasites = 0;
        for ($i = 1, $count = count($destinations); $i < $count; $i++) {
            $parasites[] = [
                'destination' => $destinations[$i]['destination'],
                'nb_liens' => (int) $destinations[$i]['nb'],
            ];
            $nbLiensParasites += (int) $destinations[$i]['nb'];
        }

        $ratio = $nbTotal > 0 ? round($nbLiensParasites / $nbTotal * 100, 1) : 0.0;

        $resultats[] = [
            'ancre' => $candidat['ancre'],
            'destination_dominante' => $dominante['destination'],
            'pct_dominant' => $pctDominant,
            'nb_total_liens' => $nbTotal,
            'nb_destinations' => (int) $candidat['nb_dest'],
            'destinations_parasites' => $parasites,
            'nb_liens_parasites' => $nbLiensParasites,
            'ratio' => $ratio,
            'severite' => determinerSeverite($ratio),
            'impact' => $nbLiensParasites,
        ];

        $nbCannibalisees++;
    }

    // Trier par impact décroissant
    usort($resultats, fn(array $a, array $b): int => $b['impact'] <=> $a['impact']);

    return [
        'type' => 'cannibale_auto',
        'nb_ancres_analysees' => count($ancresCandidates),
        'nb_cannibalisees' => $nbCannibalisees,
        'resultats' => $resultats,
    ];
}

// ── Utilitaire : extraction de section ───────────────────────────────

/**
 * Extrait la section (1er répertoire) d'une URL.
 * Ex: "https://example.com/blog/article" → "/blog"
 */
function extraireSection(string $url): string
{
    $chemin = parse_url($url, PHP_URL_PATH) ?: '/';
    $segments = explode('/', trim($chemin, '/'));

    if (empty($segments[0])) {
        return '/';
    }

    return '/' . $segments[0];
}

// ── Export CSV pour les analyses avancées ─────────────────────────────

// ── Détail d'une ancre (sources et destinations) ─────────────────────

/**
 * Retourne les paires source → destination pour une ancre donnée.
 *
 * @return array<string, mixed>
 */
function analyserDetailAncre(PDO $db, string $ancre): array
{
    // Liens source → destination pour cette ancre
    $stmt = $db->prepare('
        SELECT source, destination, COUNT(*) as nb
        FROM liens WHERE ancre = :ancre
        GROUP BY source, destination
        ORDER BY nb DESC
        LIMIT 200
    ');
    $stmt->execute([':ancre' => $ancre]);
    $liens = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Stats globales
    $stmtStats = $db->prepare('
        SELECT COUNT(*) as nb_total, COUNT(DISTINCT source) as nb_sources, COUNT(DISTINCT destination) as nb_destinations
        FROM liens WHERE ancre = :ancre
    ');
    $stmtStats->execute([':ancre' => $ancre]);
    $stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

    // Top destinations
    $stmtDest = $db->prepare('
        SELECT destination, COUNT(*) as nb
        FROM liens WHERE ancre = :ancre
        GROUP BY destination ORDER BY nb DESC LIMIT 10
    ');
    $stmtDest->execute([':ancre' => $ancre]);
    $topDestinations = $stmtDest->fetchAll(PDO::FETCH_ASSOC);

    // Top sources
    $stmtSrc = $db->prepare('
        SELECT source, COUNT(*) as nb
        FROM liens WHERE ancre = :ancre
        GROUP BY source ORDER BY nb DESC LIMIT 10
    ');
    $stmtSrc->execute([':ancre' => $ancre]);
    $topSources = $stmtSrc->fetchAll(PDO::FETCH_ASSOC);

    return [
        'type' => 'detail_ancre',
        'ancre' => $ancre,
        'nb_total' => (int) $stats['nb_total'],
        'nb_sources' => (int) $stats['nb_sources'],
        'nb_destinations' => (int) $stats['nb_destinations'],
        'liens' => $liens,
        'top_destinations' => $topDestinations,
        'top_sources' => $topSources,
    ];
}

/**
 * Exporte les résultats d'une analyse avancée en CSV.
 * Appelé depuis download.php.
 */
function exporterAnalyseAvanceeCsv(string $cheminSqlite, string $type): never
{
    $resultats = executerAnalyse($cheminSqlite, match ($type) {
        'pagerank' => 'pagerank',
        'orphelins' => 'orphelins',
        'diversite' => 'diversite_ancres',
        'hubs' => 'hubs_autorites',
        'ancres' => 'liste_ancres',
        'cannibale_auto' => 'cannibale_auto',
    });

    $nomFichier = $type . '_analyse.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nomFichier . '"');
    header('X-Content-Type-Options: nosniff');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");

    switch ($type) {
        case 'pagerank':
            fputcsv($output, ['URL', 'Score PageRank', 'Section', 'Liens entrants', 'Liens sortants'], ';');
            foreach ($resultats['classement'] as $page) {
                fputcsv($output, [$page['url'], $page['score'], $page['section'], $page['nb_entrants'], $page['nb_sortants']], ';');
            }
            break;

        case 'orphelins':
            fputcsv($output, ['URL orpheline', 'Liens sortants'], ';');
            foreach ($resultats['orphelins'] as $page) {
                fputcsv($output, [$page['url'], $page['nb_liens_sortants']], ';');
            }
            break;

        case 'diversite':
            fputcsv($output, ['URL', 'Total liens', 'Ancres uniques', 'Indice diversité (%)'], ';');
            foreach ($resultats['pages'] as $page) {
                fputcsv($output, [$page['url'], $page['total_liens'], $page['ancres_uniques'], $page['indice_diversite']], ';');
            }
            break;

        case 'hubs':
            fputcsv($output, ['Type', 'URL', 'Métrique 1', 'Métrique 2'], ';');
            foreach ($resultats['hubs'] as $h) {
                fputcsv($output, ['Hub', $h['url'], $h['nb_liens'] . ' liens', $h['destinations_uniques'] . ' dest.'], ';');
            }
            foreach ($resultats['autorites'] as $a) {
                fputcsv($output, ['Autorité', $a['url'], $a['nb_liens'] . ' liens', $a['sources_uniques'] . ' sources'], ';');
            }
            foreach ($resultats['fuites_equite'] as $f) {
                fputcsv($output, ['Fuite', $f['url'], $f['nb_sortants'] . ' sortants', $f['nb_entrants'] . ' entrants'], ';');
            }
            break;

        case 'ancres':
            fputcsv($output, ['Ancre', 'Occurrences', 'Nb destinations'], ';');
            foreach ($resultats['ancres'] as $a) {
                fputcsv($output, [$a['ancre'], $a['nb_occurrences'], $a['nb_destinations']], ';');
            }
            break;

        case 'cannibale_auto':
            fputcsv($output, ['Ancre', 'Destination dominante', '% dominant', 'Liens parasites', 'Destinations parasites', 'Ratio (%)', 'Sévérité', 'Impact'], ';');
            foreach ($resultats['resultats'] as $r) {
                fputcsv($output, [
                    $r['ancre'],
                    $r['destination_dominante'],
                    $r['pct_dominant'],
                    $r['nb_liens_parasites'],
                    $r['nb_destinations'] - 1,
                    $r['ratio'],
                    $r['severite']['label'],
                    $r['impact'],
                ], ';');
            }
            break;
    }

    fclose($output);
    exit;
}
