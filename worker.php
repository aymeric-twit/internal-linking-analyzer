<?php

declare(strict_types=1);

/**
 * Worker CLI : import du fichier CSV de liens dans une base SQLite.
 *
 * Usage : php worker.php {importId} {colSource} {colDestination} {colAncre} {cheminCsv} {userId}
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/user_context.php';

// Pas de limite de temps pour le worker CLI
set_time_limit(0);
ini_set('memory_limit', '256M');

// Lecture des arguments
$importId = $argv[1] ?? '';
$colSource = (int) ($argv[2] ?? 0);
$colDestination = (int) ($argv[3] ?? 2);
$colAncre = (int) ($argv[4] ?? 5);
$colFiltre = (int) ($argv[5] ?? -1);
$valeurFiltre = $argv[6] ?? '';
$cheminCsv = $argv[7] ?? '';
$userId = (int) ($argv[8] ?? 0);

if (!validerJobId($importId) || $cheminCsv === '') {
    fwrite(STDERR, "Arguments invalides.\n");
    exit(1);
}

$dossierImport = cheminImport($userId, $importId);
$cheminProgression = $dossierImport . '/progress.json';
$cheminSqlite = $dossierImport . '/liens.sqlite';

// Gestion des erreurs fatales
register_shutdown_function(function () use ($cheminProgression): void {
    $erreur = error_get_last();
    if ($erreur !== null && in_array($erreur['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        ecrireProgression($cheminProgression, [
            'phase' => 'import',
            'statut' => 'erreur',
            'message' => 'Erreur fatale : ' . $erreur['message'],
        ]);
    }
});

try {
    // Vérifier que le fichier CSV existe
    if (!is_file($cheminCsv) || !is_readable($cheminCsv)) {
        throw new RuntimeException('Fichier CSV introuvable : ' . $cheminCsv);
    }

    $tailleFichier = filesize($cheminCsv);
    if ($tailleFichier === false || $tailleFichier === 0) {
        throw new RuntimeException('Fichier CSV vide ou illisible.');
    }

    // Créer la base SQLite
    $db = new PDO('sqlite:' . $cheminSqlite);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Optimisations SQLite pour l'import de masse
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA synchronous = OFF');
    $db->exec('PRAGMA temp_store = MEMORY');
    $db->exec('PRAGMA cache_size = -64000');

    // Créer la table
    $db->exec('
        CREATE TABLE IF NOT EXISTS liens (
            source TEXT NOT NULL,
            destination TEXT NOT NULL,
            ancre TEXT NOT NULL
        )
    ');

    // Ouvrir le CSV en streaming
    $handle = fopen($cheminCsv, 'r');
    if ($handle === false) {
        throw new RuntimeException('Impossible d\'ouvrir le fichier CSV.');
    }

    // Détecter le BOM UTF-8 et l'ignorer
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        fseek($handle, 0);
    }

    // Sauter la première ligne (en-têtes)
    fgetcsv($handle, 0, ',', '"', '\\');

    $db->beginTransaction();
    $stmt = $db->prepare('INSERT INTO liens (source, destination, ancre) VALUES (:source, :destination, :ancre)');

    $lignesImportees = 0;
    $lignesIgnorees = 0;
    $lignesFiltrees = 0;
    $debut = time();
    $colMax = max($colSource, $colDestination, $colAncre);
    if ($colFiltre >= 0) {
        $colMax = max($colMax, $colFiltre);
    }

    while (($ligne = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        // Vérifier que les colonnes existent
        if (count($ligne) <= $colMax) {
            $lignesIgnorees++;
            continue;
        }

        // Appliquer le filtre par colonne (ex: Link Position = "Content")
        if ($colFiltre >= 0 && $valeurFiltre !== '') {
            $valeurColonne = $ligne[$colFiltre] ?? '';
            if (stripos($valeurColonne, $valeurFiltre) === false) {
                $lignesFiltrees++;
                continue;
            }
        }

        $source = normaliserUrl($ligne[$colSource]);
        $destination = normaliserUrl($ligne[$colDestination]);
        $ancre = normaliserAncre($ligne[$colAncre]);

        // Ignorer les lignes vides
        if ($source === '' || $destination === '' || $ancre === '') {
            $lignesIgnorees++;
            continue;
        }

        $stmt->execute([
            ':source' => $source,
            ':destination' => $destination,
            ':ancre' => $ancre,
        ]);

        $lignesImportees++;

        // Mise à jour de la progression tous les 50 000 lignes
        if ($lignesImportees % 50000 === 0) {
            $db->commit();
            $db->beginTransaction();

            $octetsLus = ftell($handle);
            $pourcentage = $tailleFichier > 0
                ? min(99, (int) round(($octetsLus / $tailleFichier) * 100))
                : 0;

            ecrireProgression($cheminProgression, [
                'phase' => 'import',
                'statut' => 'en_cours',
                'lignes_importees' => $lignesImportees,
                'lignes_ignorees' => $lignesIgnorees,
                'lignes_filtrees' => $lignesFiltrees,
                'taille_fichier' => $tailleFichier,
                'octets_lus' => $octetsLus,
                'pourcentage' => $pourcentage,
                'debut' => $debut,
                'duree_secondes' => time() - $debut,
            ]);
        }
    }

    // Commit final
    $db->commit();
    fclose($handle);

    // Création des index (phase indexation)
    ecrireProgression($cheminProgression, [
        'phase' => 'indexation',
        'statut' => 'en_cours',
        'lignes_importees' => $lignesImportees,
        'pourcentage' => 99,
        'debut' => $debut,
        'duree_secondes' => time() - $debut,
    ]);

    $db->exec('CREATE INDEX IF NOT EXISTS idx_ancre ON liens(ancre)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_destination ON liens(destination)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_ancre_dest ON liens(ancre, destination)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_source ON liens(source)');

    // Calculer les statistiques
    $nbAncresDistinctes = (int) $db->query('SELECT COUNT(DISTINCT ancre) FROM liens')->fetchColumn();
    $nbUrlsDistinctes = (int) $db->query('SELECT COUNT(DISTINCT destination) FROM liens')->fetchColumn();

    // Écrire la progression finale
    ecrireProgression($cheminProgression, [
        'phase' => 'import',
        'statut' => 'import_termine',
        'lignes_importees' => $lignesImportees,
        'lignes_ignorees' => $lignesIgnorees,
        'lignes_filtrees' => $lignesFiltrees,
        'ancres_distinctes' => $nbAncresDistinctes,
        'urls_distinctes' => $nbUrlsDistinctes,
        'taille_fichier' => $tailleFichier,
        'pourcentage' => 100,
        'debut' => $debut,
        'duree_secondes' => time() - $debut,
    ]);

    // Mettre à jour le registre des imports de l'utilisateur
    mettreAJourImport($userId, $importId, [
        'nb_lignes' => $lignesImportees,
        'nb_ancres_distinctes' => $nbAncresDistinctes,
        'nb_urls_distinctes' => $nbUrlsDistinctes,
        'statut' => 'pret',
    ]);
} catch (Throwable $e) {
    ecrireProgression($cheminProgression, [
        'phase' => 'import',
        'statut' => 'erreur',
        'message' => $e->getMessage(),
        'lignes_importees' => $lignesImportees ?? 0,
    ]);

    // Mettre à jour le registre avec le statut erreur
    mettreAJourImport($userId, $importId, [
        'statut' => 'erreur',
    ]);

    fwrite(STDERR, 'Erreur worker : ' . $e->getMessage() . "\n");
    exit(1);
}
