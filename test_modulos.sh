#!/bin/bash
# ============================================
# Test RBAC Completo - Sistema Ganadero
# ============================================

BASE_URL="http://ganaderia.local"

# Resetear contraseñas primero
HASH=$(php -r "echo password_hash('admin123', PASSWORD_BCRYPT, ['cost' => 12]);")
mysql -u silentroot -P 3307 -p5252 -e "
USE Ganaderia_Rosario;
UPDATE usuarios SET password = '$HASH', intentos_fallidos = 0, bloqueado_hasta = NULL;
DELETE FROM sesiones;
" 2>/dev/null

# Roles a probar
declare -A ROLES=(
    ["admin"]="admin@ganaderia.com"
    ["recepcionista"]="recepcion@ganaderia.com"
    ["veterinario"]="veterinario@ganaderia.com"
    ["clasificador"]="clasificador@ganaderia.com"
    ["alimentador"]="alimentador@ganaderia.com"
)

MODULOS="dashboard recepcion bovinos sanidad clasificacion corrales alimentacion tratamientos seguimiento costos ventas reportes trazabilidad alertas usuarios configuracion"

echo ""
echo "=============================================="
echo "🧪 TEST RBAC - SISTEMA GANADERO EL ROSARIO"
echo "=============================================="

for rol in admin recepcionista veterinario clasificador alimentador; do
    email="${ROLES[$rol]}"
    
    echo ""
    echo "=============================================="
    echo "🎭 ROL: $rol"
    echo "=============================================="
    
    # Limpiar cookies
    rm -f /tmp/gc_$rol.txt
    
    # Login
    CSRF=$(curl -s -c /tmp/gc_$rol.txt "$BASE_URL/login" | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1)
    LOGIN_CODE=$(curl -s -b /tmp/gc_$rol.txt -c /tmp/gc_$rol.txt -X POST "$BASE_URL/login" \
        -d "email=$email&password=admin123&csrf_token=$CSRF" \
        -o /dev/null -w "%{http_code}")
    
    if [ "$LOGIN_CODE" != "302" ]; then
        echo "  ❌ Login falló (HTTP $LOGIN_CODE)"
        continue
    fi
    
    echo "  ✅ Login OK"
    echo ""
    
    # Probar cada módulo
    for mod in $MODULOS; do
        code=$(curl -s -b /tmp/gc_$rol.txt -o /dev/null -w "%{http_code}" "$BASE_URL/$mod")
        
        case $code in
            200) icon="✅" ;;
            403) icon="🔒" ;;
            404) icon="❓" ;;
            302) icon="↪️ " ;;
            500) icon="💥" ;;
            *) icon="⚠️ " ;;
        esac
        
        printf "     %s %-20s HTTP %s\n" "$icon" "$mod" "$code"
    done
done

echo ""
echo "=============================================="
echo "🎯 LEYENDA"
echo "=============================================="
echo "  ✅  200 = Acceso permitido"
echo "  🔒  403 = Acceso denegado (RBAC funcionando)"
echo "  ❓  404 = Módulo no existe"
echo "  💥  500 = Error interno"
echo ""
