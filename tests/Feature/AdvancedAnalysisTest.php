<?php

declare(strict_types=1);

require_once __DIR__ . '/../../functions.php';
require_once __DIR__ . '/../../user_context.php';
require_once __DIR__ . '/../../advanced_analysis.php';

/**
 * Crée une base SQLite de test avec des données réalistes.
 */
function creerBaseLiensTest(string $cheminSqlite): PDO
{
    $db = new PDO('sqlite:' . $cheminSqlite);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec('CREATE TABLE liens (source TEXT NOT NULL, destination TEXT NOT NULL, ancre TEXT NOT NULL)');

    $liens = [
        // Homepage lie vers 3 pages
        ['https://example.com/', 'https://example.com/blog', 'notre blog'],
        ['https://example.com/', 'https://example.com/produits', 'nos produits'],
        ['https://example.com/', 'https://example.com/contact', 'contactez-nous'],

        // Blog interne (silo)
        ['https://example.com/blog', 'https://example.com/blog/article-1', 'article seo'],
        ['https://example.com/blog', 'https://example.com/blog/article-2', 'guide complet'],
        ['https://example.com/blog/article-1', 'https://example.com/blog/article-2', 'guide complet'],
        ['https://example.com/blog/article-2', 'https://example.com/blog/article-1', 'article seo'],
        ['https://example.com/blog/article-1', 'https://example.com/produits', 'voir produits'],

        // Produits (silo)
        ['https://example.com/produits', 'https://example.com/produits/chaussures', 'chaussures running'],
        ['https://example.com/produits', 'https://example.com/produits/accessoires', 'accessoires'],
        ['https://example.com/produits/chaussures', 'https://example.com/produits/accessoires', 'accessoires'],

        // Page orpheline (émet des liens mais n'en reçoit aucun)
        ['https://example.com/page-orpheline', 'https://example.com/blog', 'blog'],
        ['https://example.com/page-orpheline', 'https://example.com/produits', 'produits'],

        // Doublons d'ancre (même source → même destination, ancres différentes)
        ['https://example.com/blog', 'https://example.com/produits', 'nos produits'],
        ['https://example.com/blog', 'https://example.com/produits', 'nos produits'],

        // Page avec faible diversité d'ancres (toujours la même ancre)
        ['https://example.com/promo-1', 'https://example.com/produits/chaussures', 'chaussures running'],
        ['https://example.com/promo-2', 'https://example.com/produits/chaussures', 'chaussures running'],
        ['https://example.com/promo-3', 'https://example.com/produits/chaussures', 'chaussures running'],
    ];

    $stmt = $db->prepare('INSERT INTO liens VALUES (?, ?, ?)');
    foreach ($liens as $lien) {
        $stmt->execute($lien);
    }

    $db->exec('CREATE INDEX idx_ancre ON liens(ancre)');
    $db->exec('CREATE INDEX idx_destination ON liens(destination)');
    $db->exec('CREATE INDEX idx_source ON liens(source)');
    $db->exec('CREATE INDEX idx_ancre_dest ON liens(ancre, destination)');

    return $db;
}

// ── Pages orphelines ─────────────────────────────────────

test('analyserOrphelins devrait détecter les pages sans liens entrants', function (): void {
    $chemin = sys_get_temp_dir() . '/test_orphelins_' . uniqid() . '.sqlite';
    $db = creerBaseLiensTest($chemin);

    $resultats = analyserOrphelins($db);

    expect($resultats['type'])->toBe('orphelins')
        ->and($resultats['nb_orphelins'])->toBeGreaterThan(0);

    // La page orpheline et les pages promo qui ne reçoivent pas de liens
    $urlsOrphelines = array_column($resultats['orphelins'], 'url');
    expect($urlsOrphelines)->toContain('https://example.com/page-orpheline');

    $db = null;
    unlink($chemin);
});

// ── Diversité des ancres ─────────────────────────────────

test('analyserDiversiteAncres devrait identifier les pages à faible diversité', function (): void {
    $chemin = sys_get_temp_dir() . '/test_diversite_' . uniqid() . '.sqlite';
    $db = creerBaseLiensTest($chemin);

    $resultats = analyserDiversiteAncres($db);

    expect($resultats['type'])->toBe('diversite_ancres')
        ->and($resultats['pages'])->toBeArray();

    // La page chaussures reçoit 4 liens dont 3 avec "chaussures running" → faible diversité
    $chaussures = null;
    foreach ($resultats['pages'] as $p) {
        if (str_contains($p['url'], 'chaussures')) {
            $chaussures = $p;
            break;
        }
    }

    if ($chaussures) {
        expect((float) $chaussures['indice_diversite'])->toBeLessThanOrEqual(50);
    }

    $db = null;
    unlink($chemin);
});

// ── Hubs & Autorités ─────────────────────────────────────

test('analyserHubsAutorites devrait identifier les hubs et autorités', function (): void {
    $chemin = sys_get_temp_dir() . '/test_hubs_' . uniqid() . '.sqlite';
    $db = creerBaseLiensTest($chemin);

    $resultats = analyserHubsAutorites($db);

    expect($resultats['type'])->toBe('hubs_autorites')
        ->and($resultats['hubs'])->toBeArray()
        ->and($resultats['autorites'])->toBeArray();

    // La homepage et /blog devraient être des hubs (beaucoup de liens sortants)
    $hubUrls = array_column($resultats['hubs'], 'url');
    expect($hubUrls)->toContain('https://example.com/')
        ->and($hubUrls)->toContain('https://example.com/blog');

    // /produits devrait être une autorité (beaucoup de liens entrants)
    $autoritesUrls = array_column($resultats['autorites'], 'url');
    expect($autoritesUrls)->toContain('https://example.com/produits');

    $db = null;
    unlink($chemin);
});

// ── Distribution par section ─────────────────────────────

test('analyserDistributionSections devrait construire la matrice de flux', function (): void {
    $chemin = sys_get_temp_dir() . '/test_distrib_' . uniqid() . '.sqlite';
    $db = creerBaseLiensTest($chemin);

    $resultats = analyserDistributionSections($db);

    expect($resultats['type'])->toBe('distribution_sections')
        ->and($resultats['sections'])->toBeArray()
        ->and($resultats['matrice'])->toBeArray()
        ->and($resultats['totaux'])->toBeArray();

    // Les sections /blog et /produits devraient exister
    expect($resultats['sections'])->toContain('/blog')
        ->and($resultats['sections'])->toContain('/produits');

    // Il devrait y avoir des liens intra-blog
    expect($resultats['matrice']['/blog']['/blog'] ?? 0)->toBeGreaterThan(0);

    $db = null;
    unlink($chemin);
});

// ── Extraction de section ────────────────────────────────

test('extraireSection devrait retourner le premier segment du chemin', function (): void {
    expect(extraireSection('https://example.com/blog/article-1'))->toBe('/blog');
    expect(extraireSection('https://example.com/produits/chaussures'))->toBe('/produits');
    expect(extraireSection('https://example.com/'))->toBe('/');
    expect(extraireSection('https://example.com'))->toBe('/');
});

// ── PageRank ─────────────────────────────────────────────

test('analyserPageRank devrait calculer les scores avec surfeur raisonnable', function (): void {
    $chemin = sys_get_temp_dir() . '/test_pagerank_' . uniqid() . '.sqlite';
    $db = creerBaseLiensTest($chemin);

    $resultats = analyserPageRank($db);

    expect($resultats['type'])->toBe('pagerank')
        ->and($resultats['nb_pages'])->toBeGreaterThan(0)
        ->and($resultats['classement'])->toBeArray()
        ->and($resultats['classement'])->not->toBeEmpty();

    // Le score max devrait être 100 (normalisé)
    expect($resultats['classement'][0]['score'])->toBe(100.0);

    // La homepage ou /produits devrait avoir un score élevé
    // (car ils reçoivent le plus de liens)
    $top5Urls = array_slice(array_column($resultats['classement'], 'url'), 0, 5);
    $topContientImportant = false;
    foreach ($top5Urls as $url) {
        if (str_contains($url, 'produits') || $url === 'https://example.com/') {
            $topContientImportant = true;
            break;
        }
    }
    expect($topContientImportant)->toBeTrue();

    // Distribution devrait exister
    expect($resultats['distribution'])->toBeArray()
        ->and($resultats['distribution'])->not->toBeEmpty();

    // PageRank thématique par section
    expect($resultats['par_section'])->toBeArray();

    $db = null;
    unlink($chemin);
});

// ── Dashboard ─────────────────────────────────────────────

test('analyserDashboard devrait retourner les KPI légers', function (): void {
    $chemin = sys_get_temp_dir() . '/test_dashboard_' . uniqid() . '.sqlite';
    $db = creerBaseLiensTest($chemin);

    $resultats = analyserDashboard($db);

    expect($resultats['type'])->toBe('dashboard')
        ->and($resultats['nb_pages'])->toBeGreaterThan(0)
        ->and($resultats['nb_liens'])->toBeGreaterThan(0)
        ->and($resultats['nb_ancres'])->toBeGreaterThan(0)
        ->and($resultats['moy_liens_entrants'])->toBeGreaterThan(0)
        ->and($resultats['top5_entrants'])->toBeArray()
        ->and($resultats['top5_entrants'])->not->toBeEmpty()
        ->and($resultats['nb_orphelins'])->toBeGreaterThanOrEqual(0)
        ->and($resultats['nb_faible_diversite'])->toBeGreaterThanOrEqual(0);

    // Le top 5 devrait contenir des URLs connues
    $topUrls = array_column($resultats['top5_entrants'], 'url');
    expect($topUrls)->toContain('https://example.com/produits');

    $db = null;
    unlink($chemin);
});

// ── Liste des ancres ──────────────────────────────────────

test('analyserListeAncres devrait retourner les ancres avec top destinations', function (): void {
    $chemin = sys_get_temp_dir() . '/test_ancres_' . uniqid() . '.sqlite';
    $db = creerBaseLiensTest($chemin);

    $resultats = analyserListeAncres($db);

    expect($resultats['type'])->toBe('liste_ancres')
        ->and($resultats['nb_ancres_total'])->toBeGreaterThan(0)
        ->and($resultats['ancres'])->toBeArray()
        ->and($resultats['ancres'])->not->toBeEmpty();

    // Chaque ancre devrait avoir des top_destinations
    $premiere = $resultats['ancres'][0];
    expect($premiere)->toHaveKeys(['ancre', 'nb_occurrences', 'nb_destinations', 'top_destinations'])
        ->and($premiere['top_destinations'])->toBeArray();

    // L'ancre "chaussures running" devrait être parmi les plus fréquentes
    $ancresTexte = array_column($resultats['ancres'], 'ancre');
    expect($ancresTexte)->toContain('chaussures running');

    $db = null;
    unlink($chemin);
});

// ── Score de correspondance ancre/requête ─────────────────

test('calculerScoreCorrespondance devrait mesurer la correspondance entre mots', function (): void {
    require_once __DIR__ . '/../../gsc_bridge.php';
    require_once __DIR__ . '/../../gsc_analysis.php';

    // Correspondance parfaite
    expect(calculerScoreCorrespondance('chaussures running', 'chaussures running'))->toBe(1.0);

    // Correspondance partielle
    $score = calculerScoreCorrespondance('chaussures', 'chaussures running trail');
    expect($score)->toBeGreaterThan(0.2)->and($score)->toBeLessThan(1.0);

    // Aucune correspondance
    expect(calculerScoreCorrespondance('cliquez ici', 'chaussures running'))->toBe(0.0);

    // Chaînes vides
    expect(calculerScoreCorrespondance('', 'test'))->toBe(0.0);
    expect(calculerScoreCorrespondance('test', ''))->toBe(0.0);
});

// ── GSC Bridge (standalone) ───────────────────────────────

test('gscDisponible devrait retourner false en mode standalone', function (): void {
    require_once __DIR__ . '/../../gsc_bridge.php';

    // En standalone (PLATFORM_EMBEDDED non défini), GSC n'est pas disponible
    expect(gscDisponible())->toBeFalse();
});

// ── Dashboard enrichi ─────────────────────────────────────

test('analyserDashboard devrait inclure score de santé, profondeur et culs-de-sac', function (): void {
    $chemin = sys_get_temp_dir() . '/test_dashboard_enrichi_' . uniqid() . '.sqlite';
    $db = creerBaseLiensTest($chemin);

    $resultats = analyserDashboard($db);

    // Champs existants toujours présents
    expect($resultats['type'])->toBe('dashboard')
        ->and($resultats['nb_pages'])->toBeGreaterThan(0)
        ->and($resultats['nb_liens'])->toBeGreaterThan(0);

    // Nouveaux champs
    expect($resultats)->toHaveKeys([
        'ratio_liens_page', 'nb_culs_de_sac', 'homepage', 'profondeur',
        'nb_ancres_generiques', 'pct_ancres_generiques', 'score_sante', 'top_problemes',
    ]);
    expect($resultats['score_sante'])->toBeGreaterThanOrEqual(0)->and($resultats['score_sante'])->toBeLessThanOrEqual(100);
    expect($resultats['ratio_liens_page'])->toBeGreaterThan(0);
    expect($resultats['profondeur'])->toHaveKeys(['distribution', 'moyenne', 'max', 'inaccessibles']);
    expect($resultats['profondeur']['moyenne'])->toBeGreaterThan(0);
    expect($resultats['top_problemes'])->toBeArray();

    $db = null;
    unlink($chemin);
});

// ── Classification d'ancres ──────────────────────────────

test('classerAncre devrait classifier correctement les types d\'ancres', function (): void {
    expect(classerAncre(''))->toBe('vide');
    expect(classerAncre('   '))->toBe('vide');
    expect(classerAncre('cliquez ici'))->toBe('generique');
    expect(classerAncre('en savoir plus'))->toBe('generique');
    expect(classerAncre('https://example.com/page'))->toBe('url_nue');
    expect(classerAncre('www.example.com'))->toBe('url_nue');
    expect(classerAncre('chaussures'))->toBe('mot_cle');
    expect(classerAncre('guide complet chaussures running'))->toBe('descriptif');
});

// ── Orphelins enrichis ───────────────────────────────────

test('analyserOrphelins devrait inclure sections, quasi-orphelins et suggestions', function (): void {
    $chemin = sys_get_temp_dir() . '/test_orphelins_enrichi_' . uniqid() . '.sqlite';
    $db = creerBaseLiensTest($chemin);

    $resultats = analyserOrphelins($db);

    expect($resultats)->toHaveKeys(['quasi_orphelins', 'nb_quasi_orphelins', 'sections_disponibles', 'suggestions']);

    // Chaque orphelin doit avoir une section
    if (!empty($resultats['orphelins'])) {
        expect($resultats['orphelins'][0])->toHaveKey('section');
    }

    expect($resultats['sections_disponibles'])->toBeArray();

    $db = null;
    unlink($chemin);
});

// ── Diversité enrichie ───────────────────────────────────

test('analyserDiversiteAncres devrait détecter la sur-optimisation', function (): void {
    $chemin = sys_get_temp_dir() . '/test_diversite_enrichi_' . uniqid() . '.sqlite';
    $db = creerBaseLiensTest($chemin);

    $resultats = analyserDiversiteAncres($db);

    expect($resultats)->toHaveKeys(['nb_sur_optimisees', 'nb_a_risque']);

    // Chaque page doit avoir les nouveaux champs
    if (!empty($resultats['pages'])) {
        expect($resultats['pages'][0])->toHaveKeys(['section', 'ancre_dominante', 'pct_dominante', 'risque_sur_optimisation']);
    }

    $db = null;
    unlink($chemin);
});

// ── Hubs enrichis ────────────────────────────────────────

test('analyserHubsAutorites devrait inclure ratio et pages puits', function (): void {
    $chemin = sys_get_temp_dir() . '/test_hubs_enrichi_' . uniqid() . '.sqlite';
    $db = creerBaseLiensTest($chemin);

    $resultats = analyserHubsAutorites($db);

    // Vérifier le ratio IN/OUT
    if (!empty($resultats['hubs'])) {
        expect($resultats['hubs'][0])->toHaveKeys(['nb_entrants', 'ratio_in_out']);
    }
    if (!empty($resultats['autorites'])) {
        expect($resultats['autorites'][0])->toHaveKeys(['nb_sortants', 'ratio_in_out']);
    }

    // pages_puits doit exister
    expect($resultats)->toHaveKey('pages_puits');
    expect($resultats['pages_puits'])->toBeArray();

    $db = null;
    unlink($chemin);
});

// ── Sections enrichies ───────────────────────────────────

test('analyserDistributionSections devrait inclure diagnostics et îlots', function (): void {
    $chemin = sys_get_temp_dir() . '/test_sections_enrichi_' . uniqid() . '.sqlite';
    $db = creerBaseLiensTest($chemin);

    $resultats = analyserDistributionSections($db);

    expect($resultats)->toHaveKey('ilots');

    // Chaque section doit avoir nb_pages, top_flux, diagnostic
    foreach ($resultats['totaux'] as $section => $t) {
        expect($t)->toHaveKeys(['nb_pages', 'top_flux', 'diagnostic']);
        expect($t['nb_pages'])->toBeGreaterThan(0);
        expect($t['diagnostic'])->toBeString();
    }

    $db = null;
    unlink($chemin);
});

// ── PageRank enrichi ─────────────────────────────────────

test('analyserPageRank devrait inclure efficacité et alertes', function (): void {
    $chemin = sys_get_temp_dir() . '/test_pr_enrichi_' . uniqid() . '.sqlite';
    $db = creerBaseLiensTest($chemin);

    $resultats = analyserPageRank($db);

    // Efficacité présente sur chaque page
    if (!empty($resultats['classement'])) {
        expect($resultats['classement'][0])->toHaveKey('efficacite');
        expect($resultats['classement'][0]['efficacite'])->toBeGreaterThanOrEqual(0);
    }

    // Alertes présentes
    expect($resultats)->toHaveKey('alertes');
    expect($resultats['alertes'])->toBeArray();

    // Limite augmentée (vérifier que ça ne plante pas avec peu de données)
    expect(count($resultats['classement']))->toBeLessThanOrEqual(500);

    $db = null;
    unlink($chemin);
});

// ── Liste ancres enrichie ────────────────────────────────

test('analyserListeAncres devrait inclure classification et stats génériques', function (): void {
    $chemin = sys_get_temp_dir() . '/test_ancres_enrichi_' . uniqid() . '.sqlite';
    $db = creerBaseLiensTest($chemin);

    $resultats = analyserListeAncres($db);

    expect($resultats)->toHaveKeys([
        'nb_generiques', 'pct_generiques', 'nb_vides', 'longueur_moyenne',
        'nb_suspectes_cannib', 'types_distribution',
    ]);

    // Chaque ancre doit avoir type et longueur
    if (!empty($resultats['ancres'])) {
        expect($resultats['ancres'][0])->toHaveKeys(['type', 'longueur_mots', 'suspect_cannibale']);
        expect($resultats['ancres'][0]['type'])->toBeIn(['vide', 'generique', 'url_nue', 'mot_cle', 'descriptif']);
    }

    expect($resultats['types_distribution'])->toHaveKeys(['vide', 'generique', 'url_nue', 'mot_cle', 'descriptif']);

    $db = null;
    unlink($chemin);
});

// ── Cannibalisation automatique ──────────────────────────

test('analyserCannibalisationAuto devrait détecter les ancres avec destinations multiples', function (): void {
    $chemin = sys_get_temp_dir() . '/test_cannib_auto_' . uniqid() . '.sqlite';
    $db = creerBaseLiensTest($chemin);

    $resultats = analyserCannibalisationAuto($db);

    expect($resultats['type'])->toBe('cannibale_auto')
        ->and($resultats['nb_ancres_analysees'])->toBeGreaterThanOrEqual(0)
        ->and($resultats['nb_cannibalisees'])->toBeGreaterThanOrEqual(0)
        ->and($resultats['resultats'])->toBeArray();

    // Si des résultats existent, vérifier la structure
    if (!empty($resultats['resultats'])) {
        $premier = $resultats['resultats'][0];
        expect($premier)->toHaveKeys([
            'ancre', 'destination_dominante', 'pct_dominant', 'nb_total_liens',
            'nb_destinations', 'destinations_parasites', 'nb_liens_parasites',
            'ratio', 'severite', 'impact',
        ]);
        expect($premier['pct_dominant'])->toBeGreaterThan(0);
    }

    $db = null;
    unlink($chemin);
});

// ── Profondeur BFS ───────────────────────────────────────

test('calculerProfondeur devrait calculer la distance BFS depuis la homepage', function (): void {
    $chemin = sys_get_temp_dir() . '/test_profondeur_' . uniqid() . '.sqlite';
    $db = creerBaseLiensTest($chemin);

    $homepage = detecterHomepage($db);
    expect($homepage)->not->toBeEmpty();

    $profondeur = calculerProfondeur($db, $homepage);

    expect($profondeur)->toHaveKeys(['distribution', 'moyenne', 'max', 'inaccessibles']);
    expect($profondeur['distribution'])->toBeArray()->not->toBeEmpty();
    expect($profondeur['moyenne'])->toBeGreaterThan(0);
    expect($profondeur['max'])->toBeGreaterThan(0);

    $db = null;
    unlink($chemin);
});

test('le PageRank devrait favoriser les pages avec ancres descriptives (surfeur raisonnable)', function (): void {
    // Créer une base simple où seule la qualité d'ancre diffère
    $chemin = sys_get_temp_dir() . '/test_pr_ancres_' . uniqid() . '.sqlite';
    $db = new PDO('sqlite:' . $chemin);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE liens (source TEXT NOT NULL, destination TEXT NOT NULL, ancre TEXT NOT NULL)');
    $db->exec('CREATE INDEX idx_source ON liens(source)');
    $db->exec('CREATE INDEX idx_destination ON liens(destination)');

    $stmt = $db->prepare('INSERT INTO liens VALUES (?, ?, ?)');

    // Page A lie vers B avec une ancre descriptive
    $stmt->execute(['https://ex.com/a', 'https://ex.com/b', 'guide complet chaussures running']);
    // Page A lie vers C avec une ancre générique
    $stmt->execute(['https://ex.com/a', 'https://ex.com/c', 'cliquez ici']);
    // B et C n'ont pas d'autres liens

    $resultats = analyserPageRank($db);

    // Trouver les scores de B et C
    $scoreB = null;
    $scoreC = null;
    foreach ($resultats['classement'] as $p) {
        if ($p['url'] === 'https://ex.com/b') $scoreB = $p['score'];
        if ($p['url'] === 'https://ex.com/c') $scoreC = $p['score'];
    }

    // B devrait avoir un score >= C (ancre descriptive > générique)
    // Les deux ne peuvent pas avoir le même score car les poids diffèrent
    expect($scoreB)->toBeGreaterThanOrEqual($scoreC);

    $db = null;
    unlink($chemin);
});
