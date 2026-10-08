# Journal des changements

## 1.1.1 — 2026-10-06

- **Garanties d'envoi** : si la file d'attente est indisponible (écriture refusée, erreur de base), le mail part en direct comme avant — la file n'empêche jamais un envoi. Un mail envoyé aussitôt est mis en file avec une heure d'envoi décalée de 5 minutes, pour que la tâche « queuednotification » ne l'envoie pas une seconde fois pendant l'envoi immédiat.
- **Mail de transfert envoyé par la file d'attente des notifications de GLPI**, aussitôt : le résultat s'affiche
  toujours tout de suite. En cas d'échec, le transfert est enregistré (historique du ticket) et le mail reste en
  file : GLPI le renvoie automatiquement, un avertissement le dit. Un mail par destinataire : chacun ne voit plus les
  adresses des autres. Visible dans Administration → File d'attente des notifications. Aucune migration de base.
