import os
import subprocess
import sys
from datetime import datetime

# ─────────────────────────────────────────────────────────
# CONFIGURATION
# ─────────────────────────────────────────────────────────
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
PHP_SCRIPT = os.path.join(BASE_DIR, "generate_dataset_hybrid.php")
ML_SCRIPT  = os.path.join(BASE_DIR, "svd_recommender_v4_personalized.py")

# Fichiers modifiés par l'entraînement qui doivent être envoyés en production
FILES_TO_COMMIT = [
    "interactions_hybrid.csv",
    "svd_model_v4.pkl"
]

def run_command(command, description):
    print(f"\n[{datetime.now().strftime('%H:%M:%S')}] ⏳ {description}...")
    try:
        # Exécute la commande et affiche la sortie en temps réel
        subprocess.run(command, shell=True, check=True)
        print(f"[{datetime.now().strftime('%H:%M:%S')}] ✅ Terminé avec succès.")
    except subprocess.CalledProcessError as e:
        print(f"\n❌ ERREUR lors de : {description}")
        print(f"Code d'erreur : {e.returncode}")
        sys.exit(1)

# ─────────────────────────────────────────────────────────
# PIPELINE
# ─────────────────────────────────────────────────────────
if __name__ == "__main__":
    print("==================================================")
    print("🚀 DÉMARRAGE DU PIPELINE DE RÉ-ENTRAÎNEMENT CATECO")
    print("==================================================")

    # 1. Extraction des données
    run_command(f"php {PHP_SCRIPT}", "Extraction des données depuis la DB (PHP)")

    # 2. Entraînement du modèle
    # On force l'encodage UTF-8 pour éviter les erreurs Windows
    os.environ["PYTHONIOENCODING"] = "utf-8"
    run_command(f"python {ML_SCRIPT}", "Entraînement du modèle SVD V4 (Python)")

    # 3. Déploiement (Git)
    print("\n==================================================")
    print("📦 PRÉPARATION DU DÉPLOIEMENT")
    print("==================================================")
    
    commit_msg = f"Auto-retrain model - {datetime.now().strftime('%Y-%m-%d %H:%M')}"
    
    # Git add
    for file in FILES_TO_COMMIT:
        run_command(f"git add {os.path.join(BASE_DIR, file)}", f"Ajout de {file} à Git")
    
    # Vérifier s'il y a des changements à commiter
    status = subprocess.run("git status --porcelain", shell=True, capture_output=True, text=True)
    if not status.stdout.strip():
        print("\nℹ️  Aucune modification détectée. Le modèle n'a pas changé. Fin du script.")
        sys.exit(0)

    # Git commit & push
    print(f"\nSouhaitez-vous envoyer le nouveau modèle en production sur Render via GitHub ?")
    reponse = input("Tapez 'O' pour Oui, 'N' pour Non : ").strip().upper()

    if reponse == 'O':
        run_command(f'git commit -m "{commit_msg}"', "Création du commit")
        run_command("git push", "Envoi vers GitHub (déclenche Render)")
        print("\n🎉 TOUT EST BON ! Render est en train de déployer le nouveau modèle.")
    else:
        print("\n🛑 Déploiement annulé. Le nouveau modèle reste en local.")
