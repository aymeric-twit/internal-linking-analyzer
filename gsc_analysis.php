<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/user_context.php';
require_once __DIR__ . '/gsc_bridge.php';
require_once __DIR__ . '/advanced_analysis.php';

// Si appelé directement en GET (endpoint AJAX)
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'gsc_analysis.php' && $_SERVER['REQUEST_METHOD'] === 'GET') {

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
ini_set('display_errors', '0');
error_reporting(0);

$action = $_GET['action'] ?? '';
$importId = $_GET['importId'] ?? '';

// ── Action : vérifier la disponibilité GSC ──────────────

if ($action === 'verifier') {
    $disponible = gscDisponible();
    $sites = [];

    if ($disponible) {
        $userId = obtenirUserId();
        $sites = obtenirSitesGsc($userId);
    }

    repondreJson([
        'disponible' => $disponible && count($sites) > 0,
        'sites' => $sites,
    ]);
}

// ── Analyses croisées GSC × maillage ────────────────────

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
        'fr' => 'Base de données de liens introuvable.',
        'en' => 'Links database not found.',
    ], 404);
}

$type = $_GET['type'] ?? '';
$siteId = (int) ($_GET['siteId'] ?? 0);
$dateDebut = $_GET['dateDebut'] ?? '';
$dateFin = $_GET['dateFin'] ?? '';

$typesValides = ['fortes_sans_maillage', 'maillees_invisibles', 'ancre_vs_requete', 'budget_sections'];
if (!in_array($type, $typesValides, true)) {
    repondreErreur([
        'fr' => 'Type d\'analyse GSC invalide.',
        'en' => 'Invalid GSC analysis type.',
    ], 400);
}

if ($siteId <= 0 || $dateDebut === '' || $dateFin === '') {
    repondreErreur([
        'fr' => 'Paramètres siteId, dateDebut et dateFin requis.',
        'en' => 'Parameters siteId, dateDebut and dateFin are required.',
    ], 400);
}

if (!gscDisponible()) {
    repondreErreur([
        'fr' => 'Search Console non disponible.',
        'en' => 'Search Console not available.',
    ], 400);
}

// Charger les données GSC et SQLite
$donneesGsc = obtenirDonneesGscParPage($siteId, $dateDebut, $dateFin);

$dbLiens = new PDO('sqlite:' . $cheminSqlite);
$dbLiens->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$resultats = match ($type) {
    'fortes_sans_maillage' => analyserFortesSansMaillage($dbLiens, $donneesGsc),
    'maillees_invisibles' => analyserMailleesInvisibles($dbLiens, $donneesGsc, $cheminSqlite),
    'ancre_vs_requete' => analyserAncreVsRequete($dbLiens, $donneesGsc),
    'budget_sections' => analyserBudgetSections($dbLiens, $donneesGsc),
};

repondreJson($resultats);

} // fin du guard if (endpoint AJAX)

// ══════════════════════════════════════════════════════════
//  UTILITAIRE : normalisation URL pour matching GSC ↔ SQLite
// ══════════════════════════════════════════════════════════

/**
 * Normalise une URL pour le matching : lowercase du path, strip www,
 * forcer https, supprimer query strings non-significatifs, trailing slash.
 */
function normaliserUrlGsc(string $url): string
{
    $url = trim($url);
    $url = strtok($url, '#') ?: $url;
    $parsed = parse_url($url);
    $path = $parsed['path'] ?? '/';
    $path = rtrim(strtolower($path), '/');
    if ($path === '') {
        $path = '/';
    }

    $host = $parsed['host'] ?? '';
    $host = preg_replace('/^www\./', '', strtolower($host));

    return 'https://' . $host . $path;
}

/**
 * Cherche le nb de liens entrants pour une URL dans l'index, avec fallbacks.
 *
 * @param array<string, int> $liensEntrants
 */
function chercherLiensEntrants(string $url, array $liensEntrants): int
{
    if (isset($liensEntrants[$url])) {
        return $liensEntrants[$url];
    }
    $norm = normaliserUrlGsc($url);
    foreach ($liensEntrants as $k => $v) {
        if (normaliserUrlGsc($k) === $norm) {
            return $v;
        }
    }
    return 0;
}

// ══════════════════════════════════════════════════════════
//  ANALYSE 1 : Pages fortes sans maillage
// ══════════════════════════════════════════════════════════

/**
 * Pages bien positionnées (top 20) mais peu de liens entrants.
 *
 * @param array<string, array> $donneesGsc
 * @return array<string, mixed>
 */
function analyserFortesSansMaillage(PDO $dbLiens, array $donneesGsc): array
{
    // Liens entrants depuis SQLite
    $liensEntrants = [];
    $stmt = $dbLiens->query('SELECT destination, COUNT(*) as nb FROM liens GROUP BY destination');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $liensEntrants[$row['destination']] = (int) $row['nb'];
    }

    $pages = [];

    foreach ($donneesGsc as $url => $data) {
        if ($data['position'] > 20 || $data['position'] <= 0) {
            continue;
        }

        $nbLiens = chercherLiensEntrants($url, $liensEntrants);

        if ($nbLiens < 3) {
            $priorite = $data['impressions'] > 0 ? $data['impressions'] / max($nbLiens, 1) : 0;

            $pages[] = [
                'url' => $url,
                'position_moy' => $data['position'],
                'clics' => $data['clics'],
                'impressions' => $data['impressions'],
                'nb_liens_entrants' => $nbLiens,
                'priorite' => round($priorite, 1),
            ];
        }
    }

    // Trier par priorité décroissante
    usort($pages, function (array $a, array $b): int {
        return $b['priorite'] <=> $a['priorite'];
    });

    // Quick wins : position 5-15, impressions > 500, < 5 liens entrants
    $quickWins = [];
    foreach ($donneesGsc as $url => $data) {
        if ($data['position'] < 5 || $data['position'] > 15 || $data['impressions'] < 500) {
            continue;
        }
        $nbLiens = chercherLiensEntrants($url, $liensEntrants);
        if ($nbLiens < 5) {
            $quickWins[] = [
                'url' => $url,
                'position_moy' => $data['position'],
                'clics' => $data['clics'],
                'impressions' => $data['impressions'],
                'nb_liens_entrants' => $nbLiens,
            ];
        }
    }
    usort($quickWins, function (array $a, array $b): int {
        return $b['impressions'] <=> $a['impressions'];
    });

    return [
        'type' => 'fortes_sans_maillage',
        'pages' => array_slice($pages, 0, 200),
        'quick_wins' => array_slice($quickWins, 0, 20),
    ];
}

// ══════════════════════════════════════════════════════════
//  ANALYSE 2 : Pages maillées mais invisibles
// ══════════════════════════════════════════════════════════

/**
 * Pages avec beaucoup de liens entrants mais peu/pas d'impressions GSC.
 *
 * @param array<string, array> $donneesGsc
 * @return array<string, mixed>
 */
function analyserMailleesInvisibles(PDO $dbLiens, array $donneesGsc, string $cheminSqlite): array
{
    // Pages avec >= 10 liens entrants
    $stmt = $dbLiens->query('
        SELECT destination as url, COUNT(*) as nb_liens_entrants
        FROM liens
        GROUP BY destination
        HAVING nb_liens_entrants >= 10
        ORDER BY nb_liens_entrants DESC
    ');
    $pagesMailees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculer le PageRank pour enrichir
    $pageRanks = [];
    if (function_exists('executerAnalyse')) {
        $prData = executerAnalyse($cheminSqlite, 'pagerank');
        foreach ($prData['classement'] ?? [] as $p) {
            $pageRanks[$p['url']] = $p['score'];
        }
    }

    $pages = [];

    foreach ($pagesMailees as $page) {
        $url = $page['url'];
        $impressions = 0;

        // Chercher les impressions GSC
        $gscData = $donneesGsc[$url] ?? $donneesGsc[rtrim($url, '/')] ?? $donneesGsc[$url . '/'] ?? null;
        if ($gscData) {
            $impressions = $gscData['impressions'];
        }

        if ($impressions < 10) {
            $diagnostic = 'Vérifier indexation et contenu';
            if ($impressions === 0) {
                $diagnostic = 'Page probablement non indexée ou canonicalisée';
            }

            $pages[] = [
                'url' => $url,
                'nb_liens_entrants' => (int) $page['nb_liens_entrants'],
                'pagerank' => $pageRanks[$url] ?? 0,
                'impressions_gsc' => $impressions,
                'diagnostic' => $diagnostic,
            ];
        }
    }

    // Trier par nombre de liens entrants décroissant
    usort($pages, function (array $a, array $b): int {
        return $b['nb_liens_entrants'] <=> $a['nb_liens_entrants'];
    });

    return [
        'type' => 'maillees_invisibles',
        'pages' => array_slice($pages, 0, 200),
    ];
}

// ══════════════════════════════════════════════════════════
//  ANALYSE 3 : Ancre vs requête réelle
// ══════════════════════════════════════════════════════════

/**
 * Compare les ancres de liens internes aux requêtes GSC.
 *
 * @param array<string, array> $donneesGsc
 * @return array<string, mixed>
 */
function analyserAncreVsRequete(PDO $dbLiens, array $donneesGsc): array
{
    // Top ancres par destination
    $stmtAncres = $dbLiens->prepare('
        SELECT ancre, COUNT(*) as nb
        FROM liens
        WHERE destination = :url AND ancre != \'\'
        GROUP BY ancre
        ORDER BY nb DESC
        LIMIT 3
    ');

    $pages = [];

    foreach ($donneesGsc as $url => $data) {
        if (empty($data['requetes']) || $data['clics'] < 1) {
            continue;
        }

        // Top requête GSC
        $topRequete = $data['requetes'][0];

        // Top ancres internes
        $stmtAncres->execute([':url' => $url]);
        $topAncres = $stmtAncres->fetchAll(PDO::FETCH_ASSOC);

        if (empty($topAncres)) {
            // Essayer sans trailing slash
            $urlAlt = str_ends_with($url, '/') ? rtrim($url, '/') : $url . '/';
            $stmtAncres->execute([':url' => $urlAlt]);
            $topAncres = $stmtAncres->fetchAll(PDO::FETCH_ASSOC);
        }

        if (empty($topAncres)) {
            continue;
        }

        $meilleurScore = 0;
        $meilleureAncre = $topAncres[0]['ancre'];

        foreach ($topAncres as $ancre) {
            $score = calculerScoreCorrespondance($ancre['ancre'], $topRequete['query']);
            if ($score > $meilleurScore) {
                $meilleurScore = $score;
                $meilleureAncre = $ancre['ancre'];
            }
        }

        $action = '';
        if ($meilleurScore < 0.2) {
            $action = 'Remplacer l\'ancre "' . $meilleureAncre . '" par "' . $topRequete['query'] . '"';
        } elseif ($meilleurScore < 0.5) {
            $action = 'Enrichir l\'ancre avec les termes de la requête';
        }

        $pages[] = [
            'url' => $url,
            'top_ancre' => $meilleureAncre,
            'top_requete' => $topRequete['query'],
            'clics' => $topRequete['clics'],
            'position' => $topRequete['position'],
            'score_correspondance' => round($meilleurScore, 2),
            'action' => $action,
        ];
    }

    // Trier par score croissant (les pires d'abord)
    usort($pages, function (array $a, array $b): int {
        return $a['score_correspondance'] <=> $b['score_correspondance'];
    });

    return [
        'type' => 'ancre_vs_requete',
        'pages' => array_slice($pages, 0, 200),
    ];
}

/**
 * Calcule le score de correspondance entre une ancre et une requête.
 * Score = nb_mots_communs / max(nb_mots_ancre, nb_mots_requete)
 */
function calculerScoreCorrespondance(string $ancre, string $requete): float
{
    $motsAncre = array_unique(array_filter(
        preg_split('/\s+/', mb_strtolower(trim($ancre), 'UTF-8')) ?: []
    ));
    $motsRequete = array_unique(array_filter(
        preg_split('/\s+/', mb_strtolower(trim($requete), 'UTF-8')) ?: []
    ));

    if (empty($motsAncre) || empty($motsRequete)) {
        return 0;
    }

    $communs = count(array_intersect($motsAncre, $motsRequete));
    $maxMots = max(count($motsAncre), count($motsRequete));

    return $maxMots > 0 ? $communs / $maxMots : 0;
}

// ══════════════════════════════════════════════════════════
//  ANALYSE 4 : Budget de crawl par section
// ══════════════════════════════════════════════════════════

/**
 * Compare la distribution du maillage interne vs le trafic GSC par section.
 *
 * @param array<string, array> $donneesGsc
 * @return array<string, mixed>
 */
function analyserBudgetSections(PDO $dbLiens, array $donneesGsc): array
{
    // Maillage par section depuis SQLite
    $stmt = $dbLiens->query('SELECT destination, COUNT(*) as nb FROM liens GROUP BY destination');
    $liensParDest = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $maillageSections = [];
    $totalMaillage = 0;

    foreach ($liensParDest as $row) {
        $section = extraireSection($row['destination']);
        if (!isset($maillageSections[$section])) {
            $maillageSections[$section] = ['liens' => 0, 'pages' => 0];
        }
        $maillageSections[$section]['liens'] += (int) $row['nb'];
        $maillageSections[$section]['pages']++;
        $totalMaillage += (int) $row['nb'];
    }

    // Trafic par section depuis GSC
    $traficSections = [];
    $totalTrafic = 0;

    foreach ($donneesGsc as $url => $data) {
        $section = extraireSection($url);
        if (!isset($traficSections[$section])) {
            $traficSections[$section] = ['clics' => 0, 'impressions' => 0, 'pages' => 0];
        }
        $traficSections[$section]['clics'] += $data['clics'];
        $traficSections[$section]['impressions'] += $data['impressions'];
        $traficSections[$section]['pages']++;
        $totalTrafic += $data['clics'];
    }

    // Construire la matrice
    $toutesLesSections = array_unique(array_merge(
        array_keys($maillageSections),
        array_keys($traficSections)
    ));
    sort($toutesLesSections);

    $sections = [];

    foreach ($toutesLesSections as $section) {
        $liens = $maillageSections[$section]['liens'] ?? 0;
        $clics = $traficSections[$section]['clics'] ?? 0;
        $nbPages = ($maillageSections[$section]['pages'] ?? 0) + ($traficSections[$section]['pages'] ?? 0);

        $pctMaillage = $totalMaillage > 0 ? ($liens / $totalMaillage) * 100 : 0;
        $pctTrafic = $totalTrafic > 0 ? ($clics / $totalTrafic) * 100 : 0;

        $ratio = $pctTrafic > 0 ? $pctMaillage / $pctTrafic : ($pctMaillage > 0 ? 99.9 : 1.0);

        $diagnostic = 'Équilibré';
        if ($ratio < 0.5) {
            $diagnostic = 'Sous-maillée vs sa valeur business — ajouter des liens';
        } elseif ($ratio > 2.0) {
            $diagnostic = 'Sur-maillée vs sa valeur — redistribuer les liens';
        }

        $sections[] = [
            'section' => $section,
            'nb_pages' => $nbPages,
            'pct_maillage' => round($pctMaillage, 1),
            'pct_trafic' => round($pctTrafic, 1),
            'ratio' => round($ratio, 2),
            'diagnostic' => $diagnostic,
        ];
    }

    // Trier par ratio croissant (les plus sous-maillées en premier)
    usort($sections, function (array $a, array $b): int {
        return $a['ratio'] <=> $b['ratio'];
    });

    return [
        'type' => 'budget_sections',
        'sections' => $sections,
    ];
}
