#!/bin/bash
# ============================================
# Genera un archivo con el contenido de todos los PHP
# ============================================

PROYECTO="/home/silentroot/Desktop/desarrollo_software"
OUTPUT=~/proyecto_dump.txt

# Borrar el archivo si existe
> "$OUTPUT"

echo "===============================================" >> "$OUTPUT"
echo "  DUMP DEL PROYECTO GANADERIA EL ROSARIO" >> "$OUTPUT"
echo "  Fecha: $(date)" >> "$OUTPUT"
echo "===============================================" >> "$OUTPUT"
echo "" >> "$OUTPUT"

# 1. Includes
echo "===============================================" >> "$OUTPUT"
echo "  INCLUDES/" >> "$OUTPUT"
echo "===============================================" >> "$OUTPUT"
for f in "$PROYECTO/includes"/*.php; do
    if [ -f "$f" ]; then
        echo "" >> "$OUTPUT"
        echo "--- ARCHIVO: $f ---" >> "$OUTPUT"
        echo "" >> "$OUTPUT"
        cat "$f" >> "$OUTPUT"
        echo "" >> "$OUTPUT"
    fi
done

# 2. Modules
for dir in "$PROYECTO/modules"/*/; do
    MODULO=$(basename "$dir")
    # Saltar carpetas no relevantes
    if [ "$MODULO" = "assets" ] || [ "$MODULO" = "modules" ]; then
        continue
    fi
    
    echo "" >> "$OUTPUT"
    echo "===============================================" >> "$OUTPUT"
    echo "  MODULES/$MODULO/" >> "$OUTPUT"
    echo "===============================================" >> "$OUTPUT"
    
    for f in "$dir"*.php; do
        if [ -f "$f" ]; then
            echo "" >> "$OUTPUT"
            echo "--- ARCHIVO: $f ---" >> "$OUTPUT"
            echo "" >> "$OUTPUT"
            cat "$f" >> "$OUTPUT"
            echo "" >> "$OUTPUT"
        fi
    done
done

# 3. Database
echo "" >> "$OUTPUT"
echo "===============================================" >> "$OUTPUT"
echo "  DATABASE/" >> "$OUTPUT"
echo "===============================================" >> "$OUTPUT"
for f in "$PROYECTO/database"/*.sql; do
    if [ -f "$f" ]; then
        echo "" >> "$OUTPUT"
        echo "--- ARCHIVO: $f ---" >> "$OUTPUT"
        echo "" >> "$OUTPUT"
        cat "$f" >> "$OUTPUT"
        echo "" >> "$OUTPUT"
    fi
done

echo ""
echo "==============================================="
echo "  DUMP COMPLETO"
echo "==============================================="
echo ""
echo "Archivo generado: $OUTPUT"
echo "Tamaño: $(wc -l < "$OUTPUT") líneas"
echo "Tamaño: $(du -h "$OUTPUT" | cut -f1)"
echo ""
echo "Para verlo:"
echo "  cat $OUTPUT"
echo ""
echo "Para copiarlo a un archivo y subirlo:"
echo "  cp $OUTPUT ~/Desktop/dump_proyecto.txt"
