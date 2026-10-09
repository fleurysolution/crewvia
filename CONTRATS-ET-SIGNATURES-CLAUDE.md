## Complément contrats et signatures — 8 octobre 2026

Nouveau module `/contracts`, accessible au recrutement et au salarié concerné, interdit au client. Le bureau configure par projet les pièces préalables, la méthode interne/DocuSign et l’obligation de signature avant déploiement. Le candidat possède son compte dès le screening pour déposer les pièces avant le contrat. Aucun I-9 préalable à l’offre n’est exigé automatiquement.

Le parcours est désormais : dépôt des pièces → revue de la dernière version → préparation du brouillon → PDF original facultatif en interne/nécessaire pour DocuSign → approbation → émission → consultation → signature ou refus → archive → onboarding. Les pièces manquantes, en attente ou dont l’approbation est retirée bloquent le contrat. Les anciens boutons d’offre/acceptation ne contournent pas le parcours lorsqu’un contrat ou la politique de signature obligatoire existe.

Signature interne : consentement explicite, identité du compte, nom saisi, heure serveur, empreinte des conditions et du PDF original. Archive et justificatif JSON téléchargeables, fichiers privés chiffrés. Un PDF signé téléversé reste en revue ; validation documentée du bureau nécessaire. Refus, expiration, annulation, versions et actions sont historisés. Un contrat signé reste immuable. La signature interne n’est pas une signature cryptographique PDF ni un certificat DocuSign.

DocuSign : adaptateur de création d’enveloppe, ouverture de signature depuis le portail, confirmation serveur du signataire, récupération du PDF signé et du certificat, file Connect HMAC et traitement CLI. La source respecte l’activation explicite et conserve un identifiant de transaction pour éviter un renvoi aveugle après erreur. **Désactivé en staging, aucun compte réel ni envoi testé.** Provisionnement/renouvellement OAuth privés, validation du compte développeur, configuration Connect et supervision nécessaires avant activation. Ce n’est pas une intégration fournisseur validée en production. Voir `install/DOCUSIGN-STAGING.md`.

Pas de moteur de contre-signature/multiples signataires ni de modification d’un contrat déjà signé. Les exigences métier/juridiques restent configurées par l’agence ; ces fonctions ne constituent pas une certification réglementaire.

Message complémentaire à Claude : « Le module Contrats et signatures a été ajouté. Revois `install/DOCUSIGN-STAGING.md`, `contracts.sql`, le parcours `/contracts` et les tests. Vérifie dans Chrome le dépôt/revue des pièces, l’émission, la signature interne ou l’upload revu, l’archivage et le blocage de déploiement. Garde `docusign.enabled=false` et tous les autres services externes désactivés. La validation DocuSign réelle sera une étape distincte avec compte développeur et OAuth. »

