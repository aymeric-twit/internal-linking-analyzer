/* ══════════════════════════════════════════════════════════
   Internal Linking Analyzer — Application JavaScript v3
   ══════════════════════════════════════════════════════════ */

const baseUrl = window.MODULE_BASE_URL || '.';

// ── i18n ─────────────────────────────────────────────────

var langueActuelle = (function () {
    if (typeof window.PLATFORM_LANG === 'string' && window.PLATFORM_LANG) return window.PLATFORM_LANG;
    try { var p = new URLSearchParams(window.location.search).get('lg'); if (p) return p; } catch (_) {}
    try { var s = localStorage.getItem('lang'); if (s) return s; } catch (_) {}
    return 'fr';
})();

function t(cle, params) {
    var trad = (typeof TRANSLATIONS !== 'undefined' && TRANSLATIONS[langueActuelle] && TRANSLATIONS[langueActuelle][cle])
        ? TRANSLATIONS[langueActuelle][cle]
        : (typeof TRANSLATIONS !== 'undefined' && TRANSLATIONS.fr && TRANSLATIONS.fr[cle])
            ? TRANSLATIONS.fr[cle]
            : cle;
    if (params) {
        Object.keys(params).forEach(function (k) {
            trad = trad.replace(new RegExp('\\{' + k + '\\}', 'g'), params[k]);
        });
    }
    return trad;
}

function traduirePage() {
    document.querySelectorAll('[data-i18n]').forEach(function (el) {
        el.innerHTML = t(el.getAttribute('data-i18n'));
    });
    document.querySelectorAll('[data-i18n-placeholder]').forEach(function (el) {
        el.placeholder = t(el.getAttribute('data-i18n-placeholder'));
    });
    document.querySelectorAll('[data-i18n-title]').forEach(function (el) {
        el.title = t(el.getAttribute('data-i18n-title'));
    });
}

function initLangueSelect() {
    var sel = document.getElementById('lang-select');
    if (sel) {
        sel.value = langueActuelle;
        sel.addEventListener('change', function () {
            changerLangue(this.value);
        });
    }
    window.addEventListener('platform:lang', function (e) {
        if (e.detail && e.detail.lang) changerLangue(e.detail.lang);
    });
}

function changerLangue(lg) {
    langueActuelle = lg;
    try { localStorage.setItem('lang', lg); } catch (_) {}
    traduirePage();
}

// ── État global ──────────────────────────────────────────
const etat = {
    jobId: null,
    fichierLiens: null,
    fichierAncres: null,
    entetesCSV: [],
    pollingTimer: null,
    resultats: null,
    triColonne: null,
    triDirection: 'asc',
    tailleChunk: 1400000,
    cacheAnalyses: {},
    importSelectionne: null,
    gscDisponible: false,
    gscSites: [],
    filtreSection: null,
};

// ══════════════════════════════════════════════════════════
//  UTILITAIRES
// ══════════════════════════════════════════════════════════

function getCsrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) return meta.getAttribute('content');
    var input = document.querySelector('input[name="_csrf_token"]');
    return input ? input.value : '';
}

function formaterNombre(n) {
    return Number(n).toLocaleString(langueActuelle === 'en' ? 'en-US' : 'fr-FR');
}

function formaterTaille(octets) {
    if (octets < 1024) return octets + ' o';
    if (octets < 1048576) return (octets / 1024).toFixed(1) + ' Ko';
    if (octets < 1073741824) return (octets / 1048576).toFixed(1) + ' Mo';
    return (octets / 1073741824).toFixed(2) + ' Go';
}

function formaterDuree(secondes) {
    if (secondes < 60) return secondes + 's';
    var min = Math.floor(secondes / 60);
    var sec = secondes % 60;
    return min + 'min ' + sec + 's';
}

function afficherStatus(elementId, message, type) {
    var el = document.getElementById(elementId);
    if (!el) return;
    el.textContent = message;
    el.className = 'status-msg status-' + type;
    el.classList.remove('d-none');
}

function masquerSection(id) {
    var el = document.getElementById(id);
    if (el) el.classList.add('d-none');
}

function afficherSection(id) {
    var el = document.getElementById(id);
    if (el) el.classList.remove('d-none');
}

function parserCsvLignes(texte, separateur, nbLignes) {
    var lignes = [];
    var courant = '';
    var dansQuotes = false;
    var compteur = 0;

    for (var i = 0; i < texte.length; i++) {
        var c = texte[i];
        if (c === '"') {
            if (dansQuotes && texte[i + 1] === '"') {
                courant += '"';
                i++;
            } else {
                dansQuotes = !dansQuotes;
            }
        } else if ((c === '\n' || c === '\r') && !dansQuotes) {
            if (c === '\r' && texte[i + 1] === '\n') i++;
            if (courant.length > 0 || lignes.length > 0) {
                lignes.push(courant.split(dansQuotes ? '§IMPOSSIBLE§' : separateur));
                courant = '';
                compteur++;
                if (compteur >= nbLignes) break;
            }
        } else {
            courant += c;
        }
    }
    if (courant.length > 0 && compteur < nbLignes) {
        lignes.push(courant.split(separateur));
    }
    return lignes.map(function(l) {
        if (Array.isArray(l)) return l.map(function(c) { return c.replace(/^"|"$/g, '').trim(); });
        return [l];
    });
}

function lirePremieresLignes(fichier, separateur, nbLignes, callback) {
    var tranche = fichier.slice(0, 51200);
    var lecteur = new FileReader();
    lecteur.onload = function(e) {
        var texte = e.target.result;
        if (texte.charCodeAt(0) === 0xFEFF) texte = texte.substring(1);
        var lignes = parserCsvLignes(texte, separateur, nbLignes);
        callback(lignes);
    };
    lecteur.readAsText(tranche, 'UTF-8');
}

function escapeHtml(texte) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(texte || ''));
    return div.innerHTML;
}

function tronquerUrl(url) {
    try {
        var u = new URL(url);
        var chemin = u.pathname;
        if (chemin.length > 50) {
            chemin = chemin.substring(0, 47) + '…';
        }
        return chemin;
    } catch (e) {
        return url.length > 60 ? url.substring(0, 57) + '…' : url;
    }
}

function construireKpi(valeur, label, sub, classe) {
    return '<div class="kpi-card ' + classe + '">' +
        '<div class="kpi-value">' + valeur + '</div>' +
        '<div class="kpi-label">' + label + '</div>' +
        (sub ? '<div class="kpi-sub">' + sub + '</div>' : '') +
        '</div>';
}

function rendreUrlCopiable(url) {
    return '<span class="url-copiable" title="' + escapeHtml(url) + '">' +
        '<a href="' + escapeHtml(url) + '" target="_blank" rel="noopener">' + tronquerUrl(url) + '</a>' +
        '<button class="btn-copier" onclick="event.stopPropagation();navigator.clipboard.writeText(\'' + escapeHtml(url).replace(/'/g, "\\'") + '\')" title="' + t('btn.copier') + '"><i class="bi bi-clipboard"></i></button>' +
        '</span>';
}

function badgeType(type) {
    var couleurs = {
        'vide': 'background:#94a3b8;color:#fff;',
        'generique': 'background:#fee2e2;color:#991b1b;',
        'url_nue': 'background:#fef3c7;color:#92400e;',
        'mot_cle': 'background:#fef4e0;color:#92400e;',
        'descriptif': 'background:#d1fae5;color:#065f46;',
    };
    return '<span style="font-size:0.7rem;padding:0.2em 0.5em;border-radius:3px;font-weight:600;' + (couleurs[type] || '') + '">' + type + '</span>';
}

function classeScoreSante(score) {
    if (score >= 70) return 'bon';
    if (score >= 40) return 'moyen';
    return 'mauvais';
}

function fetchJson(url, options, etape) {
    return fetch(url, options)
        .catch(function() {
            throw new Error('[' + etape + '] ' + t('error.connexion') + ' (' + url + ')');
        })
        .then(function(response) {
            var contentType = response.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                throw new Error(
                    '[' + etape + '] ' + t('error.html_json', {status: response.status})
                );
            }
            return response.json().then(function(data) {
                if (data.erreur) {
                    throw new Error('[' + etape + '] ' + data.erreur);
                }
                return data;
            });
        });
}

// ══════════════════════════════════════════════════════════
//  TABLEAU PAGINÉ RÉUTILISABLE
// ══════════════════════════════════════════════════════════

/**
 * Crée un tableau paginé avec filtre, tri et export CSV.
 *
 * @param {Object} options
 * @param {HTMLElement} options.conteneur - Élément DOM cible
 * @param {Array} options.colonnes - [{cle, label, tri?, render?}]
 * @param {Array} options.donnees - Tableau de données
 * @param {number} [options.parPage=50] - Lignes par page
 * @param {string} [options.placeholder] - Placeholder du filtre
 * @param {Function} [options.filtrer] - Fonction filtre(item, terme)
 * @param {string} [options.exportNom] - Nom du fichier CSV exporté
 * @param {Function} [options.onClic] - Callback au clic sur une ligne
 * @returns {Object} {rafraichir(donnees), page, donneesFiltrees}
 */
function creerTableauPagine(options) {
    var conteneur = options.conteneur;
    var colonnes = options.colonnes;
    var parPage = options.parPage || 50;
    var donnees = options.donnees || [];
    var placeholder = options.placeholder || t('table.filtrer');
    var filtrer = options.filtrer || null;
    var exportNom = options.exportNom || 'export.csv';
    var onClic = options.onClic || null;
    var regexToggle = options.regexToggle || false;

    var page = 0;
    var triCol = null;
    var triDir = 'asc';
    var terme = '';
    var modeRegex = false;
    var donneesFiltrees = donnees;
    var timerFiltre = null;

    // Construire le HTML
    conteneur.innerHTML = '';

    // Barre d'outils
    var barre = document.createElement('div');
    barre.className = 'd-flex justify-content-between align-items-center mb-3';

    var inputFiltre = document.createElement('input');
    inputFiltre.type = 'text';
    inputFiltre.className = 'form-control form-control-sm';
    inputFiltre.placeholder = placeholder;
    inputFiltre.style.width = '280px';

    if (regexToggle) {
        var groupeFiltre = document.createElement('div');
        groupeFiltre.className = 'input-group input-group-sm';
        groupeFiltre.style.width = '320px';
        groupeFiltre.appendChild(inputFiltre);
        var btnRegex = document.createElement('button');
        btnRegex.className = 'btn btn-outline-secondary btn-regex';
        btnRegex.type = 'button';
        btnRegex.textContent = '.*';
        btnRegex.title = t('table.regex_activer');
        btnRegex.addEventListener('click', function() {
            modeRegex = !modeRegex;
            btnRegex.classList.toggle('active', modeRegex);
            btnRegex.title = modeRegex ? t('table.regex_desactiver') : t('table.regex_activer');
            inputFiltre.placeholder = modeRegex ? t('table.regex_placeholder') : placeholder;
            inputFiltre.dispatchEvent(new Event('input'));
        });
        groupeFiltre.appendChild(btnRegex);
        barre.appendChild(groupeFiltre);
    } else {
        barre.appendChild(inputFiltre);
    }

    var droite = document.createElement('div');
    droite.className = 'd-flex align-items-center gap-2';

    var infoPagination = document.createElement('small');
    infoPagination.className = 'text-muted';
    droite.appendChild(infoPagination);

    if (exportNom) {
        var btnExport = document.createElement('button');
        btnExport.className = 'btn btn-outline-secondary btn-sm';
        btnExport.innerHTML = '<i class="bi bi-download me-1"></i>CSV';
        btnExport.addEventListener('click', function() { exporterCsv(); });
        droite.appendChild(btnExport);
    }

    barre.appendChild(droite);
    conteneur.appendChild(barre);

    // Tableau
    var tableResp = document.createElement('div');
    tableResp.className = 'table-responsive';
    var table = document.createElement('table');
    table.className = 'table';
    var thead = document.createElement('thead');
    var trHead = document.createElement('tr');

    colonnes.forEach(function(col) {
        var th = document.createElement('th');
        th.textContent = col.label;
        if (col.tri) {
            th.className = 'sortable';
            th.setAttribute('data-col', col.cle);
            th.addEventListener('click', function() {
                if (triCol === col.cle) {
                    triDir = triDir === 'asc' ? 'desc' : 'asc';
                } else {
                    triCol = col.cle;
                    triDir = 'asc';
                }
                majTriVisuel();
                trier();
                rendrePage();
            });
        }
        trHead.appendChild(th);
    });
    thead.appendChild(trHead);
    table.appendChild(thead);

    var tbody = document.createElement('tbody');
    table.appendChild(tbody);
    tableResp.appendChild(table);
    conteneur.appendChild(tableResp);

    // Pagination
    var navPag = document.createElement('nav');
    navPag.className = 'd-flex justify-content-between align-items-center mt-3';
    var btnPrev = document.createElement('button');
    btnPrev.className = 'btn btn-sm btn-outline-secondary';
    btnPrev.innerHTML = t('btn.precedent');
    var btnNext = document.createElement('button');
    btnNext.className = 'btn btn-sm btn-outline-secondary';
    btnNext.innerHTML = t('btn.suivant');
    var labelPage = document.createElement('small');
    labelPage.className = 'text-muted';

    btnPrev.addEventListener('click', function() {
        if (page > 0) { page--; rendrePage(); }
    });
    btnNext.addEventListener('click', function() {
        var maxPage = Math.ceil(donneesFiltrees.length / parPage) - 1;
        if (page < maxPage) { page++; rendrePage(); }
    });

    navPag.appendChild(btnPrev);
    navPag.appendChild(labelPage);
    navPag.appendChild(btnNext);
    conteneur.appendChild(navPag);

    // Événement filtre avec debounce
    inputFiltre.addEventListener('input', function() {
        clearTimeout(timerFiltre);
        var val = inputFiltre.value;
        timerFiltre = setTimeout(function() {
            terme = val.toLowerCase().trim();
            page = 0;
            appliquerFiltre();
            rendrePage();
        }, 300);
    });

    function appliquerFiltre() {
        if (!terme || !filtrer) {
            donneesFiltrees = donnees;
            inputFiltre.classList.remove('is-invalid');
        } else if (modeRegex) {
            try {
                var re = new RegExp(terme, 'i');
                inputFiltre.classList.remove('is-invalid');
                donneesFiltrees = donnees.filter(function(item) {
                    return filtrer(item, terme, true);
                });
            } catch (e) {
                inputFiltre.classList.add('is-invalid');
                donneesFiltrees = donnees;
            }
        } else {
            inputFiltre.classList.remove('is-invalid');
            donneesFiltrees = donnees.filter(function(item) {
                return filtrer(item, terme, false);
            });
        }
        trier();
    }

    function trier() {
        if (!triCol) return;
        donneesFiltrees = donneesFiltrees.slice().sort(function(a, b) {
            var va = a[triCol];
            var vb = b[triCol];
            if (typeof va === 'string') va = va.toLowerCase();
            if (typeof vb === 'string') vb = vb.toLowerCase();
            if (va < vb) return triDir === 'asc' ? -1 : 1;
            if (va > vb) return triDir === 'asc' ? 1 : -1;
            return 0;
        });
    }

    function majTriVisuel() {
        trHead.querySelectorAll('.sortable').forEach(function(th) {
            th.classList.remove('sort-asc', 'sort-desc');
            if (th.getAttribute('data-col') === triCol) {
                th.classList.add('sort-' + triDir);
            }
        });
    }

    function rendrePage() {
        var debut = page * parPage;
        var fin = Math.min(debut + parPage, donneesFiltrees.length);
        var nbPages = Math.max(1, Math.ceil(donneesFiltrees.length / parPage));

        tbody.innerHTML = '';

        if (donneesFiltrees.length === 0) {
            var tr = document.createElement('tr');
            var td = document.createElement('td');
            td.colSpan = colonnes.length;
            td.className = 'text-center text-muted py-4';
            td.textContent = t('table.aucun_resultat');
            tr.appendChild(td);
            tbody.appendChild(tr);
        } else {
            for (var i = debut; i < fin; i++) {
                var item = donneesFiltrees[i];
                var tr = document.createElement('tr');
                if (onClic) {
                    tr.style.cursor = 'pointer';
                    (function(it) {
                        tr.addEventListener('click', function() { onClic(it); });
                    })(item);
                }
                colonnes.forEach(function(col) {
                    var td = document.createElement('td');
                    if (col.render) {
                        td.innerHTML = col.render(item);
                    } else {
                        td.textContent = item[col.cle] != null ? String(item[col.cle]) : '';
                    }
                    tr.appendChild(td);
                });
                tbody.appendChild(tr);
            }
        }

        infoPagination.textContent = t('table.resultats', {n: donneesFiltrees.length, s: donneesFiltrees.length > 1 ? 's' : ''});
        labelPage.textContent = t('table.page', {current: page + 1, total: nbPages});
        btnPrev.disabled = page <= 0;
        btnNext.disabled = page >= nbPages - 1;

        // Masquer la pagination si inutile
        navPag.style.display = donneesFiltrees.length <= parPage ? 'none' : '';
    }

    function exporterCsv() {
        var sep = ';';
        var bom = '\uFEFF';
        var lignes = [colonnes.map(function(c) { return c.label; }).join(sep)];
        donneesFiltrees.forEach(function(item) {
            var vals = colonnes.map(function(c) {
                var v = item[c.cle] != null ? String(item[c.cle]) : '';
                if (v.includes(sep) || v.includes('"') || v.includes('\n')) {
                    v = '"' + v.replace(/"/g, '""') + '"';
                }
                return v;
            });
            lignes.push(vals.join(sep));
        });
        var blob = new Blob([bom + lignes.join('\n')], {type: 'text/csv;charset=utf-8;'});
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = exportNom;
        a.click();
        URL.revokeObjectURL(url);
    }

    function rafraichir(nouvellesDonnees) {
        donnees = nouvellesDonnees;
        page = 0;
        appliquerFiltre();
        rendrePage();
    }

    // Rendu initial
    appliquerFiltre();
    rendrePage();

    return { rafraichir: rafraichir, donneesFiltrees: donneesFiltrees };
}

// ══════════════════════════════════════════════════════════
//  ÉTAPE 1 — UPLOAD FICHIER LIENS
// ══════════════════════════════════════════════════════════

function initialiserDropZoneLiens() {
    var zone = document.getElementById('dropZoneLiens');
    var input = document.getElementById('inputFichierLiens');

    zone.addEventListener('click', function() { input.click(); });

    zone.addEventListener('dragover', function(e) {
        e.preventDefault();
        zone.classList.add('dragover');
    });
    zone.addEventListener('dragleave', function() {
        zone.classList.remove('dragover');
    });
    zone.addEventListener('drop', function(e) {
        e.preventDefault();
        zone.classList.remove('dragover');
        if (e.dataTransfer.files.length > 0) {
            selectionnerFichierLiens(e.dataTransfer.files[0]);
        }
    });

    input.addEventListener('change', function() {
        if (input.files.length > 0) {
            selectionnerFichierLiens(input.files[0]);
        }
    });

    document.getElementById('cheminServeur').addEventListener('input', function() {
        var chemin = this.value.trim();
        if (chemin !== '') {
            etat.fichierLiens = null;
            document.getElementById('infoFichierLiens').classList.add('d-none');
            afficherMappingDefaut();
            document.getElementById('btnImporter').disabled = false;
        } else if (!etat.fichierLiens) {
            document.getElementById('btnImporter').disabled = true;
            document.getElementById('sectionMapping').classList.add('d-none');
        }
    });

    // Toggle visibilité du champ valeur filtre
    document.getElementById('colFiltre').addEventListener('change', function() {
        var zone = document.getElementById('zoneValeurFiltre');
        if (this.value !== '') {
            zone.classList.remove('d-none');
        } else {
            zone.classList.add('d-none');
            document.getElementById('valeurFiltre').value = '';
        }
    });
}

function selectionnerFichierLiens(fichier) {
    var ext = fichier.name.split('.').pop().toLowerCase();
    if (ext !== 'csv' && ext !== 'txt') {
        alert(t('upload.erreur_format'));
        return;
    }

    etat.fichierLiens = fichier;
    document.getElementById('cheminServeur').value = '';

    var info = document.getElementById('infoFichierLiens');
    info.innerHTML = '<i class="bi bi-file-earmark-check me-1"></i> ' +
        '<strong>' + fichier.name + '</strong> — ' + formaterTaille(fichier.size);
    info.classList.remove('d-none');

    lirePremieresLignes(fichier, ',', 6, function(lignes) {
        if (lignes.length === 0) return;
        afficherMappingColonnes(lignes);
        document.getElementById('btnImporter').disabled = false;
    });
}

function afficherMappingDefaut() {
    var sectionMapping = document.getElementById('sectionMapping');
    sectionMapping.classList.remove('d-none');
    var entetes = ['Col 0 (Source)', 'Col 1', 'Col 2 (Destination)', 'Col 3', 'Col 4', 'Col 5 (Ancre)'];
    remplirSelectsMapping(entetes, 0, 2, 5);
    document.getElementById('apercuCsv').innerHTML = '';
}

function afficherMappingColonnes(lignes) {
    var sectionMapping = document.getElementById('sectionMapping');
    sectionMapping.classList.remove('d-none');

    var entetes = lignes[0];
    etat.entetesCSV = entetes;

    var html = '<div class="apercu-csv"><table class="table table-sm"><thead><tr>';
    entetes.forEach(function(h, i) {
        html += '<th>Col ' + i + ' : ' + escapeHtml(h) + '</th>';
    });
    html += '</tr></thead><tbody>';

    for (var i = 1; i < Math.min(lignes.length, 6); i++) {
        html += '<tr>';
        lignes[i].forEach(function(cell) {
            html += '<td>' + escapeHtml(cell) + '</td>';
        });
        html += '</tr>';
    }
    html += '</tbody></table></div>';
    document.getElementById('apercuCsv').innerHTML = html;

    var colSourceDef = entetes.length > 0 ? 0 : 0;
    var colDestDef = entetes.length > 2 ? 2 : 0;
    var colAncreDef = entetes.length > 5 ? 5 : 0;

    remplirSelectsMapping(entetes.map(function(h, i) {
        return 'Col ' + i + ' : ' + h;
    }), colSourceDef, colDestDef, colAncreDef);
}

function remplirSelectsMapping(options, defSource, defDest, defAncre) {
    ['colSource', 'colDestination', 'colAncre'].forEach(function(id) {
        var select = document.getElementById(id);
        select.innerHTML = '';
        options.forEach(function(opt, i) {
            var o = document.createElement('option');
            o.value = i;
            o.textContent = opt;
            select.appendChild(o);
        });
    });

    document.getElementById('colSource').value = defSource;
    document.getElementById('colDestination').value = defDest;
    document.getElementById('colAncre').value = defAncre;

    // Populate filtre column selector
    var selectFiltre = document.getElementById('colFiltre');
    selectFiltre.innerHTML = '<option value="">' + t('mapping.aucun_filtre') + '</option>';
    options.forEach(function(opt, i) {
        var o = document.createElement('option');
        o.value = i;
        o.textContent = opt;
        selectFiltre.appendChild(o);
    });
}

// ══════════════════════════════════════════════════════════
//  LANCEMENT IMPORT
// ══════════════════════════════════════════════════════════

function lancerImport() {
    var cheminServeur = document.getElementById('cheminServeur').value.trim();

    if (cheminServeur !== '') {
        lancerImportCheminServeur(cheminServeur);
    } else if (etat.fichierLiens) {
        lancerImportChunked(etat.fichierLiens);
    } else {
        alert(t('upload.erreur_vide'));
        return;
    }
}

function lancerImportCheminServeur(cheminServeur) {
    document.getElementById('btnImporter').disabled = true;
    afficherSection('sectionProgression');

    var formData = new FormData();
    formData.append('chemin_serveur', cheminServeur);
    formData.append('col_source', document.getElementById('colSource').value);
    formData.append('col_destination', document.getElementById('colDestination').value);
    formData.append('col_ancre', document.getElementById('colAncre').value);
    formData.append('col_filtre', document.getElementById('colFiltre').value);
    formData.append('valeur_filtre', document.getElementById('valeurFiltre').value);

    var csrfToken = getCsrfToken();
    if (csrfToken) formData.append('_csrf_token', csrfToken);

    fetchJson(baseUrl + '/process.php', { method: 'POST', body: formData }, 'Import chemin serveur')
        .then(function(data) {
            etat.jobId = data.jobId;
            demarrerPolling();
        })
        .catch(function(err) {
            afficherStatus('statusImport', err.message, 'error');
            document.getElementById('btnImporter').disabled = false;
        });
}

function lancerImportChunked(fichier) {
    document.getElementById('btnImporter').disabled = true;
    afficherSection('sectionProgression');
    afficherSection('barreUpload');
    mettreAJourBarreUpload(0, t('progress.detection_limites'));

    var csrfToken = getCsrfToken();

    fetch(baseUrl + '/server_info.php')
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function(info) {
            if (info.taille_chunk && info.taille_chunk > 0) {
                etat.tailleChunk = info.taille_chunk;
            }
        })
        .catch(function() {})
        .then(function() {
            return demarrerUploadChunked(fichier, csrfToken);
        })
        .catch(function(err) {
            masquerSection('barreUpload');
            afficherStatus('statusImport', err.message, 'error');
            document.getElementById('btnImporter').disabled = false;
        });
}

function demarrerUploadChunked(fichier, csrfToken) {
    var tailleChunk = etat.tailleChunk;
    var nbChunks = Math.ceil(fichier.size / tailleChunk);

    mettreAJourBarreUpload(0,
        t('progress.envoi_morceaux', {nb: nbChunks, taille: formaterTaille(tailleChunk)})
    );

    var formInit = new FormData();
    formInit.append('action', 'init');
    formInit.append('nom_fichier', fichier.name);
    formInit.append('taille_totale', fichier.size);
    formInit.append('nb_chunks', nbChunks);
    if (csrfToken) formInit.append('_csrf_token', csrfToken);

    return fetchJson(baseUrl + '/upload_chunk.php', { method: 'POST', body: formInit }, 'Initialisation')
        .then(function(data) {
            etat.jobId = data.jobId;
            return envoyerChunks(fichier, data.jobId, nbChunks, csrfToken);
        })
        .then(function() {
            mettreAJourBarreUpload(100, t('progress.assemblage'));
            return assemblerEtLancer(etat.jobId, csrfToken);
        })
        .then(function() {
            masquerSection('barreUpload');
            demarrerPolling();
        });
}

function envoyerChunks(fichier, jobId, nbChunks, csrfToken) {
    var indexCourant = 0;
    var tailleChunk = etat.tailleChunk;

    function envoyerSuivant() {
        if (indexCourant >= nbChunks) return Promise.resolve();

        var debut = indexCourant * tailleChunk;
        var fin = Math.min(debut + tailleChunk, fichier.size);
        var tranche = fichier.slice(debut, fin);

        var formData = new FormData();
        formData.append('action', 'chunk');
        formData.append('jobId', jobId);
        formData.append('index_chunk', indexCourant);
        formData.append('chunk', tranche, 'chunk_' + indexCourant);
        if (csrfToken) formData.append('_csrf_token', csrfToken);

        var numChunk = indexCourant + 1;
        var pct = Math.round((numChunk / nbChunks) * 100);
        var envoye = Math.min(fin, fichier.size);
        mettreAJourBarreUpload(pct,
            formaterTaille(envoye) + ' / ' + formaterTaille(fichier.size) +
            ' — ' + t('progress.morceau') + ' ' + numChunk + '/' + nbChunks
        );

        var tentatives = 0;
        var maxTentatives = 3;
        var indexActuel = indexCourant;
        indexCourant++;

        function essayer() {
            return fetchJson(
                baseUrl + '/upload_chunk.php',
                { method: 'POST', body: formData },
                'Morceau ' + numChunk + '/' + nbChunks
            )
            .then(function() { return envoyerSuivant(); })
            .catch(function(err) {
                tentatives++;
                if (tentatives < maxTentatives) {
                    mettreAJourBarreUpload(pct,
                        t('progress.retry', {num: numChunk, tentative: tentatives + 1, max: maxTentatives})
                    );
                    return new Promise(function(resolve) {
                        setTimeout(resolve, 2000);
                    }).then(function() {
                        formData = new FormData();
                        formData.append('action', 'chunk');
                        formData.append('jobId', jobId);
                        formData.append('index_chunk', indexActuel);
                        formData.append('chunk', fichier.slice(debut, fin), 'chunk_' + indexActuel);
                        if (csrfToken) formData.append('_csrf_token', csrfToken);
                        return essayer();
                    });
                }
                throw err;
            });
        }

        return essayer();
    }

    return envoyerSuivant();
}

function assemblerEtLancer(jobId, csrfToken) {
    var formData = new FormData();
    formData.append('action', 'assemble');
    formData.append('jobId', jobId);
    formData.append('col_source', document.getElementById('colSource').value);
    formData.append('col_destination', document.getElementById('colDestination').value);
    formData.append('col_ancre', document.getElementById('colAncre').value);
    formData.append('col_filtre', document.getElementById('colFiltre').value);
    formData.append('valeur_filtre', document.getElementById('valeurFiltre').value);
    if (csrfToken) formData.append('_csrf_token', csrfToken);

    return fetchJson(baseUrl + '/upload_chunk.php', { method: 'POST', body: formData }, 'Assemblage');
}

function mettreAJourBarreUpload(pct, texte) {
    document.getElementById('progressUpload').style.width = pct + '%';
    document.getElementById('labelUpload').textContent = pct + '% — ' + texte;
}

// ══════════════════════════════════════════════════════════
//  POLLING PROGRESSION IMPORT
// ══════════════════════════════════════════════════════════

function demarrerPolling() {
    etat.pollingTimer = setInterval(function() {
        fetch(baseUrl + '/progress.php?jobId=' + encodeURIComponent(etat.jobId))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                mettreAJourProgression(data);

                if (data.statut === 'import_termine') {
                    clearInterval(etat.pollingTimer);
                    etat.pollingTimer = null;

                    // Auto-collapse config
                    var configBody = document.getElementById('configBody');
                    if (configBody) { bootstrap.Collapse.getOrCreateInstance(configBody, {toggle:false}).hide(); }

                    afficherImportTermine(data);
                } else if (data.statut === 'erreur') {
                    clearInterval(etat.pollingTimer);
                    etat.pollingTimer = null;
                    afficherStatus('statusImport', t('error.erreur') + ' ' + (data.message || t('error.inconnue')), 'error');
                }
            })
            .catch(function() {});
    }, 2000);
}

function mettreAJourProgression(data) {
    var pct = data.pourcentage || 0;
    document.getElementById('progressImport').style.width = pct + '%';
    document.getElementById('labelImport').textContent = pct + '%';
    document.getElementById('compteurLignes').textContent = formaterNombre(data.lignes_importees || 0);

    var duree = data.duree_secondes || 0;
    document.getElementById('tempsEcoule').textContent = formaterDuree(duree);

    if (pct > 0 && pct < 100 && duree > 0) {
        var restant = Math.round((duree / pct) * (100 - pct));
        document.getElementById('tempsRestant').textContent = '~' + formaterDuree(restant);
    }

    if (data.phase === 'indexation') {
        afficherStatus('statusImport', t('progress.indexation'), 'loading');
    }
}

function afficherImportTermine(data) {
    masquerSection('sectionProgression');
    masquerSection('sectionUploadLiens');
    masquerSection('sectionGestionImports');

    // Afficher directement les résultats (8 onglets)
    afficherSection('sectionResultats');
    ajouterBoutonRetour();

    // KPI du résumé dans la barre de résultats
    var html = '';
    html += construireKpi(formaterNombre(data.lignes_importees || 0), t('kpi.liens_importes'), '', 'kpi-dark');
    html += construireKpi(formaterNombre(data.ancres_distinctes || 0), t('kpi.ancres_distinctes'), '', 'kpi-gold');
    html += construireKpi(formaterNombre(data.urls_distinctes || 0), t('kpi.urls_distinctes'), '', '');
    html += construireKpi(formaterDuree(data.duree_secondes || 0), t('kpi.duree_import'), '', '');
    document.getElementById('kpiResultats').innerHTML = html;

    // Charger le dashboard (onglet actif)
    chargerAnalyseAvancee('dashboard');

    // Vérifier la disponibilité GSC
    verifierGsc();
}

// ══════════════════════════════════════════════════════════
//  UPLOAD FICHIER ANCRES (dans l'onglet Cannibalisation)
// ══════════════════════════════════════════════════════════

function initialiserDropZoneAncres() {
    var zone = document.getElementById('dropZoneAncres');
    var input = document.getElementById('inputFichierAncres');

    zone.addEventListener('click', function() { input.click(); });

    zone.addEventListener('dragover', function(e) {
        e.preventDefault();
        zone.classList.add('dragover');
    });
    zone.addEventListener('dragleave', function() {
        zone.classList.remove('dragover');
    });
    zone.addEventListener('drop', function(e) {
        e.preventDefault();
        zone.classList.remove('dragover');
        if (e.dataTransfer.files.length > 0) {
            selectionnerFichierAncres(e.dataTransfer.files[0]);
        }
    });

    input.addEventListener('change', function() {
        if (input.files.length > 0) {
            selectionnerFichierAncres(input.files[0]);
        }
    });

    document.getElementById('separateurAncres').addEventListener('change', function() {
        if (etat.fichierAncres) {
            afficherApercuAncres();
        }
    });
}

function selectionnerFichierAncres(fichier) {
    etat.fichierAncres = fichier;

    var info = document.getElementById('infoFichierAncres');
    info.innerHTML = '<i class="bi bi-file-earmark-check me-1"></i> ' +
        '<strong>' + fichier.name + '</strong> — ' + formaterTaille(fichier.size);
    info.classList.remove('d-none');

    afficherApercuAncres();
    document.getElementById('btnAnalyser').disabled = false;
}

function afficherApercuAncres() {
    var sep = document.getElementById('separateurAncres').value;
    if (sep === '\\t') sep = '\t';

    lirePremieresLignes(etat.fichierAncres, sep, 6, function(lignes) {
        if (lignes.length === 0) return;

        var html = '<div class="apercu-csv"><table class="table table-sm"><thead><tr>' +
            '<th>' + t('cannibale.apercu_ancre') + '</th><th>' + t('cannibale.apercu_url') + '</th></tr></thead><tbody>';

        var debut = document.getElementById('avecEntete').checked ? 1 : 0;
        for (var i = debut; i < Math.min(lignes.length, debut + 5); i++) {
            html += '<tr>';
            html += '<td>' + escapeHtml(lignes[i][0] || '') + '</td>';
            html += '<td>' + escapeHtml(lignes[i][1] || '') + '</td>';
            html += '</tr>';
        }
        html += '</tbody></table></div>';

        var apercuEl = document.getElementById('apercuAncres');
        apercuEl.innerHTML = html;
        apercuEl.classList.remove('d-none');
    });
}

// ══════════════════════════════════════════════════════════
//  ANALYSE CANNIBALISATION
// ══════════════════════════════════════════════════════════

function lancerAnalyse() {
    if (!etat.jobId || !etat.fichierAncres) {
        alert(t('cannibale.fichier_ancres_requis'));
        return;
    }

    var formData = new FormData();
    formData.append('jobId', etat.jobId);
    formData.append('fichier_ancres', etat.fichierAncres);

    var sep = document.getElementById('separateurAncres').value;
    if (sep === '\\t') sep = '\t';
    formData.append('separateur', sep);
    formData.append('avec_entete', document.getElementById('avecEntete').checked ? '1' : '0');

    var csrfToken = getCsrfToken();
    if (csrfToken) formData.append('_csrf_token', csrfToken);

    document.getElementById('btnAnalyser').disabled = true;
    afficherSection('sectionProgressionAnalyse');
    masquerSection('uploadCannibaleZone');

    fetch(baseUrl + '/analyse.php', { method: 'POST', body: formData })
    .then(function(r) {
        if (r.status === 429) throw new Error(t('error.quota'));
        return r.json();
    })
    .then(function(data) {
        masquerSection('sectionProgressionAnalyse');
        if (data.erreur) throw new Error(data.erreur);
        chargerResultatsCannibale(data.jobId);
    })
    .catch(function(err) {
        masquerSection('sectionProgressionAnalyse');
        afficherSection('uploadCannibaleZone');
        document.getElementById('btnAnalyser').disabled = false;
        alert(t('error.erreur') + ' ' + err.message);
    });
}

function chargerResultatsCannibale(jobId) {
    fetch(baseUrl + '/results.php?jobId=' + encodeURIComponent(jobId))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.erreur) { alert(t('error.erreur') + ' ' + data.erreur); return; }
            etat.resultats = data;
            afficherResultatsCannibale(data);
        })
        .catch(function(err) {
            alert(t('error.erreur') + ' ' + err.message);
        });
}

function afficherResultatsCannibale(data) {
    masquerSection('uploadCannibaleZone');
    masquerSection('sectionProgressionAnalyse');
    afficherSection('resultatsCannibalisation');

    // KPI Cannibalisation
    var classeScore = data.score_global <= 25 ? 'kpi-green' : (data.score_global <= 50 ? 'kpi-gold' : 'kpi-red');
    var html = '';
    html += construireKpi(formaterNombre(data.nb_couples), t('kpi.ancres_analysees'), '', 'kpi-dark');
    html += construireKpi(formaterNombre(data.nb_cannibalisations), t('kpi.cannibalisees'),
        t('cannibale.sur') + ' ' + data.nb_couples + ' ' + t('tab.ancres').toLowerCase(), 'kpi-red');
    html += construireKpi(data.score_global + '%', t('kpi.score_global'),
        data.severite_globale.label, classeScore);

    var pire = null;
    data.resultats.forEach(function(r) {
        if (!pire || r.ratio > pire.ratio) pire = r;
    });
    if (pire) {
        html += construireKpi(pire.ratio + '%', t('kpi.pire_ancre'), escapeHtml(pire.ancre), 'kpi-gold');
    }
    document.getElementById('kpiCannibale').innerHTML = html;

    // Sous-onglet Résumé : tableau paginé
    creerTableauPagine({
        conteneur: document.getElementById('tableauCannibaleResume'),
        colonnes: [
            { cle: 'ancre', label: t('table.ancre'), tri: true, render: function(r) { return '<strong>' + escapeHtml(r.ancre) + '</strong>'; } },
            { cle: 'url_cible', label: t('table.url_cible'), tri: true, render: function(r) { return '<a href="' + escapeHtml(r.url_cible) + '" target="_blank" rel="noopener">' + tronquerUrl(r.url_cible) + '</a>'; } },
            { cle: 'liens_legitimes', label: t('table.legitimes'), tri: true },
            { cle: 'liens_cannibales', label: t('table.cannibales'), tri: true, render: function(r) { return '<strong>' + formaterNombre(r.liens_cannibales) + '</strong>'; } },
            { cle: 'destinations_parasites', label: t('table.dest_parasites'), tri: true },
            { cle: 'ratio', label: t('table.ratio'), tri: true, render: function(r) { return '<strong>' + r.ratio + '%</strong>'; } },
            { cle: '_severite_ordre', label: t('table.severite'), tri: true, render: function(r) { return '<span class="' + r.severite.classe + '">' + r.severite.label + '</span>'; } },
        ],
        donnees: data.resultats.map(function(r) {
            return Object.assign({}, r, { _severite_ordre: r.severite.ordre });
        }),
        placeholder: t('table.filtrer_ancre_url'),
        filtrer: function(item, terme) {
            return item.ancre.toLowerCase().includes(terme) || item.url_cible.toLowerCase().includes(terme);
        },
        exportNom: 'cannibalisation_resume.csv',
        onClic: function(item) {
            document.getElementById('selectAncre').value = item.ancre_normalisee;
            afficherDetailAncre(item);
            var sousTab = document.querySelector('[data-bs-target="#cannibale-detail"]');
            if (sousTab) new bootstrap.Tab(sousTab).show();
        },
    });

    // Select des ancres (détail)
    remplirSelectAncres(data.resultats);

    // Sous-onglet Actions : tableau paginé
    var actions = [];
    data.resultats.forEach(function(r) {
        (r.detail_sources || []).forEach(function(s) {
            actions.push({
                source: s.source,
                ancre: r.ancre,
                destination: s.destination,
                url_cible: r.url_cible,
                action: t('cannibale.action_modifier'),
            });
        });
    });

    creerTableauPagine({
        conteneur: document.getElementById('tableauCannibaleActions'),
        colonnes: [
            { cle: 'source', label: t('cannibale.page_source'), tri: true, render: function(r) { return '<a href="' + escapeHtml(r.source) + '" target="_blank" rel="noopener">' + tronquerUrl(r.source) + '</a>'; } },
            { cle: 'ancre', label: t('table.ancre'), tri: true, render: function(r) { return '<strong>' + escapeHtml(r.ancre) + '</strong>'; } },
            { cle: 'destination', label: t('cannibale.dest_actuelle'), tri: true, render: function(r) { return '<a href="' + escapeHtml(r.destination) + '" target="_blank" rel="noopener">' + tronquerUrl(r.destination) + '</a>'; } },
            { cle: 'url_cible', label: t('cannibale.dest_souhaitee'), tri: true, render: function(r) { return '<a href="' + escapeHtml(r.url_cible) + '" target="_blank" rel="noopener">' + tronquerUrl(r.url_cible) + '</a>'; } },
            { cle: 'action', label: t('table.action') },
        ],
        donnees: actions,
        placeholder: t('table.filtrer'),
        filtrer: function(item, terme) {
            return item.source.toLowerCase().includes(terme) || item.ancre.toLowerCase().includes(terme);
        },
        exportNom: 'cannibalisation_actions.csv',
    });
}

function lancerCannibaleAuto() {
    if (!etat.jobId) return;
    var btn = document.getElementById('btnCannibaleAuto');
    if (btn) btn.disabled = true;

    var conteneur = document.getElementById('resultatsAutoCannibalisation');
    conteneur.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div> ' + t('cannibale.analyse_en_cours') + '</div>';
    conteneur.classList.remove('d-none');

    fetchJson(baseUrl + '/advanced_analysis.php?importId=' + encodeURIComponent(etat.jobId) + '&type=cannibale_auto', {}, 'Cannibalisation auto')
    .then(function(data) {
        if (btn) btn.disabled = false;
        rendreResultatsAutoCannibalisation(conteneur, data);
    })
    .catch(function(err) {
        if (btn) btn.disabled = false;
        conteneur.innerHTML = '<div class="status-msg status-error">' + err.message + '</div>';
    });
}

function rendreResultatsAutoCannibalisation(el, data) {
    var html = '<div class="kpi-row mb-4">';
    html += construireKpi(formaterNombre(data.nb_ancres_analysees), t('kpi.ancres_analysees'), t('kpi.trois_plus_dest'), 'kpi-dark');
    html += construireKpi(formaterNombre(data.nb_cannibalisees), t('kpi.cannibalisees'), '', data.nb_cannibalisees > 0 ? 'kpi-red' : 'kpi-green');
    html += '</div>';

    if (!data.resultats || data.resultats.length === 0) {
        html += '<div class="status-msg status-success"><i class="bi bi-check-circle me-1"></i>' + t('cannibale.aucune_auto') + '</div>';
        el.innerHTML = html;
        return;
    }

    html += '<div id="autoCannibalTabConteneur"></div>';
    el.innerHTML = html;

    creerTableauPagine({
        conteneur: document.getElementById('autoCannibalTabConteneur'),
        colonnes: [
            {cle:'ancre', label:t('table.ancre'), tri:true, render: function(r) { return '<strong>' + escapeHtml(r.ancre) + '</strong>'; }},
            {cle:'destination_dominante', label:t('cannibale.dest_dominante'), tri:true, render: function(r) { return rendreUrlCopiable(r.destination_dominante); }},
            {cle:'pct_dominant', label:t('cannibale.pct_dominant'), tri:true, render: function(r) { return r.pct_dominant.toFixed(0) + '%'; }},
            {cle:'nb_destinations', label:t('cannibale.dest'), tri:true},
            {cle:'nb_liens_parasites', label:t('cannibale.liens_parasites'), tri:true, render: function(r) { return '<strong class="text-danger">' + r.nb_liens_parasites + '</strong>'; }},
            {cle:'ratio', label:t('table.ratio'), tri:true, render: function(r) { return '<strong>' + r.ratio + '%</strong>'; }},
            {cle:'_sev', label:t('table.severite'), tri:true, render: function(r) { return '<span class="' + r.severite.classe + '">' + r.severite.label + '</span>'; }},
            {cle:'impact', label:'Impact', tri:true, render: function(r) { return '<span class="badge-attention">' + r.impact + ' ' + t('cannibale.impact') + '</span>'; }},
        ],
        donnees: data.resultats.map(function(r) { return Object.assign({}, r, {_sev: r.severite.ordre}); }),
        placeholder: t('table.filtrer_ancre'),
        filtrer: function(item, terme) { return item.ancre.toLowerCase().includes(terme); },
        exportNom: 'cannibalisation_auto.csv',
    });
}

function remplirSelectAncres(resultats) {
    var select = document.getElementById('selectAncre');
    select.innerHTML = '<option value="">' + t('cannibale.choisir_ancre') + '</option>';

    resultats.forEach(function(r) {
        var opt = document.createElement('option');
        opt.value = r.ancre_normalisee;
        opt.textContent = r.ancre + ' (' + r.ratio + '% — ' + r.severite.label + ')';
        select.appendChild(opt);
    });
}

function afficherDetailAncre(resultat) {
    var conteneur = document.getElementById('contenuDetail');

    if (!resultat) {
        conteneur.innerHTML = '<p class="text-muted">' + t('cannibale.detail_vide') + '</p>';
        return;
    }

    var html = '';
    html += '<div class="detail-bloc">';
    html += '<div class="row">';
    html += '<div class="col-md-6">';
    html += '<h6><i class="bi bi-link-45deg me-1"></i>' + t('cannibale.ancre_label') + ' "' + escapeHtml(resultat.ancre) + '"</h6>';
    html += '<p class="url-cible mb-1"><strong>' + t('cannibale.url_cible_attendue') + '</strong> ' +
        '<a href="' + escapeHtml(resultat.url_cible) + '" target="_blank" rel="noopener">' +
        escapeHtml(resultat.url_cible) + '</a></p>';
    html += '</div>';
    html += '<div class="col-md-6 text-md-end">';
    html += '<span class="' + resultat.severite.classe + ' me-2">' + resultat.severite.label + '</span>';
    html += '<strong>' + resultat.ratio + '%</strong> ' + t('cannibale.cannibalisation');
    html += '<br><small class="text-muted">' + formaterNombre(resultat.liens_legitimes) + ' ' + t('cannibale.legitimes') + ' — ' +
        formaterNombre(resultat.liens_cannibales) + ' ' + t('cannibale.cannibales') + '</small>';
    html += '</div></div></div>';

    if (resultat.detail_destinations && resultat.detail_destinations.length > 0) {
        html += '<h6 class="mt-3 mb-2"><i class="bi bi-exclamation-triangle me-1"></i>' + t('cannibale.dest_parasites') + '</h6>';
        html += '<div class="table-responsive"><table class="table table-sm">';
        html += '<thead><tr><th>' + t('cannibale.dest_erronee') + '</th><th>' + t('cannibale.nb_liens') + '</th></tr></thead><tbody>';
        resultat.detail_destinations.forEach(function(d) {
            html += '<tr><td><a href="' + escapeHtml(d.destination) + '" target="_blank" rel="noopener">' +
                escapeHtml(d.destination) + '</a></td>' +
                '<td><strong>' + formaterNombre(d.nb_liens) + '</strong></td></tr>';
        });
        html += '</tbody></table></div>';
    }

    if (resultat.detail_sources && resultat.detail_sources.length > 0) {
        html += '<h6 class="mt-3 mb-2"><i class="bi bi-file-earmark-x me-1"></i>' + t('cannibale.pages_fautives') + '</h6>';
        html += '<div class="table-responsive"><table class="table table-sm">';
        html += '<thead><tr><th>' + t('cannibale.page_source') + '</th><th>' + t('cannibale.dest_erronee') + '</th><th>' + t('cannibale.nb') + '</th></tr></thead><tbody>';
        resultat.detail_sources.forEach(function(s) {
            html += '<tr><td><a href="' + escapeHtml(s.source) + '" target="_blank" rel="noopener">' +
                tronquerUrl(s.source) + '</a></td>' +
                '<td><a href="' + escapeHtml(s.destination) + '" target="_blank" rel="noopener">' +
                tronquerUrl(s.destination) + '</a></td>' +
                '<td>' + s.nb + '</td></tr>';
        });
        html += '</tbody></table></div>';
    }

    if (resultat.liens_cannibales === 0) {
        html += '<div class="status-msg status-success mt-3">' +
            '<i class="bi bi-check-circle me-1"></i>' + t('cannibale.aucune') + '</div>';
    }

    conteneur.innerHTML = html;
}

// ══════════════════════════════════════════════════════════
//  GESTION DES IMPORTS PRÉCÉDENTS
// ══════════════════════════════════════════════════════════

function chargerListeImports() {
    fetch(baseUrl + '/imports.php')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            masquerSection('chargementImports');
            if (!data.imports || data.imports.length === 0) {
                afficherSection('aucunImport');
                afficherSection('sectionUploadLiens');
                return;
            }
            afficherListeImports(data.imports);
        })
        .catch(function() {
            masquerSection('chargementImports');
            afficherSection('aucunImport');
            afficherSection('sectionUploadLiens');
        });
}

function afficherListeImports(imports) {
    var conteneur = document.getElementById('listeImports');
    var html = '<div class="table-responsive"><table class="table table-sm import-table"><thead><tr>' +
        '<th>' + t('imports.date') + '</th><th>' + t('imports.fichier') + '</th><th>' + t('imports.lignes') + '</th><th>' + t('imports.ancres') + '</th><th>' + t('imports.urls') + '</th><th>' + t('imports.statut') + '</th><th>' + t('imports.actions') + '</th>' +
        '</tr></thead><tbody>';

    imports.forEach(function(imp) {
        var date = new Date(imp.date_creation).toLocaleDateString(langueActuelle === 'en' ? 'en-US' : 'fr-FR', {
            day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit'
        });
        var statut = imp.statut === 'pret'
            ? '<span class="badge-succes">' + t('imports.pret') + '</span>'
            : (imp.statut === 'erreur'
                ? '<span class="badge-erreur">' + t('imports.erreur') + '</span>'
                : '<span class="badge-attention">' + t('imports.en_cours') + '</span>');

        var actions = '';
        if (imp.statut === 'pret') {
            actions = '<button class="btn btn-sm btn-utiliser me-1" onclick="utiliserImport(\'' + imp.id + '\')">' + t('imports.utiliser') + '</button>';
        }
        actions += '<button class="btn btn-sm btn-supprimer" onclick="supprimerImport(\'' + imp.id + '\')">' + t('imports.supprimer') + '</button>';

        html += '<tr>' +
            '<td>' + date + '</td>' +
            '<td>' + escapeHtml(imp.nom_fichier) + '</td>' +
            '<td>' + formaterNombre(imp.nb_lignes || 0) + '</td>' +
            '<td>' + formaterNombre(imp.nb_ancres_distinctes || 0) + '</td>' +
            '<td>' + formaterNombre(imp.nb_urls_distinctes || 0) + '</td>' +
            '<td>' + statut + '</td>' +
            '<td>' + actions + '</td>' +
            '</tr>';
    });

    html += '</tbody></table></div>';
    conteneur.innerHTML = html;
}

/**
 * Clic "Utiliser" sur un import → affiche directement les 8 onglets + dashboard.
 */
function utiliserImport(importId) {
    etat.jobId = importId;
    etat.importSelectionne = importId;
    etat.cacheAnalyses = {};

    // Masquer tout et afficher les résultats
    masquerSection('sectionGestionImports');
    masquerSection('sectionUploadLiens');
    masquerSection('sectionProgression');
    afficherSection('sectionResultats');
    ajouterBoutonRetour();

    // Charger les infos de progression pour les KPI
    fetch(baseUrl + '/progress.php?jobId=' + encodeURIComponent(importId))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var html = '';
            html += construireKpi(formaterNombre(data.lignes_importees || 0), t('kpi.liens_importes'), '', 'kpi-dark');
            html += construireKpi(formaterNombre(data.ancres_distinctes || 0), t('kpi.ancres_distinctes'), '', 'kpi-gold');
            html += construireKpi(formaterNombre(data.urls_distinctes || 0), t('kpi.urls_distinctes'), '', '');
            document.getElementById('kpiResultats').innerHTML = html;
        })
        .catch(function() {
            document.getElementById('kpiResultats').innerHTML = construireKpi('—', t('imports.selectionne'), importId.substring(0, 8), 'kpi-dark');
        });

    // Charger le dashboard (onglet actif par défaut)
    chargerAnalyseAvancee('dashboard');

    // Vérifier la disponibilité GSC
    verifierGsc();

    // Charger les résultats cannibalisation si existants
    fetch(baseUrl + '/results.php?jobId=' + encodeURIComponent(importId))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.erreur && data.resultats) {
                etat.resultats = data;
                afficherResultatsCannibale(data);
            }
        })
        .catch(function() {});
}

function ajouterBoutonRetour() {
    if (document.getElementById('btnRetourImports')) return;
    var btn = document.createElement('button');
    btn.id = 'btnRetourImports';
    btn.className = 'btn btn-outline-secondary btn-sm mb-3';
    btn.innerHTML = '<i class="bi bi-arrow-left me-1"></i> ' + t('btn.retour');
    btn.addEventListener('click', function() {
        masquerSection('sectionResultats');
        btn.remove();
        etat.cacheAnalyses = {};
        etat.jobId = null;
        afficherSection('sectionGestionImports');
        chargerListeImports();
    });
    var resultats = document.getElementById('sectionResultats');
    resultats.parentNode.insertBefore(btn, resultats);
}

function supprimerImport(importId) {
    if (!confirm(t('imports.confirmer_suppression'))) return;

    var formData = new FormData();
    formData.append('action', 'supprimer');
    formData.append('id', importId);

    var csrfToken = getCsrfToken();
    if (csrfToken) formData.append('_csrf_token', csrfToken);

    fetch(baseUrl + '/imports.php', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function() { chargerListeImports(); });
}

// ══════════════════════════════════════════════════════════
//  ANALYSES AVANCÉES (lazy loading par onglet)
// ══════════════════════════════════════════════════════════

function initialiserOngletsAvances() {
    document.querySelectorAll('[data-analyse]').forEach(function(btn) {
        btn.addEventListener('shown.bs.tab', function() {
            var type = btn.getAttribute('data-analyse');
            if (etat.cacheAnalyses[type]) return;
            chargerAnalyseAvancee(type);
        });
    });
}

function chargerAnalyseAvancee(type) {
    if (!etat.jobId) return;

    var mapChargement = {
        'dashboard': 'chargementDashboard',
        'orphelins': 'chargementOrphelins',
        'diversite_ancres': 'chargementDiversite',
        'hubs_autorites': 'chargementHubs',
        'distribution_sections': 'chargementDistribution',
        'pagerank': 'chargementPagerank',
        'liste_ancres': 'chargementAncres',
    };

    var mapResultats = {
        'dashboard': 'resultatsDashboard',
        'orphelins': 'resultatsOrphelins',
        'diversite_ancres': 'resultatsDiversite',
        'hubs_autorites': 'resultatsHubs',
        'distribution_sections': 'resultatsDistribution',
        'pagerank': 'resultatsPagerank',
        'liste_ancres': 'resultatsAncres',
    };

    var elChargement = document.getElementById(mapChargement[type]);
    if (elChargement) elChargement.classList.remove('d-none');

    fetchJson(
        baseUrl + '/advanced_analysis.php?importId=' + encodeURIComponent(etat.jobId) + '&type=' + type,
        {},
        'Analyse ' + type
    )
    .then(function(data) {
        if (elChargement) elChargement.classList.add('d-none');
        etat.cacheAnalyses[type] = data;

        var conteneur = document.getElementById(mapResultats[type]);
        if (!conteneur) return;

        switch (type) {
            case 'dashboard':            rendreDashboard(conteneur, data); break;
            case 'orphelins':            rendreOrphelins(conteneur, data); break;
            case 'diversite_ancres':     rendreDiversite(conteneur, data); break;
            case 'hubs_autorites':       rendreHubs(conteneur, data); break;
            case 'distribution_sections': rendreDistribution(conteneur, data); break;
            case 'pagerank':             rendrePageRank(conteneur, data); break;
            case 'liste_ancres':         rendreListeAncres(conteneur, data); break;
        }
    })
    .catch(function(err) {
        if (elChargement) elChargement.classList.add('d-none');
        var conteneur = document.getElementById(mapResultats[type]);
        if (conteneur) conteneur.innerHTML = '<div class="status-msg status-error">' + err.message + '</div>';
    });
}

// ── Renderer : Dashboard ─────────────────────────────────

function rendreDashboard(el, data) {
    var scoreSante = data.score_sante || 0;
    var classeS = classeScoreSante(scoreSante);

    var html = '<p class="memo-onglet"><i class="bi bi-info-circle me-1"></i>' + t('dashboard.memo') + '</p>';
    html += '<div class="d-flex align-items-start gap-4 mb-4">';
    html += '<div class="score-sante score-sante-' + classeS + '"><span class="score-sante-valeur">' + scoreSante + '</span><span class="score-sante-label">/100</span></div>';
    html += '<div class="flex-grow-1"><div class="kpi-row">';
    html += construireKpi(formaterNombre(data.nb_pages), t('kpi.pages'), '', 'kpi-dark');
    html += construireKpi(formaterNombre(data.nb_liens), t('kpi.liens'), '', '');
    var classeRatio = data.ratio_liens_page < 2 ? 'kpi-red' : (data.ratio_liens_page > 30 ? 'kpi-gold' : 'kpi-green');
    html += construireKpi(data.ratio_liens_page, t('kpi.ratio_liens_page'), data.ratio_liens_page < 2 ? t('kpi.sous_maille') : (data.ratio_liens_page > 30 ? t('kpi.suspicion_footer') : t('kpi.bon')), classeRatio);
    html += construireKpi(formaterNombre(data.nb_ancres), t('kpi.ancres_uniques'), '', 'kpi-gold');
    if (data.profondeur) {
        var classeProf = data.profondeur.moyenne <= 3 ? 'kpi-green' : (data.profondeur.moyenne <= 5 ? 'kpi-gold' : 'kpi-red');
        html += construireKpi(data.profondeur.moyenne.toFixed(1), t('kpi.profondeur_moy'), t('kpi.max_clics', {max: data.profondeur.max}), classeProf);
    }
    html += '</div></div></div>';

    html += '<div class="kpi-row mb-4">';
    html += construireKpi(formaterNombre(data.nb_orphelins), t('kpi.orphelines'), '', data.nb_orphelins > 0 ? 'kpi-red' : 'kpi-green');
    html += construireKpi(formaterNombre(data.nb_culs_de_sac || 0), t('kpi.culs_de_sac'), t('kpi.piegent_crawl'), (data.nb_culs_de_sac || 0) > 0 ? 'kpi-gold' : 'kpi-green');
    html += construireKpi(formaterNombre(data.nb_faible_diversite), t('kpi.faible_diversite'), t('kpi.ancres_inf_20'), data.nb_faible_diversite > 0 ? 'kpi-gold' : 'kpi-green');
    html += construireKpi((data.pct_ancres_generiques || 0) + '%', t('kpi.ancres_generiques'), formaterNombre(data.nb_ancres_generiques || 0) + ' ' + t('kpi.liens').toLowerCase(), (data.pct_ancres_generiques || 0) > 30 ? 'kpi-red' : 'kpi-green');
    html += '</div>';

    // Top 5 problèmes
    if (data.top_problemes && data.top_problemes.length > 0) {
        html += '<h6 class="mb-2"><i class="bi bi-exclamation-triangle me-1"></i>' + t('dashboard.problemes_prioritaires') + '</h6>';
        var mapTabs = {orpheline:'tab-orphelins', diversite:'tab-diversite', cul_de_sac:'tab-hubs', generique:'tab-ancres', profondeur:'tab-pagerank'};
        data.top_problemes.forEach(function(p) {
            var badgeSev = p.severite === 'critique' ? 'badge-erreur' : (p.severite === 'elevee' ? 'badge-attention' : 'badge-succes');
            var tabId = mapTabs[p.type] || '';
            html += '<div class="probleme-card probleme-' + p.severite + '" ' + (tabId ? 'onclick="new bootstrap.Tab(document.getElementById(\'' + tabId + '\')).show();" style="cursor:pointer;"' : '') + '>';
            html += '<span class="' + badgeSev + ' me-2">' + p.severite + '</span>' + escapeHtml(p.message);
            html += '</div>';
        });
    }

    // Profondeur distribution
    if (data.profondeur && data.profondeur.distribution) {
        html += '<h6 class="mt-4 mb-2"><i class="bi bi-diagram-2 me-1"></i>' + t('dashboard.distribution_profondeur') + '</h6>';
        html += '<div class="profondeur-distribution">';
        var totalProf = 0;
        Object.values(data.profondeur.distribution).forEach(function(v) { totalProf += v; });
        Object.keys(data.profondeur.distribution).forEach(function(k) {
            var v = data.profondeur.distribution[k];
            var pct = totalProf > 0 ? (v / totalProf * 100) : 0;
            html += '<div class="profondeur-barre"><span class="profondeur-label">' + k + ' ' + (k !== '1' ? t('dashboard.clics') : t('dashboard.clic')) + '</span>';
            html += '<div class="profondeur-track"><div class="profondeur-fill" style="width:' + pct.toFixed(1) + '%;"></div></div>';
            html += '<span class="profondeur-valeur">' + formaterNombre(v) + ' (' + pct.toFixed(0) + '%)</span></div>';
        });
        if (data.profondeur.inaccessibles > 0) {
            html += '<div class="profondeur-barre"><span class="profondeur-label" style="color:var(--score-low);">' + t('dashboard.inaccessibles') + '</span>';
            html += '<div class="profondeur-track"><div class="profondeur-fill" style="width:' + (data.profondeur.inaccessibles / totalProf * 100).toFixed(1) + '%;background:var(--score-low);"></div></div>';
            html += '<span class="profondeur-valeur" style="color:var(--score-low);">' + formaterNombre(data.profondeur.inaccessibles) + '</span></div>';
        }
        html += '</div>';
    }

    // Top 5 pages
    if (data.top5_entrants && data.top5_entrants.length > 0) {
        html += '<h6 class="mt-4 mb-2"><i class="bi bi-trophy me-1"></i>' + t('dashboard.top5') + '</h6>';
        html += '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>#</th><th>' + t('dashboard.url') + '</th><th>' + t('dashboard.liens_entrants') + '</th></tr></thead><tbody>';
        data.top5_entrants.forEach(function(p, i) {
            html += '<tr><td>' + (i + 1) + '</td><td>' + rendreUrlCopiable(p.url) + '</td><td><strong>' + formaterNombre(p.nb_entrants) + '</strong></td></tr>';
        });
        html += '</tbody></table></div>';
    }

    if (data.homepage) {
        html += '<p class="text-muted mt-3" style="font-size:0.8rem;"><i class="bi bi-house me-1"></i>' + t('dashboard.homepage') + ' <strong>' + escapeHtml(data.homepage) + '</strong></p>';
    }

    el.innerHTML = html;
}

// ── Renderer : Pages orphelines ──────────────────────────

function rendreOrphelins(el, data) {
    var html = '<p class="memo-onglet"><i class="bi bi-info-circle me-1"></i>' + t('orphelins.memo') + '</p>';
    html += '<div class="kpi-row mb-4">';
    html += construireKpi(formaterNombre(data.nb_orphelins), t('kpi.orphelines_strictes'), t('kpi.zero_entrant'), 'kpi-red');
    html += construireKpi(formaterNombre(data.nb_quasi_orphelins || 0), t('kpi.quasi_orphelines'), t('kpi.un_deux_entrants'), 'kpi-gold');
    html += construireKpi(formaterNombre(data.nb_pages_total), t('kpi.pages_totales'), '', 'kpi-dark');
    html += construireKpi(data.ratio_orphelins + '%', t('kpi.taux_orphelines'), '', data.ratio_orphelins > 10 ? 'kpi-red' : 'kpi-green');
    html += '</div>';

    // Filtres
    html += '<div class="d-flex gap-2 mb-3 align-items-center">';
    html += '<select id="filtreOrphelinsSection" class="form-select form-select-sm" style="width:200px;"><option value="">' + t('orphelins.toutes_sections') + '</option>';
    (data.sections_disponibles || []).forEach(function(s) { html += '<option value="' + escapeHtml(s) + '">' + escapeHtml(s) + '</option>'; });
    html += '</select>';
    html += '<div class="form-check form-switch ms-3"><input class="form-check-input" type="checkbox" id="toggleQuasiOrphelins"><label class="form-check-label" for="toggleQuasiOrphelins" style="font-size:0.85rem;">' + t('orphelins.afficher_quasi') + '</label></div>';
    html += '</div>';

    html += '<div id="orphelinsTabConteneur"></div>';

    // Suggestions
    if (data.suggestions && data.suggestions.length > 0) {
        html += '<h6 class="mt-4 mb-2"><i class="bi bi-lightbulb me-1"></i>' + t('orphelins.suggestions') + '</h6>';
        data.suggestions.forEach(function(s) {
            html += '<div class="action-card"><span class="action-source">' + t('orphelins.liez') + ' ' + rendreUrlCopiable(s.orpheline) + '</span>';
            html += '<div class="action-detail">' + t('orphelins.depuis') + ' ' + rendreUrlCopiable(s.source_suggeree) + ' (' + s.nb_liens_hub + ' ' + t('orphelins.liens_sortants_section') + ')</div></div>';
        });
    }

    el.innerHTML = html;

    var donneesActuelles = data.orphelins.map(function(o) { return Object.assign({}, o, {section: o.section || ''}); });

    var tableInstance = creerTableauPagine({
        conteneur: document.getElementById('orphelinsTabConteneur'),
        colonnes: [
            {cle:'url', label:t('table.url'), tri:true, render: function(r) { return rendreUrlCopiable(r.url); }},
            {cle:'section', label:t('table.section'), tri:true},
            {cle:'nb_liens_sortants', label:t('table.liens_sortants'), tri:true},
        ],
        donnees: donneesActuelles,
        placeholder: t('table.filtrer_url'),
        filtrer: function(item, terme) { return item.url.toLowerCase().includes(terme); },
        exportNom: 'orphelines.csv',
    });

    document.getElementById('filtreOrphelinsSection').addEventListener('change', function() {
        var sec = this.value;
        var quasiMode = document.getElementById('toggleQuasiOrphelins').checked;
        var source = quasiMode ? (data.quasi_orphelins || []) : data.orphelins;
        var filtre = sec ? source.filter(function(o) { return (o.section || '') === sec; }) : source;
        tableInstance.rafraichir(filtre);
    });

    document.getElementById('toggleQuasiOrphelins').addEventListener('change', function() {
        var quasiMode = this.checked;
        var sec = document.getElementById('filtreOrphelinsSection').value;
        var source = quasiMode ? (data.quasi_orphelins || []) : data.orphelins;
        var filtre = sec ? source.filter(function(o) { return (o.section || '') === sec; }) : source;
        tableInstance.rafraichir(filtre);
    });
}

// ── Renderer : Diversité des ancres ──────────────────────

function rendreDiversite(el, data) {
    var html = '<p class="memo-onglet"><i class="bi bi-info-circle me-1"></i>' + t('diversite.memo') + '</p>';
    html += '<div class="kpi-row mb-4">';
    html += construireKpi(formaterNombre(data.nb_pages_analysees), t('kpi.pages_analysees'), t('kpi.trois_plus_entrants'), 'kpi-dark');
    html += construireKpi(formaterNombre(data.nb_faible_diversite), t('kpi.faible_diversite'), '(indice < 20%)', 'kpi-red');
    html += construireKpi(formaterNombre(data.nb_sur_optimisees || 0), t('kpi.sur_optimisees'), t('kpi.ancre_sup_60'), 'kpi-red');
    html += construireKpi(formaterNombre(data.nb_a_risque || 0), t('kpi.dominante_sup_70'), t('kpi.a_risque'), 'kpi-gold');
    html += '</div>';

    // Dropdown filtre prédéfini
    html += '<div class="d-flex gap-2 mb-3 align-items-center">';
    html += '<select id="filtreDiversitePredefini" class="form-select form-select-sm" style="width:220px;">';
    html += '<option value="">' + t('diversite.toutes_pages') + '</option>';
    html += '<option value="faible">' + t('diversite.faible') + '</option>';
    html += '<option value="suropt">' + t('diversite.suropt') + '</option>';
    html += '<option value="dominante">' + t('diversite.dominante') + '</option>';
    html += '</select></div>';

    var conteneurTab = document.createElement('div');
    el.innerHTML = html;
    el.appendChild(conteneurTab);

    var donneesCompletes = data.pages.map(function(p) {
        return Object.assign({}, p, {_indice: parseFloat(p.indice_diversite), _pct_dom: p.pct_dominante || 0, section: p.section || ''});
    });

    var tableInstance = creerTableauPagine({
        conteneur: conteneurTab,
        colonnes: [
            {cle:'url', label:t('table.url'), tri:true, render: function(r) { return rendreUrlCopiable(r.url); }},
            {cle:'section', label:t('table.section'), tri:true},
            {cle:'total_liens', label:t('table.liens'), tri:true},
            {cle:'ancres_uniques', label:t('table.uniques'), tri:true},
            {cle:'_indice', label:t('table.diversite'), tri:true, render: function(r) {
                var idx = parseFloat(r.indice_diversite);
                var badge = idx < 20 ? 'badge-erreur' : (idx < 50 ? 'badge-attention' : 'badge-succes');
                return '<span class="' + badge + '">' + r.indice_diversite + '%</span>';
            }},
            {cle:'_pct_dom', label:t('table.dominante'), tri:true, render: function(r) {
                var txt = escapeHtml(r.ancre_dominante || '') + ' <strong>' + (r.pct_dominante || 0).toFixed(0) + '%</strong>';
                if (r.risque_sur_optimisation) txt += ' <span class="badge-erreur" style="font-size:0.65rem;">' + t('diversite.sur_opt_badge') + '</span>';
                return '<span style="font-size:0.8rem;">' + txt + '</span>';
            }},
        ],
        donnees: donneesCompletes,
        placeholder: t('table.filtrer_url_ancre'),
        regexToggle: true,
        filtrer: function(item, terme, isRegex) {
            if (isRegex) {
                try { return new RegExp(terme, 'i').test(item.url) || new RegExp(terme, 'i').test(item.ancre_dominante || ''); }
                catch(e) { return true; }
            }
            return item.url.toLowerCase().includes(terme) || (item.ancre_dominante || '').toLowerCase().includes(terme);
        },
        exportNom: 'diversite_ancres.csv',
    });

    // Listener dropdown filtre prédéfini
    document.getElementById('filtreDiversitePredefini').addEventListener('change', function() {
        var f = this.value;
        var donneesFiltrées = f ? donneesCompletes.filter(function(item) {
            if (f === 'faible') return parseFloat(item.indice_diversite) < 20;
            if (f === 'suropt') return item.risque_sur_optimisation === true;
            if (f === 'dominante') return (item.pct_dominante || 0) > 70;
            return true;
        }) : donneesCompletes;
        tableInstance.rafraichir(donneesFiltrées);
    });
}

// ── Renderer : Hubs & Autorités ──────────────────────────

function rendreHubs(el, data) {
    var html = '<p class="memo-onglet"><i class="bi bi-info-circle me-1"></i>' + t('hubs.memo') + '</p>';
    html += '<h6 class="mt-2"><i class="bi bi-box-arrow-up-right me-1"></i>' + t('hubs.top_hubs') + '</h6><div id="hubsTabConteneur"></div>';
    html += '<h6 class="mt-4"><i class="bi bi-box-arrow-in-down me-1"></i>' + t('hubs.top_autorites') + '</h6><div id="autoritesTabConteneur"></div>';
    if (data.fuites_equite && data.fuites_equite.length > 0) {
        html += '<h6 class="mt-4"><i class="bi bi-exclamation-triangle me-1" style="color:var(--brand-gold);"></i>' + t('hubs.fuites_equite') + '</h6><div id="fuitesTabConteneur"></div>';
    }
    if (data.pages_puits && data.pages_puits.length > 0) {
        html += '<h6 class="mt-4"><i class="bi bi-inbox me-1" style="color:var(--score-low);"></i>' + t('hubs.pages_puits') + '</h6>';
        html += '<p class="text-muted mb-2" style="font-size:0.82rem;">' + t('hubs.pages_puits_desc') + '</p>';
        html += '<div id="puitsTabConteneur"></div>';
    }
    el.innerHTML = html;

    creerTableauPagine({
        conteneur: document.getElementById('hubsTabConteneur'),
        colonnes: [
            {cle:'url', label:t('table.url'), tri:true, render: function(r) { return rendreUrlCopiable(r.url); }},
            {cle:'nb_liens', label:t('table.sortants'), tri:true, render: function(r) { return '<strong>' + formaterNombre(r.nb_liens) + '</strong>'; }},
            {cle:'destinations_uniques', label:t('table.dest_uniques'), tri:true},
            {cle:'nb_entrants', label:t('table.entrants'), tri:true},
            {cle:'ratio_in_out', label:t('table.ratio_in_out'), tri:true, render: function(r) {
                var v = r.ratio_in_out || 0;
                var badge = v < 0.3 ? 'badge-erreur' : (v < 1 ? 'badge-attention' : 'badge-succes');
                return '<span class="' + badge + '">' + v.toFixed(2) + '</span>';
            }},
        ],
        donnees: data.hubs, exportNom: 'hubs.csv',
        placeholder: t('table.filtrer'), filtrer: function(item, terme) { return item.url.toLowerCase().includes(terme); },
    });

    creerTableauPagine({
        conteneur: document.getElementById('autoritesTabConteneur'),
        colonnes: [
            {cle:'url', label:t('table.url'), tri:true, render: function(r) { return rendreUrlCopiable(r.url); }},
            {cle:'nb_liens', label:t('table.entrants'), tri:true, render: function(r) { return '<strong>' + formaterNombre(r.nb_liens) + '</strong>'; }},
            {cle:'sources_uniques', label:t('table.sources_uniques'), tri:true},
            {cle:'nb_sortants', label:t('table.sortants'), tri:true},
            {cle:'ratio_in_out', label:t('table.ratio_in_out'), tri:true, render: function(r) {
                var v = r.ratio_in_out || 0;
                return '<strong>' + v.toFixed(2) + '</strong>';
            }},
        ],
        donnees: data.autorites, exportNom: 'autorites.csv',
        placeholder: t('table.filtrer'), filtrer: function(item, terme) { return item.url.toLowerCase().includes(terme); },
    });

    if (data.fuites_equite && data.fuites_equite.length > 0) {
        creerTableauPagine({
            conteneur: document.getElementById('fuitesTabConteneur'),
            colonnes: [
                {cle:'url', label:t('table.url'), tri:true, render: function(r) { return rendreUrlCopiable(r.url); }},
                {cle:'nb_sortants', label:t('table.sortants'), tri:true, render: function(r) { return '<strong>' + r.nb_sortants + '</strong>'; }},
                {cle:'nb_entrants', label:t('table.entrants'), tri:true, render: function(r) { return '<span class="text-danger"><strong>' + r.nb_entrants + '</strong></span>'; }},
                {cle:'section', label:t('table.section'), tri:true},
                {cle:'recommandation', label:t('table.action'), render: function(r) { return '<span style="font-size:0.78rem;font-style:italic;">' + escapeHtml(r.recommandation || '') + '</span>'; }},
            ],
            donnees: data.fuites_equite, exportNom: 'fuites.csv',
        });
    }

    if (data.pages_puits && data.pages_puits.length > 0) {
        creerTableauPagine({
            conteneur: document.getElementById('puitsTabConteneur'),
            colonnes: [
                {cle:'url', label:t('table.url'), tri:true, render: function(r) { return rendreUrlCopiable(r.url); }},
                {cle:'nb_entrants', label:t('table.entrants'), tri:true},
                {cle:'section', label:t('table.section'), tri:true},
            ],
            donnees: data.pages_puits, exportNom: 'pages_puits.csv',
        });
    }
}

// ── Renderer : Distribution par section ──────────────────

function rendreDistribution(el, data) {
    if (!data.sections || data.sections.length === 0) {
        el.innerHTML = '<p class="text-muted">' + t('sections.pas_de_donnees') + '</p>';
        return;
    }

    var html = '<p class="memo-onglet"><i class="bi bi-info-circle me-1"></i>' + t('sections.memo') + '</p>';

    // Alertes îlots
    if (data.ilots && data.ilots.length > 0) {
        html += '<div class="status-msg status-error mb-3"><i class="bi bi-exclamation-triangle me-1"></i><strong>' + t('sections.isolees') + '</strong> ' + t('sections.aucun_lien') + ' ' + data.ilots.map(escapeHtml).join(', ') + '</div>';
    }

    // Calculer le max pour le heatmap
    var maxVal = 1;
    data.sections.forEach(function(src) {
        data.sections.forEach(function(dst) {
            var v = (data.matrice[src] && data.matrice[src][dst]) || 0;
            if (v > maxVal) maxVal = v;
        });
    });

    html += '<h6 class="mb-3">' + t('sections.matrice') + '</h6>';
    html += '<div class="table-responsive"><table class="table table-sm matrice-sections"><thead><tr><th>' + t('sections.source_dest') + '</th>';
    data.sections.forEach(function(s) { html += '<th class="text-center">' + escapeHtml(s) + '</th>'; });
    html += '<th class="text-center">' + t('sections.total') + '</th></tr></thead><tbody>';

    data.sections.forEach(function(src) {
        html += '<tr><th>' + escapeHtml(src) + '</th>';
        var total = 0;
        data.sections.forEach(function(dst) {
            var val = (data.matrice[src] && data.matrice[src][dst]) || 0;
            total += val;
            var intra = src === dst;
            var opacity = maxVal > 0 ? (val / maxVal) : 0;
            var bg = intra ? 'rgba(0,76,76,' + (opacity * 0.4 + 0.05) + ')' : (val > 0 ? 'rgba(102,178,178,' + (opacity * 0.35) + ')' : '');
            var style = bg ? 'background:' + bg + ';' : '';
            var fw = val > maxVal * 0.3 ? 'font-weight:700;' : '';
            html += '<td class="text-center" style="' + style + fw + '">' + (val > 0 ? formaterNombre(val) : '-') + '</td>';
        });
        html += '<td class="text-center"><strong>' + formaterNombre(total) + '</strong></td></tr>';
    });
    html += '</tbody></table></div>';

    // Totaux par section enrichis
    html += '<h6 class="mt-4">' + t('sections.analyse') + '</h6>';
    html += '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>' + t('sections.section') + '</th><th>' + t('sections.pages') + '</th><th>' + t('sections.entrants') + '</th><th>' + t('sections.sortants') + '</th><th>' + t('sections.intra') + '</th><th>' + t('sections.isolation') + '</th><th>' + t('sections.top_flux') + '</th><th>' + t('sections.diagnostic') + '</th></tr></thead><tbody>';
    data.sections.forEach(function(s) {
        var t = data.totaux[s] || {};
        var iso = t.isolation || 0;
        var badge = iso > 90 ? 'badge-erreur' : (iso > 60 ? 'badge-succes' : 'badge-attention');
        var topFlux = (t.top_flux || []).map(function(f) { return escapeHtml(f.section) + ' (' + f.pct + '%)'; }).join(', ');
        html += '<tr><td><strong>' + escapeHtml(s) + '</strong></td><td>' + (t.nb_pages || '—') + '</td><td>' + formaterNombre(t.entrants || 0) + '</td><td>' + formaterNombre(t.sortants || 0) + '</td><td>' + formaterNombre(t.intra || 0) + '</td><td><span class="' + badge + '">' + iso + '%</span></td>';
        html += '<td style="font-size:0.78rem;">' + (topFlux || '—') + '</td>';
        html += '<td style="font-size:0.78rem;">' + escapeHtml(t.diagnostic || '') + '</td></tr>';
    });
    html += '</tbody></table></div>';

    el.innerHTML = html;
}

// ── Renderer : PageRank ──────────────────────────────────

function rendrePageRank(el, data) {
    var html = '<p class="memo-onglet"><i class="bi bi-info-circle me-1"></i>' + t('pagerank.memo') + '</p>';
    html += '<div class="kpi-row mb-4">';
    html += construireKpi(formaterNombre(data.nb_pages), t('kpi.pages_graphe'), '', 'kpi-dark');
    if (data.classement.length > 0) {
        html += construireKpi(data.classement[0].score.toFixed(1), t('kpi.score_num1'), tronquerUrl(data.classement[0].url), 'kpi-gold');
    }
    if (data.distribution) {
        var faibles = data.distribution.find(function(d) { return d.label.includes('Très faible'); });
        if (faibles) html += construireKpi(formaterNombre(faibles.nb_pages), t('kpi.pages_tres_faibles'), t('kpi.score_inf_20'), 'kpi-red');
    }
    html += '</div>';

    html += '<p class="text-muted mb-3" style="font-size:0.8rem;"><i class="bi bi-info-circle me-1"></i>' + t('pagerank.surfeur_info') + '</p>';

    // Alertes PR
    if (data.alertes && data.alertes.length > 0) {
        html += '<div class="mb-4">';
        data.alertes.forEach(function(a) {
            var couleur = a.type === 'autorite_diluee' ? 'var(--score-low)' : 'var(--brand-gold)';
            html += '<div style="border-left:3px solid ' + couleur + ';padding:0.4rem 0.75rem;margin-bottom:0.5rem;font-size:0.82rem;background:var(--bg-card-alt);border-radius:0 4px 4px 0;">';
            html += '<strong>' + tronquerUrl(a.url) + '</strong> — ' + escapeHtml(a.message) + '</div>';
        });
        html += '</div>';
    }

    // Distribution
    if (data.distribution) {
        html += '<h6>' + t('pagerank.distribution') + '</h6><div class="row mb-4">';
        data.distribution.forEach(function(d) {
            var pct = data.nb_pages > 0 ? Math.round((d.nb_pages / data.nb_pages) * 100) : 0;
            html += '<div class="col"><div class="text-center"><div class="fw-bold">' + formaterNombre(d.nb_pages) + '</div><div class="progress mb-1" style="height:6px;"><div class="progress-bar" style="width:' + pct + '%;"></div></div><div style="font-size:0.7rem;" class="text-muted">' + d.label + '</div></div></div>';
        });
        html += '</div>';
    }

    html += '<div id="treemapConteneur" class="mb-4"></div>';
    html += '<h6>' + t('pagerank.classement') + '</h6><div id="pagerankTabConteneur"></div>';

    if (data.par_section && Object.keys(data.par_section).length > 0) {
        html += '<h6 class="mt-4">' + t('pagerank.par_section') + '</h6>';
        html += '<div class="row mb-2"><div class="col-md-4"><select class="form-select form-select-sm" id="selectSectionPR"><option value="">' + t('pagerank.choisir_section') + '</option>';
        Object.keys(data.par_section).sort().forEach(function(s) {
            html += '<option value="' + escapeHtml(s) + '">' + escapeHtml(s) + ' (' + data.par_section[s].length + ' pages)</option>';
        });
        html += '</select></div></div><div id="contenuSectionPR"></div>';
    }

    el.innerHTML = html;

    creerTableauPagine({
        conteneur: document.getElementById('pagerankTabConteneur'),
        colonnes: [
            {cle:'_rang', label:t('table.rang'), render: function(r) { return r._rang; }},
            {cle:'url', label:t('table.url'), tri:true, render: function(r) { return rendreUrlCopiable(r.url); }},
            {cle:'score', label:t('table.score'), tri:true, render: function(r) { return '<strong>' + r.score.toFixed(1) + '</strong>'; }},
            {cle:'efficacite', label:t('table.efficacite'), tri:true, render: function(r) { var e = r.efficacite || 0; return '<span style="color:' + (e > 10 ? 'var(--score-high)' : (e > 3 ? 'var(--brand-gold)' : 'var(--text-muted)')) + ';">' + e.toFixed(1) + '</span>'; }},
            {cle:'section', label:t('table.section'), tri:true},
            {cle:'nb_entrants', label:t('table.entrants'), tri:true},
            {cle:'nb_sortants', label:t('table.sortants'), tri:true},
        ],
        donnees: data.classement.map(function(p, i) { return Object.assign({}, p, {_rang: i + 1}); }),
        placeholder: t('table.filtrer_url_section'),
        filtrer: function(item, terme) { return item.url.toLowerCase().includes(terme) || item.section.toLowerCase().includes(terme); },
        exportNom: 'pagerank.csv',
    });

    chargerTreemap(data);

    var selectPR = document.getElementById('selectSectionPR');
    if (selectPR) {
        selectPR.addEventListener('change', function() {
            var section = this.value;
            var conteneurPR = document.getElementById('contenuSectionPR');
            if (!section || !data.par_section[section]) { conteneurPR.innerHTML = ''; return; }
            var h = '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>#</th><th>URL</th><th>Score</th></tr></thead><tbody>';
            data.par_section[section].forEach(function(p, i) {
                h += '<tr><td>' + (i+1) + '</td><td>' + rendreUrlCopiable(p.url) + '</td><td><strong>' + p.score.toFixed(1) + '</strong></td></tr>';
            });
            h += '</tbody></table></div>';
            conteneurPR.innerHTML = h;
        });
    }
}

// ── Treemap Chart.js ─────────────────────────────────────

function chargerTreemap(data) {
    var conteneur = document.getElementById('treemapConteneur');
    if (!conteneur || !data.classement || data.classement.length === 0) return;

    // Charger Chart.js + plugin treemap dynamiquement
    var script1 = document.createElement('script');
    script1.src = 'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js';
    script1.onload = function() {
        var script2 = document.createElement('script');
        script2.src = 'https://cdn.jsdelivr.net/npm/chartjs-chart-treemap@2/dist/chartjs-chart-treemap.min.js';
        script2.onload = function() {
            rendreTreemap(conteneur, data);
        };
        document.head.appendChild(script2);
    };
    document.head.appendChild(script1);
}

function rendreTreemap(conteneur, data) {
    conteneur.innerHTML = '<h6>' + t('pagerank.treemap') + '</h6><canvas id="canvasTreemap" style="max-height:400px;"></canvas>';
    var canvas = document.getElementById('canvasTreemap');

    // Regrouper par section
    var sections = {};
    data.classement.forEach(function(p) {
        if (!sections[p.section]) sections[p.section] = { total: 0, count: 0 };
        sections[p.section].total += p.score;
        sections[p.section].count++;
    });

    var treemapData = Object.keys(sections).map(function(s) {
        return {
            section: s,
            poids: Math.round(sections[s].total),
            pages: sections[s].count,
        };
    });

    var couleurs = ['#004c4c', '#066', '#088', '#0aa', '#66b2b2', '#88cccc', '#aadddd', '#cce8e8', '#fbb03b', '#f97316'];

    new Chart(canvas, {
        type: 'treemap',
        data: {
            datasets: [{
                tree: treemapData,
                key: 'poids',
                groups: ['section'],
                backgroundColor: function(ctx) {
                    return couleurs[ctx.dataIndex % couleurs.length];
                },
                borderColor: '#fff',
                borderWidth: 2,
                labels: {
                    display: true,
                    formatter: function(ctx) {
                        if (ctx.type !== 'data') return '';
                        return ctx.raw._data.section + '\n' + ctx.raw._data.pages + ' pages';
                    },
                    color: '#fff',
                    font: { size: 12, weight: 'bold' },
                },
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title: function(items) { return items[0].raw._data.section; },
                        label: function(item) {
                            return t('pagerank.tooltip', {poids: item.raw._data.poids, pages: item.raw._data.pages});
                        },
                    },
                },
            },
        },
    });
}

// ── Renderer : Liste des ancres + Nuage ──────────────────

function rendreListeAncres(el, data) {
    var html = '<p class="memo-onglet"><i class="bi bi-info-circle me-1"></i>' + t('ancres.memo') + '</p>';
    html += '<div class="kpi-row mb-4">';
    html += construireKpi(formaterNombre(data.nb_ancres_total), t('kpi.nb_ancres_total'), '', 'kpi-dark');
    html += construireKpi((data.pct_generiques || 0).toFixed(0) + '%', t('kpi.generiques'), formaterNombre(data.nb_generiques || 0) + ' ' + t('tab.ancres').toLowerCase(), (data.pct_generiques || 0) > 30 ? 'kpi-red' : 'kpi-green');
    html += construireKpi((data.longueur_moyenne || 0).toFixed(1), t('kpi.mots_ancre_moy'), '', '');
    html += construireKpi(formaterNombre(data.nb_suspectes_cannib || 0), t('kpi.suspectes_cannib'), t('kpi.trois_plus_dest'), (data.nb_suspectes_cannib || 0) > 0 ? 'kpi-gold' : 'kpi-green');
    html += '</div>';

    // Barre de répartition par type
    if (data.types_distribution) {
        var td = data.types_distribution;
        var total = (td.vide||0)+(td.generique||0)+(td.url_nue||0)+(td.mot_cle||0)+(td.descriptif||0);
        if (total > 0) {
            html += '<div class="types-bar mb-4">';
            var segments = [
                {type:'descriptif',label:t('ancres.descriptifs'),n:td.descriptif||0,color:'#22c55e'},
                {type:'mot_cle',label:t('ancres.mot_cle'),n:td.mot_cle||0,color:'#fbb03b'},
                {type:'generique',label:t('ancres.generiques'),n:td.generique||0,color:'#ef4444'},
                {type:'url_nue',label:t('ancres.url_nues'),n:td.url_nue||0,color:'#f97316'},
                {type:'vide',label:t('ancres.vides'),n:td.vide||0,color:'#94a3b8'},
            ];
            html += '<div class="types-bar-track">';
            segments.forEach(function(s) {
                var pct = (s.n / total * 100);
                if (pct > 0) html += '<div class="types-bar-segment" style="width:' + pct.toFixed(1) + '%;background:' + s.color + ';" title="' + s.label + ': ' + s.n + ' (' + pct.toFixed(0) + '%)"></div>';
            });
            html += '</div><div class="types-bar-legend">';
            segments.forEach(function(s) {
                if (s.n > 0) html += '<span><span class="types-dot" style="background:' + s.color + ';"></span>' + s.label + ' : ' + formaterNombre(s.n) + '</span>';
            });
            html += '</div></div>';
        }
    }

    html += '<h6><i class="bi bi-grid-3x3-gap me-1"></i>' + t('ancres.par_type') + '</h6><div id="nuageAncres" class="nuage-grille mb-4"></div>';
    html += '<div id="detailAncrePanel" class="d-none mb-4"></div>';
    html += '<div id="ancresTabConteneur"></div>';

    el.innerHTML = html;

    rendreNuageAncres(data.ancres);

    creerTableauPagine({
        conteneur: document.getElementById('ancresTabConteneur'),
        colonnes: [
            {cle:'ancre', label:t('table.ancre'), tri:true, render: function(r) { return '<strong>' + escapeHtml(r.ancre) + '</strong>'; }},
            {cle:'type', label:t('table.type'), tri:true, render: function(r) { return badgeType(r.type || 'mot_cle'); }},
            {cle:'nb_occurrences', label:t('table.occurrences'), tri:true},
            {cle:'nb_destinations', label:t('table.destinations'), tri:true, render: function(r) {
                var txt = String(r.nb_destinations);
                if (r.suspect_cannibale) txt += ' <span class="badge-attention" style="font-size:0.6rem;">Cannib?</span>';
                return txt;
            }},
            {cle:'_top_dest', label:t('table.top_destinations'), render: function(r) {
                return (r.top_destinations || []).map(function(d) { return rendreUrlCopiable(d.destination) + ' (' + d.nb + ')'; }).join(', ');
            }},
        ],
        donnees: data.ancres.map(function(a) { return Object.assign({}, a, {_top_dest:''}); }),
        placeholder: t('table.filtrer_ancre'),
        filtrer: function(item, terme) { return item.ancre.toLowerCase().includes(terme) || (item.type || '').includes(terme); },
        exportNom: 'ancres.csv',
    });

    document.querySelectorAll('#nuageAncres .nuage-mot').forEach(function(mot) {
        mot.addEventListener('click', function() {
            var ancre = mot.getAttribute('data-ancre');
            chargerDetailAncre(ancre);
        });
    });
}

function rendreNuageAncres(ancres) {
    var conteneur = document.getElementById('nuageAncres');
    if (!conteneur) return;

    var topAncres = ancres.slice(0, 100);
    if (topAncres.length === 0) return;

    var maxOcc = topAncres[0].nb_occurrences;
    var logMax = Math.log(maxOcc + 1);
    var logMin = Math.log((topAncres[topAncres.length - 1].nb_occurrences || 1) + 1);

    // Grouper par type
    var parType = {descriptif:[], mot_cle:[], generique:[], url_nue:[], vide:[]};
    var typesLabels = {descriptif:t('ancres.descriptifs'), mot_cle:t('ancres.mot_cle'), generique:t('ancres.generiques'), url_nue:t('ancres.url_nues'), vide:t('ancres.vides')};
    var typesCouleurs = {descriptif:'#065f46', mot_cle:'#92400e', generique:'#94a3b8', url_nue:'#f97316', vide:'#cbd5e1'};
    var typesBg = {descriptif:'#f0fdf4', mot_cle:'#fef4e0', generique:'#f1f5f9', url_nue:'#fff7ed', vide:'#f8fafc'};

    topAncres.forEach(function(a) {
        var t = a.type || 'mot_cle';
        if (parType[t]) parType[t].push(a);
    });

    var html = '';
    Object.keys(parType).forEach(function(type) {
        var ancresType = parType[type];
        if (ancresType.length === 0) return;

        html += '<div class="nuage-type-groupe" style="border-left:3px solid ' + typesCouleurs[type] + ';background:' + typesBg[type] + ';">';
        html += '<div class="nuage-type-titre" style="color:' + typesCouleurs[type] + ';">' + typesLabels[type] + ' <small>(' + ancresType.length + ')</small></div>';
        html += '<div class="nuage-type-mots">';
        ancresType.forEach(function(a) {
            var logVal = Math.log(a.nb_occurrences + 1);
            var ratio = logMax > logMin ? (logVal - logMin) / (logMax - logMin) : 0.5;
            var taille = 0.75 + ratio * 1.2;
            var opacite = 0.5 + ratio * 0.5;
            html += '<span class="nuage-mot" data-ancre="' + escapeHtml(a.ancre) + '" style="font-size:' + taille.toFixed(2) + 'rem;color:' + typesCouleurs[type] + ';opacity:' + opacite.toFixed(2) + ';" title="' + escapeHtml(a.ancre) + ' — ' + formaterNombre(a.nb_occurrences) + ' occ. → ' + a.nb_destinations + ' dest.">' + escapeHtml(a.ancre) + '</span> ';
        });
        html += '</div></div>';
    });

    conteneur.innerHTML = html;
}

function chargerDetailAncre(ancre) {
    var panel = document.getElementById('detailAncrePanel');
    if (!panel || !etat.jobId) return;

    panel.classList.remove('d-none');
    panel.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div> ' + t('error.chargement') + ' "' + escapeHtml(ancre) + '"…</div>';
    panel.scrollIntoView({behavior: 'smooth', block: 'nearest'});

    fetchJson(baseUrl + '/advanced_analysis.php?importId=' + encodeURIComponent(etat.jobId) + '&type=detail_ancre&ancre=' + encodeURIComponent(ancre), {}, 'Détail ancre')
    .then(function(data) {
        var html = '<div class="card"><div class="card-header d-flex justify-content-between align-items-center">';
        html += '<h6 class="mb-0"><i class="bi bi-link-45deg me-1"></i>' + t('ancres.detail') + ' "' + escapeHtml(data.ancre) + '"</h6>';
        html += '<button class="btn btn-sm btn-outline-secondary" onclick="document.getElementById(\'detailAncrePanel\').classList.add(\'d-none\');"><i class="bi bi-x"></i></button>';
        html += '</div><div class="card-body">';

        // KPI
        html += '<div class="kpi-row mb-3">';
        html += construireKpi(formaterNombre(data.nb_total), t('kpi.occurrences'), '', 'kpi-dark');
        html += construireKpi(formaterNombre(data.nb_sources), t('kpi.pages_sources'), '', '');
        html += construireKpi(formaterNombre(data.nb_destinations), t('kpi.destinations'), '', data.nb_destinations > 3 ? 'kpi-gold' : 'kpi-green');
        html += '</div>';

        // Top destinations
        html += '<div class="row"><div class="col-md-6">';
        html += '<h6 class="mb-2">' + t('ancres.top_destinations') + '</h6>';
        html += '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>' + t('ancres.url_destination') + '</th><th>' + t('table.liens') + '</th></tr></thead><tbody>';
        (data.top_destinations || []).forEach(function(d) {
            html += '<tr><td>' + rendreUrlCopiable(d.destination) + '</td><td><strong>' + formaterNombre(d.nb) + '</strong></td></tr>';
        });
        html += '</tbody></table></div></div>';

        // Top sources
        html += '<div class="col-md-6">';
        html += '<h6 class="mb-2">' + t('ancres.top_sources') + '</h6>';
        html += '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>' + t('ancres.url_source') + '</th><th>' + t('table.liens') + '</th></tr></thead><tbody>';
        (data.top_sources || []).forEach(function(s) {
            html += '<tr><td>' + rendreUrlCopiable(s.source) + '</td><td><strong>' + formaterNombre(s.nb) + '</strong></td></tr>';
        });
        html += '</tbody></table></div></div></div>';

        // Paires source → destination
        if (data.liens && data.liens.length > 0) {
            html += '<h6 class="mt-3 mb-2">' + t('ancres.paires') + '</h6>';
            html += '<div id="detailAncrePairesTab"></div>';
        }

        html += '</div></div>';
        panel.innerHTML = html;

        if (data.liens && data.liens.length > 0) {
            creerTableauPagine({
                conteneur: document.getElementById('detailAncrePairesTab'),
                colonnes: [
                    {cle:'source', label:t('cannibale.page_source'), tri:true, render: function(r) { return rendreUrlCopiable(r.source); }},
                    {cle:'destination', label:t('table.destinations'), tri:true, render: function(r) { return rendreUrlCopiable(r.destination); }},
                    {cle:'nb', label:t('cannibale.nb'), tri:true},
                ],
                donnees: data.liens,
                placeholder: t('table.filtrer'),
                regexToggle: true,
                filtrer: function(item, terme, isRegex) {
                    if (isRegex) {
                        try { return new RegExp(terme, 'i').test(item.source) || new RegExp(terme, 'i').test(item.destination); }
                        catch(e) { return true; }
                    }
                    return item.source.toLowerCase().includes(terme) || item.destination.toLowerCase().includes(terme);
                },
                exportNom: 'detail_ancre_' + data.ancre.replace(/[^a-z0-9]/gi, '_') + '.csv',
            });
        }
    })
    .catch(function(err) {
        panel.innerHTML = '<div class="status-msg status-error">' + err.message + '</div>';
    });
}

// ══════════════════════════════════════════════════════════
//  GSC — SEARCH CONSOLE INTEGRATION
// ══════════════════════════════════════════════════════════

function verifierGsc() {
    fetchJson(baseUrl + '/gsc_analysis.php?action=verifier&importId=' + encodeURIComponent(etat.jobId), {}, 'GSC vérification')
        .then(function(data) {
            if (data.disponible) {
                etat.gscDisponible = true;
                etat.gscSites = data.sites || [];
                document.getElementById('tabGscItem').classList.remove('d-none');
            }
        })
        .catch(function() {
            // GSC non disponible — silencieux
        });
}

function initialiserOngletGsc() {
    var tabGsc = document.getElementById('tab-gsc');
    if (!tabGsc) return;

    tabGsc.addEventListener('shown.bs.tab', function() {
        if (etat.cacheAnalyses['gsc']) return;
        rendreGscInsights();
    });
}

function rendreGscInsights() {
    var conteneur = document.getElementById('resultatsGsc');
    if (!conteneur) return;

    if (!etat.gscDisponible || etat.gscSites.length === 0) {
        conteneur.innerHTML = '<div class="status-msg status-error">' + t('gsc.non_disponible') + '</div>';
        return;
    }

    var html = '<div class="row mb-4">';
    html += '<div class="col-md-4">';
    html += '<label for="selectSiteGsc" class="form-label">' + t('gsc.site') + '</label>';
    html += '<select class="form-select" id="selectSiteGsc">';
    etat.gscSites.forEach(function(s) {
        html += '<option value="' + s.id + '">' + escapeHtml(s.url) + '</option>';
    });
    html += '</select></div>';
    html += '<div class="col-md-3">';
    html += '<label for="dateDebutGsc" class="form-label">' + t('gsc.date_debut') + '</label>';
    html += '<input type="date" class="form-control" id="dateDebutGsc" value="' + dateIl_y_a(90) + '">';
    html += '</div>';
    html += '<div class="col-md-3">';
    html += '<label for="dateFinGsc" class="form-label">' + t('gsc.date_fin') + '</label>';
    html += '<input type="date" class="form-control" id="dateFinGsc" value="' + dateIl_y_a(0) + '">';
    html += '</div>';
    html += '<div class="col-md-2 d-flex align-items-end">';
    html += '<button class="btn btn-primary btn-sm w-100" id="btnChargerGsc"><i class="bi bi-arrow-clockwise me-1"></i>' + t('btn.charger') + '</button>';
    html += '</div></div>';

    // 4 sous-analyses
    html += '<ul class="nav nav-tabs nav-tabs-inner mb-3" id="sousTabsGsc" role="tablist">';
    html += '<li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#gsc-fortes">' + t('gsc.fortes_sans_maillage') + '</button></li>';
    html += '<li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#gsc-maillees">' + t('gsc.maillees_invisibles') + '</button></li>';
    html += '<li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#gsc-ancre-vs-requete">' + t('gsc.ancre_vs_requete') + '</button></li>';
    html += '<li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#gsc-budget">' + t('gsc.budget_crawl') + '</button></li>';
    html += '</ul>';
    html += '<div class="tab-content">';
    html += '<div class="tab-pane fade show active" id="gsc-fortes"><div id="gscFortesConteneur"></div></div>';
    html += '<div class="tab-pane fade" id="gsc-maillees"><div id="gscMailleesConteneur"></div></div>';
    html += '<div class="tab-pane fade" id="gsc-ancre-vs-requete"><div id="gscAncreRequeteConteneur"></div></div>';
    html += '<div class="tab-pane fade" id="gsc-budget"><div id="gscBudgetConteneur"></div></div>';
    html += '</div>';

    conteneur.innerHTML = html;

    document.getElementById('btnChargerGsc').addEventListener('click', function() {
        chargerDonneesGsc();
    });

    // Charger automatiquement au premier affichage
    chargerDonneesGsc();
}

function dateIl_y_a(jours) {
    var d = new Date();
    d.setDate(d.getDate() - jours);
    return d.toISOString().split('T')[0];
}

function chargerDonneesGsc() {
    var siteId = document.getElementById('selectSiteGsc').value;
    var dateDebut = document.getElementById('dateDebutGsc').value;
    var dateFin = document.getElementById('dateFinGsc').value;

    var types = ['fortes_sans_maillage', 'maillees_invisibles', 'ancre_vs_requete', 'budget_sections'];
    var conteneurs = {
        'fortes_sans_maillage': 'gscFortesConteneur',
        'maillees_invisibles': 'gscMailleesConteneur',
        'ancre_vs_requete': 'gscAncreRequeteConteneur',
        'budget_sections': 'gscBudgetConteneur',
    };

    types.forEach(function(type) {
        var el = document.getElementById(conteneurs[type]);
        if (el) el.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div> ' + t('error.chargement') + '</div>';

        fetchJson(
            baseUrl + '/gsc_analysis.php?importId=' + encodeURIComponent(etat.jobId) +
            '&siteId=' + encodeURIComponent(siteId) +
            '&dateDebut=' + encodeURIComponent(dateDebut) +
            '&dateFin=' + encodeURIComponent(dateFin) +
            '&type=' + type,
            {},
            'GSC ' + type
        )
        .then(function(data) {
            etat.cacheAnalyses['gsc'] = true;
            switch (type) {
                case 'fortes_sans_maillage': rendreGscFortes(el, data); break;
                case 'maillees_invisibles': rendreGscMaillees(el, data); break;
                case 'ancre_vs_requete': rendreGscAncreRequete(el, data); break;
                case 'budget_sections': rendreGscBudget(el, data); break;
            }
        })
        .catch(function(err) {
            if (el) el.innerHTML = '<div class="status-msg status-error">' + err.message + '</div>';
        });
    });
}

function rendreGscFortes(el, data) {
    var html = '<p class="text-muted mb-2" style="font-size:0.82rem;"><i class="bi bi-lightbulb me-1"></i>' + t('gsc.fortes_desc') + '</p>';

    // Quick wins
    if (data.quick_wins && data.quick_wins.length > 0) {
        html += '<div class="status-msg status-success mb-3"><i class="bi bi-stars me-1"></i><strong>' + data.quick_wins.length + ' ' + t('gsc.quick_wins') + '</strong> — ' + t('gsc.quick_wins_desc') + '</div>';
        html += '<h6 class="mb-2">' + t('gsc.quick_wins_titre') + '</h6><div id="gscQuickWinsTab"></div>';
    }

    html += '<h6 class="mt-4 mb-2">' + t('gsc.toutes_sous_maillees') + '</h6><div id="gscFortesTab"></div>';
    el.innerHTML = html;

    if (data.quick_wins && data.quick_wins.length > 0) {
        creerTableauPagine({
            conteneur: document.getElementById('gscQuickWinsTab'),
            colonnes: [
                {cle:'url', label:t('table.url'), tri:true, render: function(r) { return rendreUrlCopiable(r.url); }},
                {cle:'position_moy', label:t('gsc.position'), tri:true, render: function(r) { return '<strong>' + r.position_moy.toFixed(1) + '</strong>'; }},
                {cle:'clics', label:t('gsc.clics'), tri:true, render: function(r) { return formaterNombre(r.clics); }},
                {cle:'impressions', label:t('gsc.impressions'), tri:true, render: function(r) { return formaterNombre(r.impressions); }},
                {cle:'nb_liens_entrants', label:t('gsc.liens'), tri:true},
            ],
            donnees: data.quick_wins, exportNom: 'gsc_quick_wins.csv',
            placeholder: t('table.filtrer'), filtrer: function(i,t) { return i.url.toLowerCase().includes(t); },
        });
    }

    creerTableauPagine({
        conteneur: document.getElementById('gscFortesTab'),
        colonnes: [
            {cle:'url', label:t('table.url'), tri:true, render: function(r) { return rendreUrlCopiable(r.url); }},
            {cle:'position_moy', label:t('gsc.position_moy'), tri:true, render: function(r) { return r.position_moy.toFixed(1); }},
            {cle:'clics', label:t('gsc.clics'), tri:true, render: function(r) { return formaterNombre(r.clics); }},
            {cle:'impressions', label:t('gsc.impressions'), tri:true, render: function(r) { return formaterNombre(r.impressions); }},
            {cle:'nb_liens_entrants', label:t('gsc.liens_entrants'), tri:true},
            {cle:'priorite', label:t('gsc.priorite'), tri:true, render: function(r) {
                var badge = r.priorite > 1000 ? 'badge-erreur' : (r.priorite > 100 ? 'badge-attention' : 'badge-succes');
                return '<span class="' + badge + '">' + formaterNombre(Math.round(r.priorite)) + '</span>';
            }},
        ],
        donnees: data.pages || [],
        placeholder: t('table.filtrer_url'),
        filtrer: function(item, terme) { return item.url.toLowerCase().includes(terme); },
        exportNom: 'gsc_fortes_sans_maillage.csv',
    });
}

function rendreGscMaillees(el, data) {
    var html = '<p class="text-muted mb-2" style="font-size:0.82rem;"><i class="bi bi-lightbulb me-1"></i>' + t('gsc.maillees_desc') + '</p>';
    var div = document.createElement('div');
    el.innerHTML = html;
    el.appendChild(div);

    creerTableauPagine({
        conteneur: div,
        colonnes: [
            { cle: 'url', label: t('table.url'), tri: true, render: function(r) { return '<a href="' + escapeHtml(r.url) + '" target="_blank" rel="noopener">' + tronquerUrl(r.url) + '</a>'; } },
            { cle: 'nb_liens_entrants', label: t('gsc.liens_entrants'), tri: true },
            { cle: 'pagerank', label: t('gsc.pagerank'), tri: true, render: function(r) { return r.pagerank.toFixed(1); } },
            { cle: 'impressions_gsc', label: t('gsc.impressions_gsc'), tri: true },
            { cle: 'diagnostic', label: t('sections.diagnostic'), render: function(r) { return '<span style="font-size:0.8rem;">' + escapeHtml(r.diagnostic) + '</span>'; } },
        ],
        donnees: data.pages || [],
        placeholder: t('table.filtrer_url'),
        filtrer: function(item, terme) { return item.url.toLowerCase().includes(terme); },
        exportNom: 'gsc_maillees_invisibles.csv',
    });
}

function rendreGscAncreRequete(el, data) {
    var html = '<p class="text-muted mb-2" style="font-size:0.82rem;"><i class="bi bi-lightbulb me-1"></i>' + t('gsc.ancre_requete_desc') + '</p>';
    var div = document.createElement('div');
    el.innerHTML = html;
    el.appendChild(div);

    creerTableauPagine({
        conteneur: div,
        colonnes: [
            { cle: 'url', label: t('table.url'), tri: true, render: function(r) { return '<a href="' + escapeHtml(r.url) + '" target="_blank" rel="noopener">' + tronquerUrl(r.url) + '</a>'; } },
            { cle: 'top_ancre', label: t('gsc.top_ancre'), tri: true, render: function(r) { return '<strong>' + escapeHtml(r.top_ancre) + '</strong>'; } },
            { cle: 'top_requete', label: t('gsc.top_requete'), tri: true, render: function(r) { return escapeHtml(r.top_requete); } },
            { cle: 'clics', label: t('gsc.clics'), tri: true },
            { cle: 'position', label: t('gsc.position'), tri: true, render: function(r) { return r.position.toFixed(1); } },
            { cle: 'score_correspondance', label: t('gsc.score'), tri: true, render: function(r) {
                var badge = r.score_correspondance > 0.5 ? 'badge-succes' : (r.score_correspondance > 0.2 ? 'badge-attention' : 'badge-erreur');
                return '<span class="' + badge + '">' + (r.score_correspondance * 100).toFixed(0) + '%</span>';
            }},
            { cle: 'action', label: t('table.action'), render: function(r) { return '<span style="font-size:0.8rem;">' + escapeHtml(r.action || '') + '</span>'; } },
        ],
        donnees: data.pages || [],
        placeholder: t('table.filtrer'),
        filtrer: function(item, terme) {
            return item.url.toLowerCase().includes(terme) ||
                   item.top_ancre.toLowerCase().includes(terme) ||
                   item.top_requete.toLowerCase().includes(terme);
        },
        exportNom: 'gsc_ancre_vs_requete.csv',
    });
}

function rendreGscBudget(el, data) {
    var html = '<p class="text-muted mb-2" style="font-size:0.82rem;"><i class="bi bi-lightbulb me-1"></i>' + t('gsc.budget_desc') + '</p>';

    if (data.sections && data.sections.length > 0) {
        html += '<div class="table-responsive"><table class="table"><thead><tr>' +
            '<th>' + t('sections.section') + '</th><th>' + t('sections.pages') + '</th><th>' + t('gsc.pct_maillage') + '</th><th>' + t('gsc.pct_trafic') + '</th><th>' + t('gsc.ratio') + '</th><th>' + t('sections.diagnostic') + '</th>' +
            '</tr></thead><tbody>';

        data.sections.forEach(function(s) {
            var badgeRatio = s.ratio < 0.5 ? 'badge-erreur' : (s.ratio > 2.0 ? 'badge-attention' : 'badge-succes');
            html += '<tr>' +
                '<td><strong>' + escapeHtml(s.section) + '</strong></td>' +
                '<td>' + s.nb_pages + '</td>' +
                '<td>' + s.pct_maillage.toFixed(1) + '%</td>' +
                '<td>' + s.pct_trafic.toFixed(1) + '%</td>' +
                '<td><span class="' + badgeRatio + '">' + s.ratio.toFixed(2) + '</span></td>' +
                '<td style="font-size:0.8rem;">' + escapeHtml(s.diagnostic) + '</td>' +
                '</tr>';
        });

        html += '</tbody></table></div>';
    } else {
        html += '<p class="text-muted">' + t('gsc.aucune_donnee') + '</p>';
    }

    el.innerHTML = html;
}

// ══════════════════════════════════════════════════════════
//  EXPORTS
// ══════════════════════════════════════════════════════════

function exporterAvance(type) {
    window.location.href = baseUrl + '/download.php?jobId=' + encodeURIComponent(etat.jobId) + '&type=' + type;
}

// ══════════════════════════════════════════════════════════
//  INITIALISATION
// ══════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', function() {
    traduirePage();
    initLangueSelect();

    initialiserDropZoneLiens();
    initialiserDropZoneAncres();
    initialiserOngletsAvances();
    initialiserOngletGsc();

    // Charger les imports précédents
    chargerListeImports();

    // Bouton "Nouvel import"
    document.getElementById('btnNouvelImport').addEventListener('click', function() {
        afficherSection('sectionUploadLiens');
    });

    // Bouton importer
    document.getElementById('btnImporter').addEventListener('click', function() {
        lancerImport();
    });

    // Bouton analyser (cannibalisation)
    document.getElementById('btnAnalyser').addEventListener('click', function() {
        lancerAnalyse();
    });

    document.getElementById('btnCannibaleAuto').addEventListener('click', function() {
        lancerCannibaleAuto();
    });

    // Sélection ancre dans l'onglet détail cannibalisation
    document.getElementById('selectAncre').addEventListener('change', function() {
        var ancreNorm = this.value;
        if (!ancreNorm || !etat.resultats) {
            afficherDetailAncre(null);
            return;
        }
        var resultat = etat.resultats.resultats.find(function(r) {
            return r.ancre_normalisee === ancreNorm;
        });
        afficherDetailAncre(resultat || null);
    });

    // Re-parse aperçu ancres quand la checkbox en-tête change
    document.getElementById('avecEntete').addEventListener('change', function() {
        if (etat.fichierAncres) {
            afficherApercuAncres();
        }
    });

    // Export détail cannibalisation
    document.getElementById('btnExportDetail').addEventListener('click', function() {
        if (etat.jobId) {
            window.location.href = baseUrl + '/download.php?jobId=' + encodeURIComponent(etat.jobId) + '&type=detail';
        }
    });
});
