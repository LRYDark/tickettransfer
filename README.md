# Transfert de ticket (tickettransfer)

Plugin GLPI 11 autonome permettant de transférer un ticket par email à un ou
plusieurs utilisateurs et/ou groupes, depuis la timeline du ticket.

Aucune dépendance à d'autres plugins (utilise uniquement le cœur GLPI).

## Fonctionnalités

- Bouton **Transférer ticket** dans la timeline du ticket (modal).
- Sélecteur unique combiné **utilisateurs + groupes** (recherche AJAX, email
  affiché au survol des utilisateurs). Un groupe envoie le mail à tous ses
  membres actifs.
- Option d'association des destinataires dans « Attribué à » (ajout ou
  remplacement), groupes inclus.
- Commentaire libre joint à l'email.
- Trace dans l'historique du ticket : « X a transféré le ticket à : … ».
- Modification optionnelle du statut du ticket après transfert.
- Fenêtre modale responsive (v1.1.0) : large et centrée sur ordinateur, panneau
  pleine largeur ancré en bas de l'écran sur téléphone, comme les modals des
  plugins RP et Gestion.

## Réglages du statut

Combinaison config globale (admin) / préférence utilisateur :

| Config globale | Préférence | Résultat |
|---|---|---|
| OFF | OFF | Statut inchangé |
| ON  | OFF | Statut = valeur config |
| OFF | ON  | Statut = valeur préférence |
| ON  | ON  | Statut = valeur préférence (choix utilisateur prioritaire) |

Deux autorisations admin :
- **Autoriser la modification dans les préférences** : si OFF, masqué et ignoré.
- **Autoriser la modification depuis le modal** : si OFF, pas de liste
  déroulante dans le modal (le statut change quand même selon la valeur
  effective).

## Droits (profil)

Droit `plugin_tickettransfer` :
- **Transférer un ticket** : accès au bouton/modal de transfert.
- **Gérer la configuration depuis les préférences** : accès à l'onglet
  « Transfert de ticket » dans les préférences utilisateur.

La page de configuration globale du plugin (onglet de Configuration > Générale)
est réservée aux super-admins (droit cœur `config`), pas un droit de profil.

## Tables

- `glpi_plugin_tickettransfer_configs`
- `glpi_plugin_tickettransfer_preferences`
