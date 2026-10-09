# Crewvia — finalisation locale et passage au staging

Produit de Fleury Solutions ; Crewvia est un nom de travail. Source : D:\app\rss-ops. RSS est un prospect parmi d'autres agences. BPMS, Fleury Solutions et Edocis Pro n'ont pas été modifiés. Aucun commit, push ou déploiement serveur n'a été effectué.

## Décision du propriétaire

Préparer le staging avec les services externes désactivés. Garder email_delivery_enabled=false, ai_enabled=false, commercial_mode=demo, recruiting_webhooks=[] ; ne pas renseigner de secrets Google, Stripe ou partenaires. Les files internes et écrans peuvent être testés sans expédier de vrais messages ou paiements. Ne jamais copier les clés de BPMS.

## Ce qui est disponible dans le code

- Recrutement : réquisitions et QR, candidature par email, vivier public /join, CV privés chiffrés, historique des candidatures/refus et recherche des personnes déjà connues ; questionnaire configurable avec questions obligatoires avant offre, journal des appels/contacts et suivis. Les doublons restent en revue, sans fusion automatique d'identités non vérifiées.

- Offre signée, invitation et compte personnel ; anglais par défaut, choix français/espagnol. Employee Folder regroupe les affectations, documents et preuves. Onboarding configurable, instructions modifiables et historisées ; une modification remet les tâches actives en attente de nouvelle revue.

- I-9/W-4 et autorisations background/drug screen : documents privés, signature/horodatage, suivi de consentement et revue. Cas de screening configurables agence/bus/sur site et payeur ; qualifications par projet/métier/classe, preuve et expiration, blocages opérationnels si critères manquants.

- Plusieurs projets, sites/États, manning et vues de supervision ; hôtels/chambres/séjours et conflits, voyages/billets saisis, véhicules/permis/MVR/accord, itinéraires et capacités, équipements de l'agence ou du client. Organigramme, communication privée, notifications, demandes de congés et approbations, contenus de formation et incidents/consignes de sécurité.

- Import CSV/XLSX avec prévisualisation : identité, numéro employé, ID ADP, métier, dates, shift, statut d'emploi, mode de paiement, taux de paie/facturation/per diem et salaire par période. Imports en affectation proposée : aucun contournement des contrôles. Migration des historiques hôtel/transport/finance à définir sur les vrais fichiers.

- Présences approuvées vers préparation de paie hebdomadaire avec sources conservées ; politique d'heures supplémentaires configurable, base salaire et alertes de rapprochement. Export ADP préparatoire, remboursements contre reçus, paiements cash/chèque/virement enregistrés, fournisseurs, factures client figées et prévention de double facturation.

- Portail client isolé : projets autorisés, présences et contestations, rapports CSV, factures émises/payées imprimables. Connexion facultative : le bureau saisit les demandes reçues par email/téléphone, les modifie avec historique, les approuve et les convertit en projet. Recrutement, opérations et facturation continuent sans connexion client. Aucun taux de 35 % ajouté.

- Offboarding configurable et revu, retour des ressources avant clôture opérationnelle ; aucun blocage automatique du salaire dû. Droits serveur et isolation des espaces clients/tenants, documents chiffrés, CSRF, contrôle des sessions, limitation des tentatives, MFA TOTP avec codes de récupération à usage unique, récupération du mot de passe par file d'email chiffrée.

- Socle commercial : installation neuve vide, configuration de marque, isolation par hôte/base/espace privé, abonnement par effectif et code Stripe optionnel avec signatures et idempotence. Ce n'est pas une facturation d'usage automatiquement certifiée.

- Socle mobile web installable avec manifeste/icônes ; utilisation en ligne. Code OAuth Gmail lecture/envoi, revue IA assistée sous contrôle humain, flux d'offres public/JobPosting/sitemap et contrat générique d'ingestion signé. Ces fonctions externes restent désactivées et non validées sur comptes réels.

## Limites à conserver explicitement

Les connecteurs natifs Indeed, LinkedIn, Handshake, Simplify et autres ne sont pas intégrés par le simple catalogue ni par le webhook générique. Ils nécessitent accords/API, développement spécifique et recette. Pas de téléphone intégré, achat automatique de billets/hôtels, synchronisation Gmail des pièces jointes, examen SCORM ou certificat de formation. Pas d'applications natives publiées iOS/Android, ni push en arrière-plan.

Le produit n'est pas une certification électronique I-9/E-Verify, laboratoire, procédure réglementaire complète de background screening, dossier FMCSA ou moteur fiscal/paie américain. ADP doit confirmer le format client ; rapprochement des heures multi-projets et règles locales nécessaire. Les signatures/proofs et calculs bruts ne remplacent pas cette validation. Les fichiers sont chiffrés et les formats filtrés ; aucun antivirus réel n'a été connecté.

## Passage au staging

1. Lire ce rapport, README.md et install/CONNECTOR-CONTRACT.md ; examiner le code et les limites.

2. Sauvegarder la base, le stockage privé, la configuration et la clé de chiffrement ; tester la restauration. Staging séparé, données synthétiques ou anonymisées, HTTPS, document root public/ uniquement.

3. Base existante : php install/upgrade.php. Base neuve : WORKFORCE_ADMIN_EMAIL puis php install/install.php --blank. L'installateur est désormais toujours vide ; --reset est refusé. Historique install/data exclu de l'archive commerciale, sans suppression du dossier local original.

4. Générer une clé unique et un utilisateur DB dédié ; debug=false, protections stockage/configuration, tâches planifiées par tenant. Laisser les services externes désactivés selon la décision ci-dessus.

5. Reproduire tests/README.md et tester dans Chrome les rôles bureau/recruteur, superviseur, employé, client et administrateur SaaS ; EN/FR/ES et largeur mobile. Vérifier candidature QR → screening → offre → compte → onboarding → qualification → déploiement → présence → facturation → offboarding, et demande client par email sans login.

6. Valider les configurations de contrat/rates/instructions avec le propriétaire. Ne pas qualifier cette recette de validation des fournisseurs réels, de conformité ou de charge concurrente.

## Message prêt à transmettre à Claude

« La finalisation locale est dans D:\app\rss-ops. Lis FINALISATION-STAGING-CLAUDE.md et validation-summary.json avant ta revue ; ce rapport remplace les anciens états d'avancement. Le portail client facultatif, les demandes client sans login, la sécurité MFA/récupération, les qualifications, les questionnaires de screening, les imports étendus, les consignes d'onboarding révisables, le vivier/CV, la consolidation des présences, l'offboarding et le socle mobile ont été complétés. Vérifie le code et reproduis les tests, puis prépare le staging HTTPS pour recette Chrome. Google/Gmail, Stripe, IA, emails sortants et partenaires restent désactivés selon le choix du propriétaire. N'active aucun fournisseur et ne modifie pas BPMS, Fleury Solutions ou Edocis Pro. Les limites externes/réglementaires/natives sont documentées ; ne les présente pas comme déjà livrées. »

## Complément contrats et signatures — 8 octobre 2026

Nouveau module `/contracts`, accessible au recrutement et au salarié concerné, interdit au client. Le bureau configure par projet les pièces préalables, la méthode interne/DocuSign et l’obligation de signature avant déploiement. Le candidat possède son compte dès le screening pour déposer les pièces avant le contrat. Aucun I-9 préalable à l’offre n’est exigé automatiquement.

Le parcours est désormais : dépôt des pièces → revue de la dernière version → préparation du brouillon → PDF original facultatif en interne/nécessaire pour DocuSign → approbation → émission → consultation → signature ou refus → archive → onboarding. Les pièces manquantes, en attente ou dont l’approbation est retirée bloquent le contrat. Les anciens boutons d’offre/acceptation ne contournent pas le parcours lorsqu’un contrat ou la politique de signature obligatoire existe.

Signature interne : consentement explicite, identité du compte, nom saisi, heure serveur, empreinte des conditions et du PDF original. Archive et justificatif JSON téléchargeables, fichiers privés chiffrés. Un PDF signé téléversé reste en revue ; validation documentée du bureau nécessaire. Refus, expiration, annulation, versions et actions sont historisés. Un contrat signé reste immuable. La signature interne n’est pas une signature cryptographique PDF ni un certificat DocuSign.

DocuSign : adaptateur de création d’enveloppe, ouverture de signature depuis le portail, confirmation serveur du signataire, récupération du PDF signé et du certificat, file Connect HMAC et traitement CLI. La source respecte l’activation explicite et conserve un identifiant de transaction pour éviter un renvoi aveugle après erreur. **Désactivé en staging, aucun compte réel ni envoi testé.** Provisionnement/renouvellement OAuth privés, validation du compte développeur, configuration Connect et supervision nécessaires avant activation. Ce n’est pas une intégration fournisseur validée en production. Voir `install/DOCUSIGN-STAGING.md`.

Pas de moteur de contre-signature/multiples signataires ni de modification d’un contrat déjà signé. Les exigences métier/juridiques restent configurées par l’agence ; ces fonctions ne constituent pas une certification réglementaire.

Message complémentaire à Claude : « Le module Contrats et signatures a été ajouté. Revois `install/DOCUSIGN-STAGING.md`, `contracts.sql`, le parcours `/contracts` et les tests. Vérifie dans Chrome le dépôt/revue des pièces, l’émission, la signature interne ou l’upload revu, l’archivage et le blocage de déploiement. Garde `docusign.enabled=false` et tous les autres services externes désactivés. La validation DocuSign réelle sera une étape distincte avec compte développeur et OAuth. »

## Résultat de la campagne finale

202 contrôles HTTP réussis ; 184 fichiers PHP vérifiés sans erreur de syntaxe ; aucun warning/fatal PHP relevé. Tests unitaires : saas 16, recruiting 9, security 14, payroll 7, connector 6, docusign 11. Catalogues EN/FR/ES et navigateur desktop/mobile vérifiés. Isolation réelle de deux bases/tenants vérifiée. 10 000 dossiers synthétiques répartis sur 10 projets testés par requêtes séquentielles ; pas de certification de charge concurrente. Preuves détaillées : validation-summary.json et outputs/test-evidence dans le dossier de remise Codex.
