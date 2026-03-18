<?php

declare(strict_types=1);

/**
 * Bridge vers les données Search Console du plugin SC de la plateforme.
 * Lecture directe de la BDD sc_performance_data (pas d'OAuth supplémentaire).
 */

/**
 * Vérifie si les données GSC sont disponibles.
 * En standalone : toujours false.
 * En plateforme : vérifie l'existence des tables sc_*.
 */
function gscDisponible(): bool
{
    if (!defined('PLATFORM_EMBEDDED')) {
        return false;
    }

    $pdo = obtenirConnexionPlateforme();
    if ($pdo === null) {
        return false;
    }

    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'sc_performance_data'");
        return $stmt->rowCount() > 0;
    } catch (\PDOException) {
        return false;
    }
}

/**
 * Retourne les sites GSC synchronisés pour un utilisateur.
 *
 * @return array<int, array{id: int, url: string}>
 */
function obtenirSitesGsc(int $userId): array
{
    $pdo = obtenirConnexionPlateforme();
    if ($pdo === null) {
        return [];
    }

    try {
        $stmt = $pdo->prepare('
            SELECT id, site_url as url
            FROM sc_sites
            WHERE user_id = :uid AND active = 1
            ORDER BY site_url
        ');
        $stmt->execute([':uid' => $userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException) {
        return [];
    }
}

/**
 * Récupère les données de performance GSC agrégées par page et requête.
 *
 * @return array<int, array{page: string, query: string, clics: int, impressions: int, position: float}>
 */
function obtenirDonneesGsc(int $siteId, string $dateDebut, string $dateFin): array
{
    $pdo = obtenirConnexionPlateforme();
    if ($pdo === null) {
        return [];
    }

    try {
        $stmt = $pdo->prepare('
            SELECT
                page,
                query,
                SUM(clicks) as clics,
                SUM(impressions) as impressions,
                AVG(position) as position
            FROM sc_performance_data
            WHERE site_id = :sid
              AND data_date BETWEEN :from AND :to
            GROUP BY page, query
        ');
        $stmt->execute([
            ':sid' => $siteId,
            ':from' => $dateDebut,
            ':to' => $dateFin,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException) {
        return [];
    }
}

/**
 * Récupère les données GSC agrégées par page uniquement.
 *
 * @return array<string, array{clics: int, impressions: int, position: float, requetes: array}>
 */
function obtenirDonneesGscParPage(int $siteId, string $dateDebut, string $dateFin): array
{
    $donneesRaw = obtenirDonneesGsc($siteId, $dateDebut, $dateFin);
    $parPage = [];

    foreach ($donneesRaw as $row) {
        $page = $row['page'];
        if (!isset($parPage[$page])) {
            $parPage[$page] = [
                'clics' => 0,
                'impressions' => 0,
                'position_totale' => 0,
                'nb_requetes' => 0,
                'requetes' => [],
            ];
        }
        $parPage[$page]['clics'] += (int) $row['clics'];
        $parPage[$page]['impressions'] += (int) $row['impressions'];
        $parPage[$page]['position_totale'] += (float) $row['position'] * (int) $row['impressions'];
        $parPage[$page]['nb_requetes']++;
        $parPage[$page]['requetes'][] = [
            'query' => $row['query'],
            'clics' => (int) $row['clics'],
            'impressions' => (int) $row['impressions'],
            'position' => (float) $row['position'],
        ];
    }

    // Calculer la position moyenne pondérée par impressions
    foreach ($parPage as &$data) {
        $totalImpressions = $data['impressions'];
        $data['position'] = $totalImpressions > 0
            ? round($data['position_totale'] / $totalImpressions, 1)
            : 0;
        unset($data['position_totale']);

        // Trier les requêtes par clics décroissants
        usort($data['requetes'], function (array $a, array $b): int {
            return $b['clics'] <=> $a['clics'];
        });
    }
    unset($data);

    return $parPage;
}

/**
 * Obtient la connexion PDO à la base de données plateforme.
 * Utilise les mêmes paramètres que le framework de la plateforme.
 */
function obtenirConnexionPlateforme(): ?PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    // En mode plateforme, les variables d'environnement DB sont disponibles
    $host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: null;
    $dbname = $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: null;
    $user = $_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: null;
    $pass = $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: null;

    if (!$host || !$dbname || !$user) {
        return null;
    }

    try {
        $dsn = 'mysql:host=' . $host . ';dbname=' . $dbname . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $user, $pass ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    } catch (\PDOException) {
        return null;
    }
}
