<?php

declare(strict_types=1);

/**
 * Écrit la progression de manière atomique (tmp + rename).
 *
 * @param string $chemin Chemin vers progress.json
 * @param array<string, mixed> $donnees Données de progression
 */
function ecrireProgression(string $chemin, array $donnees): void
{
    $contenu = json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $cheminTemp = $chemin . '.tmp';
    file_put_contents($cheminTemp, $contenu, LOCK_EX);
    rename($cheminTemp, $chemin);
}

/**
 * Valide un jobId (alphanumérique, tirets, underscores).
 */
function validerJobId(string $jobId): bool
{
    return (bool) preg_match('/^[a-zA-Z0-9_-]+$/', $jobId);
}

/**
 * Retourne le chemin du répertoire d'un job.
 */
function cheminJob(string $jobId): string
{
    return __DIR__ . '/data/jobs/' . $jobId;
}

/**
 * Nettoie les jobs de plus de 24h.
 */
function nettoyerAnciensJobs(int $ttlSecondes = 86400): void
{
    $dossierJobs = __DIR__ . '/data/jobs';
    if (!is_dir($dossierJobs)) {
        return;
    }

    $limite = time() - $ttlSecondes;

    foreach (new DirectoryIterator($dossierJobs) as $item) {
        if ($item->isDot() || !$item->isDir()) {
            continue;
        }
        if ($item->getMTime() >= $limite) {
            continue;
        }

        $chemin = $item->getPathname();
        $fichiers = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($chemin, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($fichiers as $fichier) {
            $fichier->isDir() ? rmdir($fichier->getPathname()) : unlink($fichier->getPathname());
        }
        @rmdir($chemin);
    }
}

/**
 * Envoie une réponse JSON et termine le script.
 *
 * @param array<string, mixed> $donnees
 */
function repondreJson(array $donnees, int $codeHttp = 200): never
{
    http_response_code($codeHttp);
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($donnees, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Envoie une erreur JSON et termine le script.
 */
function repondreErreur(string $message, int $codeHttp = 400): never
{
    repondreJson(['erreur' => $message], $codeHttp);
}

/**
 * Normalise une ancre pour la comparaison : trim + lowercase.
 */
function normaliserAncre(string $ancre): string
{
    return mb_strtolower(trim($ancre), 'UTF-8');
}

/**
 * Normalise une URL : trim, suppression du trailing slash et du fragment.
 */
function normaliserUrl(string $url): string
{
    $url = trim($url);
    $url = strtok($url, '#') ?: $url;
    return rtrim($url, '/');
}

/**
 * Détermine la sévérité selon le ratio de cannibalisation.
 *
 * @return array{label: string, classe: string, ordre: int}
 */
function determinerSeverite(float $ratio): array
{
    if ($ratio === 0.0) {
        return ['label' => 'Aucune', 'classe' => 'badge-succes', 'ordre' => 0];
    }
    if ($ratio <= 25) {
        return ['label' => 'Faible', 'classe' => 'badge-succes', 'ordre' => 1];
    }
    if ($ratio <= 50) {
        return ['label' => 'Modérée', 'classe' => 'badge-attention', 'ordre' => 2];
    }
    if ($ratio <= 75) {
        return ['label' => 'Élevée', 'classe' => 'badge-attention', 'ordre' => 3];
    }

    return ['label' => 'Critique', 'classe' => 'badge-erreur', 'ordre' => 4];
}

/**
 * Convertit une valeur PHP ini (ex: "2G", "128M") en octets.
 */
function convertirEnOctets(string $valeur): int
{
    $valeur = trim($valeur);
    $nombre = (int) $valeur;
    $unite = strtolower(substr($valeur, -1));

    return match ($unite) {
        'g' => $nombre * 1073741824,
        'm' => $nombre * 1048576,
        'k' => $nombre * 1024,
        default => $nombre,
    };
}

/**
 * Formate un nombre d'octets en unité lisible.
 */
function formaterOctets(int $octets): string
{
    if ($octets < 1024) {
        return $octets . ' o';
    }
    if ($octets < 1048576) {
        return round($octets / 1024, 1) . ' Ko';
    }
    if ($octets < 1073741824) {
        return round($octets / 1048576, 1) . ' Mo';
    }

    return round($octets / 1073741824, 2) . ' Go';
}
