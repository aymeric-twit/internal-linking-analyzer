<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/user_context.php';

nettoyerAnciensImports();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Internal Linking Analyzer — Intelligence du maillage interne</title>
    <!-- CDN : ignorés en mode embedded -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
</head>
<body>

<!-- Navbar : supprimée en mode embedded -->
<nav class="navbar mb-4">
    <div class="container-fluid px-lg-4 py-4">
        <span class="navbar-brand mb-0 h1">
            <i class="bi bi-link-45deg"></i> Internal Linking Analyzer
            <span class="d-block d-sm-inline ms-sm-2">Intelligence du maillage interne</span>
        </span>
    </div>
</nav>

<div class="container-fluid px-lg-4 py-4">

    <!-- ══════════════════════════════════════════════ -->
    <!-- IMPORTS PRÉCÉDENTS                            -->
    <!-- ══════════════════════════════════════════════ -->
    <div id="sectionGestionImports" class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-collection me-2"></i>Imports précédents</h6>
            <button class="btn btn-sm btn-outline-secondary" id="btnNouvelImport">
                <i class="bi bi-plus-lg me-1"></i> Nouvel import
            </button>
        </div>
        <div class="card-body">
            <div id="listeImports"></div>
            <p id="aucunImport" class="text-muted mb-0 d-none" style="font-size:0.85rem;">
                Aucun import précédent. Importez un fichier de liens internes pour commencer.
            </p>
            <div id="chargementImports" class="text-center py-3">
                <div class="spinner-border spinner-border-sm text-muted" role="status"></div>
                <small class="text-muted ms-2">Chargement…</small>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════ -->
    <!-- ÉTAPE 1 : Upload fichier liens                -->
    <!-- ══════════════════════════════════════════════ -->
    <div class="row g-4" id="rowUploadLiens">
    <div class="col-md-8">
    <div id="sectionUploadLiens" class="card mb-4 d-none">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-upload me-2"></i>Étape 1 — Import du fichier de liens internes</h6>
            <button type="button" class="config-toggle" data-bs-toggle="collapse" data-bs-target="#configBody" aria-expanded="true"><i class="bi bi-chevron-down"></i></button>
        </div>
        <div class="collapse show" id="configBody">
        <div class="card-body">
            <p class="text-muted mb-3" style="font-size:0.85rem;">
                Importez votre export CSV de liens internes (ex : Screaming Frog "All Inlinks").
            </p>

            <div class="row g-3">
                <!-- Upload par morceaux (principal) -->
                <div class="col-12">
                    <div id="dropZoneLiens" class="drop-zone">
                        <i class="bi bi-cloud-arrow-up" style="font-size:2rem;color:var(--brand-teal);"></i>
                        <div class="mt-2">Glissez votre fichier CSV ici ou <strong>cliquez pour sélectionner</strong></div>
                        <div class="text-muted" style="font-size:0.8rem;">
                            Toutes tailles supportées — le fichier est envoyé par morceaux
                        </div>
                    </div>
                    <input type="file" id="inputFichierLiens" accept=".csv,.txt" class="d-none">
                    <div id="infoFichierLiens" class="fichier-info d-none"></div>
                </div>

                <!-- Chemin serveur (alternatif) -->
                <div class="col-12">
                    <div class="separator-ou">
                        <span>ou</span>
                    </div>
                    <label for="cheminServeur" class="form-label">Chemin d'un fichier déjà présent sur le serveur</label>
                    <input type="text" class="form-control" id="cheminServeur"
                           placeholder="/home/votrecompte/data/all_inlinks.csv">
                    <div class="form-text">
                        Si le fichier est déjà sur le serveur (upload SFTP), indiquez son chemin absolu ici.
                    </div>
                </div>
            </div>

            <!-- Mapping de colonnes (masqué initialement) -->
            <div id="sectionMapping" class="d-none mt-4">
                <h6 class="mb-3"><i class="bi bi-columns-gap me-2"></i>Mapping des colonnes</h6>
                <div id="apercuCsv" class="table-responsive mb-3"></div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="colSource" class="form-label">Colonne Source</label>
                        <select class="form-select" id="colSource"></select>
                    </div>
                    <div class="col-md-4">
                        <label for="colDestination" class="form-label">Colonne Destination</label>
                        <select class="form-select" id="colDestination"></select>
                    </div>
                    <div class="col-md-4">
                        <label for="colAncre" class="form-label">Colonne Ancre</label>
                        <select class="form-select" id="colAncre"></select>
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-md-4">
                        <label for="colFiltre" class="form-label">Colonne filtre <span class="text-muted">(optionnel)</span></label>
                        <select class="form-select" id="colFiltre">
                            <option value="">— Aucun filtre —</option>
                        </select>
                        <div class="form-text">Ex : "Link Position" dans Screaming Frog</div>
                    </div>
                    <div class="col-md-4 d-none" id="zoneValeurFiltre">
                        <label for="valeurFiltre" class="form-label">Valeur à conserver</label>
                        <input type="text" class="form-control" id="valeurFiltre" placeholder="ex: Content">
                        <div class="form-text">Seules les lignes contenant cette valeur seront importées</div>
                    </div>
                </div>
            </div>

            <!-- Bouton importer -->
            <div class="mt-4">
                <button id="btnImporter" class="btn btn-primary" disabled>
                    <i class="bi bi-database-add me-1"></i> Importer dans la base
                </button>
            </div>
        </div>
        </div>
    </div>
    </div><!-- /.col-md-8 -->
    <div class="col-md-4" id="helpPanel">
        <div id="platformCreditsSlot"></div>
        <div class="config-help-panel">
            <div class="help-title mb-2">
                <i class="bi bi-info-circle me-1"></i> Comment ça marche
            </div>
            <ul>
                <li>Importez un export CSV de liens internes (Screaming Frog : <strong>"All Inlinks"</strong>).</li>
                <li>Mappez les colonnes : <strong>Source</strong>, <strong>Destination</strong>, <strong>Ancre</strong> (mapping flexible).</li>
                <li>Lancez l'analyse pour détecter les problèmes de maillage interne.</li>
            </ul>
            <hr>
            <div class="help-title mb-0" role="button" data-bs-toggle="collapse" data-bs-target="#helpFonctionnalites" aria-expanded="false">
                <i class="bi bi-lightbulb me-1"></i> Fonctionnalités <i class="bi bi-chevron-down help-chevron ms-1"></i>
            </div>
            <div class="collapse" id="helpFonctionnalites">
                <ul class="mt-2 mb-0">
                    <li>Cannibalisation d'ancres : même texte d'ancre vers plusieurs pages</li>
                    <li>Calcul du PageRank interne par page</li>
                    <li>Détection des pages orphelines (sans liens entrants)</li>
                    <li>Analyse de la diversité des ancres</li>
                    <li>Support fichiers volumineux (upload par chunks)</li>
                </ul>
            </div>
        </div>
    </div>
    </div><!-- /.row -->

    <!-- ══════════════════════════════════════════════ -->
    <!-- PROGRESSION UPLOAD + IMPORT                   -->
    <!-- ══════════════════════════════════════════════ -->
    <div id="sectionProgression" class="card mb-4 d-none">
        <div class="card-header">
            <h6 class="mb-0"><i class="bi bi-hourglass-split me-2"></i>Import en cours</h6>
        </div>
        <div class="card-body">
            <!-- Barre upload réseau -->
            <div id="barreUpload" class="d-none mb-3">
                <div class="d-flex justify-content-between mb-1">
                    <small class="text-muted">Upload du fichier</small>
                    <small id="labelUpload" class="text-muted">0%</small>
                </div>
                <div class="progress">
                    <div id="progressUpload" class="progress-bar" role="progressbar" style="width:0%"></div>
                </div>
            </div>

            <!-- Barre import SQLite -->
            <div class="d-flex justify-content-between mb-1">
                <small class="text-muted">Import dans la base SQLite</small>
                <small id="labelImport" class="text-muted">0%</small>
            </div>
            <div class="progress mb-3">
                <div id="progressImport" class="progress-bar" role="progressbar" style="width:0%"></div>
            </div>

            <div class="row g-2" style="font-size:0.85rem;">
                <div class="col-auto">
                    <span class="text-muted">Lignes importées :</span>
                    <strong id="compteurLignes">0</strong>
                </div>
                <div class="col-auto">
                    <span class="text-muted">Temps écoulé :</span>
                    <strong id="tempsEcoule">0s</strong>
                </div>
                <div class="col-auto">
                    <span class="text-muted">Estimation restante :</span>
                    <strong id="tempsRestant">—</strong>
                </div>
            </div>

            <!-- Status -->
            <div id="statusImport" class="status-msg status-loading mt-3 d-none"></div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════ -->
    <!-- RÉSULTATS (9 onglets)                         -->
    <!-- ══════════════════════════════════════════════ -->
    <div id="sectionResultats" class="d-none">
        <!-- KPI -->
        <div id="kpiResultats" class="kpi-row mb-4"></div>

        <!-- Onglets -->
        <div class="card">
            <div class="card-header p-0 pt-2 px-3">
                <ul class="nav nav-tabs" id="tabsResultats" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="tab-dashboard" data-bs-toggle="tab"
                                data-bs-target="#contenu-dashboard" type="button" role="tab"
                                data-analyse="dashboard">
                            <i class="bi bi-speedometer2 me-1"></i>Dashboard
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-pagerank" data-bs-toggle="tab"
                                data-bs-target="#contenu-pagerank" type="button" role="tab"
                                data-analyse="pagerank">
                            <i class="bi bi-graph-up me-1"></i>PageRank
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-orphelins" data-bs-toggle="tab"
                                data-bs-target="#contenu-orphelins" type="button" role="tab"
                                data-analyse="orphelins">
                            <i class="bi bi-exclamation-diamond me-1"></i>Orphelines
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-diversite" data-bs-toggle="tab"
                                data-bs-target="#contenu-diversite" type="button" role="tab"
                                data-analyse="diversite_ancres">
                            <i class="bi bi-shuffle me-1"></i>Diversité
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-hubs" data-bs-toggle="tab"
                                data-bs-target="#contenu-hubs" type="button" role="tab"
                                data-analyse="hubs_autorites">
                            <i class="bi bi-diagram-3 me-1"></i>Hubs
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-distribution" data-bs-toggle="tab"
                                data-bs-target="#contenu-distribution" type="button" role="tab"
                                data-analyse="distribution_sections">
                            <i class="bi bi-grid-3x3 me-1"></i>Sections
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-ancres" data-bs-toggle="tab"
                                data-bs-target="#contenu-ancres" type="button" role="tab"
                                data-analyse="liste_ancres">
                            <i class="bi bi-fonts me-1"></i>Ancres
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-cannibale" data-bs-toggle="tab"
                                data-bs-target="#contenu-cannibale" type="button" role="tab">
                            <i class="bi bi-bug me-1"></i>Cannibalisation
                        </button>
                    </li>
                    <!-- Onglet GSC (conditionnel, masqué par défaut) -->
                    <li class="nav-item d-none" id="tabGscItem" role="presentation">
                        <button class="nav-link" id="tab-gsc" data-bs-toggle="tab"
                                data-bs-target="#contenu-gsc" type="button" role="tab">
                            <i class="bi bi-google me-1"></i>GSC Insights
                        </button>
                    </li>
                </ul>
            </div>
            <div class="card-body">
                <div class="tab-content" id="tabsContenu">
                    <!-- Onglet Dashboard -->
                    <div class="tab-pane fade show active" id="contenu-dashboard" role="tabpanel">
                        <div id="chargementDashboard" class="text-center py-4 d-none">
                            <div class="spinner-border text-primary" role="status"></div>
                            <p class="text-muted mt-2">Chargement du dashboard…</p>
                        </div>
                        <div id="resultatsDashboard"></div>
                    </div>

                    <!-- Onglet PageRank -->
                    <div class="tab-pane fade" id="contenu-pagerank" role="tabpanel">
                        <div id="chargementPagerank" class="text-center py-4 d-none">
                            <div class="spinner-border text-primary" role="status"></div>
                            <p class="text-muted mt-2">Calcul du PageRank (surfeur raisonnable)…</p>
                        </div>
                        <div id="resultatsPagerank"></div>
                    </div>

                    <!-- Onglet Orphelines -->
                    <div class="tab-pane fade" id="contenu-orphelins" role="tabpanel">
                        <div id="chargementOrphelins" class="text-center py-4 d-none">
                            <div class="spinner-border text-primary" role="status"></div>
                            <p class="text-muted mt-2">Détection des pages orphelines…</p>
                        </div>
                        <div id="resultatsOrphelins"></div>
                    </div>

                    <!-- Onglet Diversité -->
                    <div class="tab-pane fade" id="contenu-diversite" role="tabpanel">
                        <div id="chargementDiversite" class="text-center py-4 d-none">
                            <div class="spinner-border text-primary" role="status"></div>
                            <p class="text-muted mt-2">Analyse de la diversité des ancres…</p>
                        </div>
                        <div id="resultatsDiversite"></div>
                    </div>

                    <!-- Onglet Hubs & Autorités -->
                    <div class="tab-pane fade" id="contenu-hubs" role="tabpanel">
                        <div id="chargementHubs" class="text-center py-4 d-none">
                            <div class="spinner-border text-primary" role="status"></div>
                            <p class="text-muted mt-2">Identification des hubs et autorités…</p>
                        </div>
                        <div id="resultatsHubs"></div>
                    </div>

                    <!-- Onglet Distribution -->
                    <div class="tab-pane fade" id="contenu-distribution" role="tabpanel">
                        <div id="chargementDistribution" class="text-center py-4 d-none">
                            <div class="spinner-border text-primary" role="status"></div>
                            <p class="text-muted mt-2">Analyse de la distribution par section…</p>
                        </div>
                        <div id="resultatsDistribution"></div>
                    </div>

                    <!-- Onglet Ancres -->
                    <div class="tab-pane fade" id="contenu-ancres" role="tabpanel">
                        <div id="chargementAncres" class="text-center py-4 d-none">
                            <div class="spinner-border text-primary" role="status"></div>
                            <p class="text-muted mt-2">Chargement de la liste des ancres…</p>
                        </div>
                        <div id="resultatsAncres"></div>
                    </div>

                    <!-- Onglet Cannibalisation -->
                    <div class="tab-pane fade" id="contenu-cannibale" role="tabpanel">
                        <div id="zoneCannibale">
                            <!-- Zone upload ancres (inline) -->
                            <div id="uploadCannibaleZone">
                                <div id="cannibaleAutoZone" class="mb-4">
                                    <h6><i class="bi bi-magic me-1"></i>Détection automatique</h6>
                                    <p class="text-muted mb-2" style="font-size:0.85rem;">
                                        Détecte les ancres pointant vers 3+ destinations différentes — sans fichier CSV nécessaire.
                                    </p>
                                    <button id="btnCannibaleAuto" class="btn btn-primary btn-sm mb-3">
                                        <i class="bi bi-magic me-1"></i> Détecter automatiquement
                                    </button>
                                    <div id="resultatsAutoCannibalisation" class="d-none"></div>
                                </div>
                                <hr class="my-3">
                                <h6><i class="bi bi-file-earmark-text me-1"></i>Détection manuelle (fichier CSV)</h6>
                                <p class="text-muted mb-3" style="font-size:0.85rem;">
                                    Uploadez le fichier CSV contenant les couples <strong>ancre souhaitée ; URL cible</strong>
                                    pour détecter la cannibalisation.
                                </p>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-8">
                                        <div id="dropZoneAncres" class="drop-zone drop-zone-sm">
                                            <i class="bi bi-file-earmark-text" style="font-size:1.5rem;color:var(--brand-teal);"></i>
                                            <div>Glissez votre fichier CSV ici ou <strong>cliquez</strong></div>
                                        </div>
                                        <input type="file" id="inputFichierAncres" accept=".csv,.txt" class="d-none">
                                        <div id="infoFichierAncres" class="fichier-info d-none"></div>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="separateurAncres" class="form-label">Séparateur</label>
                                        <select class="form-select" id="separateurAncres">
                                            <option value=";" selected>Point-virgule (;)</option>
                                            <option value=",">Virgule (,)</option>
                                            <option value="\t">Tabulation</option>
                                        </select>
                                        <div class="form-check mt-2">
                                            <input class="form-check-input" type="checkbox" id="avecEntete">
                                            <label class="form-check-label" for="avecEntete" style="font-size:0.85rem;">
                                                La première ligne est un en-tête
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <!-- Aperçu fichier ancres -->
                                <div id="apercuAncres" class="table-responsive d-none"></div>

                                <div class="mt-3">
                                    <button id="btnAnalyser" class="btn btn-primary" disabled>
                                        <i class="bi bi-search me-1"></i> Analyser la cannibalisation
                                    </button>
                                </div>
                            </div>

                            <!-- Progression analyse -->
                            <div id="sectionProgressionAnalyse" class="text-center py-4 d-none">
                                <div class="spinner-border text-primary mb-3" role="status"></div>
                                <p class="text-muted">Analyse de la cannibalisation en cours…</p>
                            </div>

                            <!-- Résultats cannibalisation -->
                            <div id="resultatsCannibalisation" class="d-none">
                                <div id="kpiCannibale" class="kpi-row mb-4"></div>

                                <!-- Sous-onglets cannibalisation -->
                                <ul class="nav nav-tabs nav-tabs-inner mb-3" id="sousTabsCannibale" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active" data-bs-toggle="tab"
                                                data-bs-target="#cannibale-resume" type="button" role="tab">
                                            Vue d'ensemble
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" data-bs-toggle="tab"
                                                data-bs-target="#cannibale-detail" type="button" role="tab">
                                            Détail par ancre
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" data-bs-toggle="tab"
                                                data-bs-target="#cannibale-actions" type="button" role="tab">
                                            Actions recommandées
                                        </button>
                                    </li>
                                </ul>

                                <div class="tab-content">
                                    <!-- Résumé -->
                                    <div class="tab-pane fade show active" id="cannibale-resume" role="tabpanel">
                                        <div id="tableauCannibaleResume"></div>
                                    </div>

                                    <!-- Détail -->
                                    <div class="tab-pane fade" id="cannibale-detail" role="tabpanel">
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <label for="selectAncre" class="form-label">Sélectionner une ancre</label>
                                                <select class="form-select" id="selectAncre">
                                                    <option value="">— Choisir —</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6 d-flex align-items-end">
                                                <button class="btn btn-outline-secondary btn-sm" id="btnExportDetail">
                                                    <i class="bi bi-download me-1"></i> Exporter CSV
                                                </button>
                                            </div>
                                        </div>
                                        <div id="contenuDetail"></div>
                                    </div>

                                    <!-- Actions -->
                                    <div class="tab-pane fade" id="cannibale-actions" role="tabpanel">
                                        <div id="tableauCannibaleActions"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Onglet GSC Insights (conditionnel) -->
                    <div class="tab-pane fade" id="contenu-gsc" role="tabpanel">
                        <div id="chargementGsc" class="text-center py-4 d-none">
                            <div class="spinner-border text-primary" role="status"></div>
                            <p class="text-muted mt-2">Chargement des données Search Console…</p>
                        </div>
                        <div id="resultatsGsc"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="app.js"></script>
</body>
</html>
