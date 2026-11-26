#!/usr/bin/env bash
# Incorpora `main` a una rama de edición con Meld (coordinación, LIA-130).
#
# El editor resuelve los conflictos del propio material. Este script es para
# lo que el editor manda a coordinación («conflict_other»: chocan otros
# ficheros o el material se retiró de main) o para revisar una rama a mano.
#
# Uso, desde un clon del repositorio de contenidos con Meld instalado:
#   scripts/incorporar-main.sh edicion/ana/materiales-ut03-mvc-ut03-mvc [--push]
#
# Fusiona origin/main en la rama; lo que no choca se fusiona solo y los
# conflictos se abren en Meld a tres paneles (rama | resultado | main). Sin
# --push, deja el commit de fusión en local para revisarlo antes de subirlo.
set -euo pipefail

rama="${1:-}"
push="${2:-}"
if [[ -z "$rama" || "$rama" == -* ]]; then
  echo "Uso: $0 <rama-de-edición> [--push]" >&2
  exit 2
fi
if [[ "$rama" == "main" ]]; then
  echo "La rama principal no se fusiona consigo misma." >&2
  exit 2
fi
command -v meld >/dev/null || { echo "Falta Meld (p. ej. apt install meld)." >&2; exit 1; }
git rev-parse --is-inside-work-tree >/dev/null
if [[ -n "$(git status --porcelain)" ]]; then
  echo "Hay cambios sin commit en el clon: guárdalos o descártalos antes." >&2
  exit 1
fi

git fetch origin
if git show-ref --verify --quiet "refs/heads/$rama"; then
  git switch "$rama"
  if ! git merge --ff-only "origin/$rama"; then
    echo "La rama local y la de origin han divergido (¿una fusión anterior sin --push?)." >&2
    echo "Súbela (git push origin $rama) o descártala (git reset --hard origin/$rama)." >&2
    exit 1
  fi
else
  git switch --track -c "$rama" "origin/$rama"
fi

if git merge --no-edit -m "Incorpora la versión oficial (main) a $rama" origin/main; then
  echo "Fusionada sin conflictos."
else
  echo "Hay conflictos: se abren en Meld (rama | resultado | main)."
  git mergetool --tool=meld --no-prompt
  if git diff --name-only --diff-filter=U | grep -q .; then
    echo "Quedan conflictos sin resolver; la fusión sigue a medias (git merge --abort para deshacerla)." >&2
    exit 1
  fi
  git commit --no-edit
fi

git log --oneline -1
if [[ "$push" == "--push" ]]; then
  git push origin "$rama"
else
  echo "Revisa el resultado y súbelo con: git push origin $rama"
fi
