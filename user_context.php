<?php

declare(strict_types=1);

/**
 * Retourne l'identifiant de l'utilisateur courant.
 * En mode plateforme : via \Auth::id().
 * En mode standalone : retourne 0.
 */
function obtenirUserId(): int
{
    if (defined('PLATFORM_EMBEDDED') && class_exists('\\Auth')) {
        return \Auth::id() ?? 0;
    }

    return 0;
}

/**
 * Retourne le chemin du répertoire d'un utilisateur.
 */
function cheminUtilisateur(int $userId): string
{
    return __DIR__ . '/data/users/' . $userId;
}

/**
 * Retourne le chemin du répertoire d'un import.
 */
function cheminImport(int $userId, string $importId): string
{
    return cheminUtilisateur($userId) . '/imports/' . $importId;
}

/**
 * Retourne le chemin du fichier registre des imports d'un utilisateur.
 */
function cheminRegistreImports(int $userId): string
{
    return cheminUtilisateur($userId) . '/imports.json';
}

/**
 * Lit le registre des imports d'un utilisateur.
 *
 * @return array<int, array<string, mixed>>
 */
function lireRegistreImports(int $userId): array
{
    $chemin = cheminRegistreImports($userId);
    if (!file_exists($chemin)) {
        return [];
    }

    $contenu = file_get_contents($chemin);
    if ($contenu === false) {
        return [];
    }

    $donnees = json_decode($contenu, true);

    return is_array($donnees) ? $donnees : [];
}

/**
 * Écrit le registre des imports d'un utilisateur (écriture atomique).
 *
 * @param array<int, array<string, mixed>> $imports
 */
function ecrireRegistreImports(int $userId, array $imports): void
{
    $chemin = cheminRegistreImports($userId);
    $dossier = dirname($chemin);

    if (!is_dir($dossier)) {
        mkdir($dossier, 0755, true);
    }

    ecrireProgression($chemin, $imports);
}

/**
 * Ajoute un import au registre de l'utilisateur.
 *
 * @param array<string, mixed> $metadonnees
 */
function ajouterImport(int $userId, array $metadonnees): void
{
    $imports = lireRegistreImports($userId);
    $imports[] = $metadonnees;
    ecrireRegistreImports($userId, $imports);
}

/**
 * Met à jour un import existant dans le registre.
 *
 * @param array<string, mixed> $modifications Champs à mettre à jour
 */
function mettreAJourImport(int $userId, string $importId, array $modifications): void
{
    $imports = lireRegistreImports($userId);

    foreach ($imports as &$import) {
        if (($import['id'] ?? '') === $importId) {
            $import = array_merge($import, $modifications);
            break;
        }
    }
    unset($import);

    ecrireRegistreImports($userId, $imports);
}

/**
 * Supprime un import du registre et son répertoire sur le disque.
 */
function supprimerImportComplet(int $userId, string $importId): bool
{
    $imports = lireRegistreImports($userId);
    $trouve = false;

    $imports = array_values(array_filter($imports, function (array $import) use ($importId, &$trouve): bool {
        if (($import['id'] ?? '') === $importId) {
            $trouve = true;
            return false;
        }
        return true;
    }));

    if (!$trouve) {
        return false;
    }

    ecrireRegistreImports($userId, $imports);

    // Supprimer le répertoire de l'import
    $chemin = cheminImport($userId, $importId);
    if (is_dir($chemin)) {
        $fichiers = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($chemin, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($fichiers as $fichier) {
            $fichier->isDir() ? rmdir($fichier->getPathname()) : unlink($fichier->getPathname());
        }
        @rmdir($chemin);
    }

    return true;
}

/**
 * Vérifie que l'import appartient à l'utilisateur courant.
 * Retourne le userId si OK, sinon envoie une erreur 403.
 */
function verifierProprietaire(string $importId): int
{
    $userId = obtenirUserId();
    $imports = lireRegistreImports($userId);

    foreach ($imports as $import) {
        if (($import['id'] ?? '') === $importId) {
            return $userId;
        }
    }

    repondreErreur([
        'fr' => 'Accès refusé : cet import ne vous appartient pas.',
        'en' => 'Access denied: this import does not belong to you.',
    ], 403);
}

/**
 * Nettoie les imports de plus de 30 jours pour tous les utilisateurs.
 */
function nettoyerAnciensImports(int $ttlSecondes = 2592000): void
{
    $dossierUsers = __DIR__ . '/data/users';
    if (!is_dir($dossierUsers)) {
        return;
    }

    $limite = time() - $ttlSecondes;

    foreach (new DirectoryIterator($dossierUsers) as $userDir) {
        if ($userDir->isDot() || !$userDir->isDir()) {
            continue;
        }

        $userId = (int) $userDir->getFilename();
        $imports = lireRegistreImports($userId);
        $modifie = false;

        $imports = array_values(array_filter($imports, function (array $import) use ($userId, $limite, &$modifie): bool {
            $dateCreation = strtotime($import['date_creation'] ?? '');
            if ($dateCreation !== false && $dateCreation < $limite) {
                // Supprimer le répertoire
                $chemin = cheminImport($userId, $import['id']);
                if (is_dir($chemin)) {
                    $fichiers = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($chemin, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::CHILD_FIRST
                    );
                    foreach ($fichiers as $fichier) {
                        $fichier->isDir() ? rmdir($fichier->getPathname()) : unlink($fichier->getPathname());
                    }
                    @rmdir($chemin);
                }
                $modifie = true;
                return false;
            }
            return true;
        }));

        if ($modifie) {
            ecrireRegistreImports($userId, $imports);
        }
    }
}
