#!/bin/sh
# Corre este script una vez despues de clonar el repo: sh scripts/install-hooks.sh
HOOK=".git/hooks/pre-commit"
cat > "$HOOK" << 'HOOK_CONTENT'
#!/bin/sh
echo "Verificando TypeScript..."
cd "$(git rev-parse --show-toplevel)/frontend" || exit 1
npx tsc --noEmit
if [ $? -ne 0 ]; then
  echo "TypeScript fallo. Commit cancelado."
  exit 1
fi
echo "TypeScript OK"
HOOK_CONTENT
chmod +x "$HOOK"
echo "Hook instalado en $HOOK"
