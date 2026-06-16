<?php
/* -------------------------------------------------------------------------
 * stoneChat / Server/langs/fr.php
 *
 * French (fr) language table. Key set matches the canonical en.php
 * (the language spec) so sc_t() never has to fall back to English.
 *
 * Formal register: button / menu labels are vous-imperatives
 * (Connectez-vous, Envoyez, Annulez, ...); error / status messages
 * keep an impersonal or vous-form construction.
 *
 * Loaded by Server/i18n.php via include; uses the `return array(...)`
 * style. UTF-8. PHP 5.2 compatible.
 * ------------------------------------------------------------------------- */

return array(
    // --- app ---
    'app.title'              => 'stoneChat',
    'app.tagline'            => 'Un chat web LLM hébergé localement, multi-fournisseurs.',

    // --- login ---
    'login.title'            => 'Connexion',
    'login.password'         => 'Mot de passe',
    'login.submit'           => 'Connectez-vous',
    'login.error'            => 'Mot de passe incorrect. Veuillez réessayer.',
    'login.locked'           => 'Trop de tentatives échouées. Veuillez patienter.',
    'login.locked'           => 'Trop de tentatives échouées. Veuillez patienter avant de réessayer.',

    // --- chat (action buttons / labels) ---
    'chat.send'              => 'Envoyez',
    'chat.stop'              => 'Arrêtez',
    'chat.regenerate'        => 'Régénérez',
    'chat.delete'            => 'Supprimez',
    'chat.new'               => 'Nouvelle',
    'chat.newChat'           => 'Nouvelle discussion',
    'chat.deleteChat'        => 'Supprimez la discussion',
    'chat.renameChat'        => 'Renommez la discussion',
    'chat.confirmDelete'     => 'Supprimer cette discussion ? Cette action est irréversible.',
    'chat.settings'          => 'Paramètres',
    'chat.model'             => 'Modèle',
    'chat.model.label'       => 'Modèle :',
    'chat.provider'          => 'Fournisseur',
    'chat.tokens.label'      => 'Jetons :',
    'chat.timeout.label'     => 'Délai (s) :',
    'chat.connectCheck'      => 'Vérifiez la connexion',
    'chat.reloadConfig'      => 'Rechargez la configuration',
    'chat.about'             => 'À propos',
    'chat.empty'             => 'Aucun message pour l’instant. Dites quelque chose pour commencer la conversation.',

    // --- chat: status & errors ---
    'chat.connected'         => 'Connecté',
    'chat.disconnected'      => 'Déconnecté',
    'chat.stream.warning'    => 'Connexion interrompue. La diffusion a été arrêtée.',
    'chat.error.network'     => 'Erreur réseau. Veuillez vérifier votre connexion.',
    'chat.error.timeout'     => 'Délai d’attente dépassé. Veuillez réessayer.',
    'chat.error.unauthorized'=> 'Non autorisé. Veuillez vous reconnecter.',

    // --- chat: input / countdown ---
    'chat.input.placeholder' => 'Saisissez un message...',
    'chat.countdown.waiting' => 'En attente d’une réponse...',
    'chat.countdown.seconds' => 's',

    // --- new chat dialog ---
    'newchat.title'          => 'Nouvelle discussion',
    'newchat.testAll'        => 'Testez tous les fournisseurs',
    'newchat.create'         => 'Créez',
    'newchat.cancel'         => 'Annulez',

    // --- about dialog ---
    'about.protocol'         => 'Protocole',
    'about.author'           => 'Auteur',
    'about.brief'            => 'À propos',
    'about.github'           => 'Dépôt GitHub',
    'about.close'            => 'Fermez',

    // --- history ---
    'history.title'          => 'Historique',
    'history.empty'          => 'Aucun historique pour l’instant.',
    'history.delete'         => 'Supprimez l’entrée',
    'history.new'            => 'Nouvelle discussion',
    'history.lastUsed'       => 'Dernière utilisation',

    // --- common (shared UI buttons) ---
    'common.cancel'          => 'Annulez',
    'common.confirm'         => 'Confirmez',
    'common.save'            => 'Enregistrez',
    'common.close'           => 'Fermez',
    'common.yes'             => 'Oui',
    'common.no'              => 'Non',

    // --- top-level error namespace ---
    'error.network'          => 'Erreur réseau. Veuillez vérifier votre connexion.',
    'error.config'           => 'Le fichier de configuration est invalide. Veuillez contacter l’administrateur.',
    'error.auth'             => 'Échec de l’authentification. Veuillez vous reconnecter.',
);
