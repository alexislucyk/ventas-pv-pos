# Versionado de Código — Tags y Releases (GitHub)

> Guía para publicar versiones del sistema y cómo se relaciona con el
> módulo de **Actualizaciones** (`/actualizaciones`).

---

## 1. Conceptos: Tag vs Release

- **Tag**: una etiqueta simple que apunta a un commit (solo un nombre + referencia).
  Se crea localmente con `git tag` y se sube con `git push`.
- **Release**: es un tag publicado en GitHub con metadatos (título, descripción /
  "changelog", notas, adjuntos). **Un Release siempre crea un tag subyacente.**

Cuando la página `/actualizaciones` consulta
`api.github.com/repos/.../releases/latest`, obtiene un **Release**.

### Recomendación

El repositorio (`alexislucyk/ventas-pv-pos`) detecta actualizaciones en este orden:

1. **Releases** (`releases/latest`) → usa la última release.
2. **Tags** (`tags`) → si no hay releases, usa la última tag.
3. **Commits** → si no hay tags publicados, compara `HEAD` local contra `origin/main`.

Por eso, **publicar Releases** (con el número de versión en el tag) es lo más
limpio y visible, y activa automáticamente la detección **por versión**.

---

## 2. Cómo crear una versión

### Opción A — Desde GitHub web (más fácil y visual)

1. Entrar al repositorio: `https://github.com/alexislucyk/ventas-pv-pos`
2. Panel derecho → **Create a new release** (o pestaña **Releases** → **Draft a new release**).
3. **Choose a tag**: escribir `v2.7.0` y elegir **"Create new tag"** (o elegir un tag existente).
4. **Target**: `main` (o el commit/rama con el código nuevo).
5. Escribir un título (ej. `v2.7.0`) y la descripción de cambios (opcional).
6. **Publish release**.

> 💡 **Importante para el módulo de actualizaciones:** el tag debe llevar el
> número con la `v` (ej. `v2.7.0`). El código ya la limpia
> (`actualizaciones_normalizar_version()`), así que `v2.7.0` se compara
> correctamente contra el `app_version` de la base de datos (`2.6.0`).

### Opción B — Por línea de comandos (git)

```bash
# 1. Asegurarse de tener el código nuevo commitado y pusheado
git add -A
git commit -m "Mejoras X y Y"
git push origin main

# 2. Crear y subir un tag (anotado, con mensaje)
git tag -a v2.7.0 -m "Versión 2.7.0"
git push origin v2.7.0

# 3. (Opcional pero recomendado) Crear el Release desde GitHub web,
#    eligiendo ahora ese tag ya existente.
```

#### Comandos útiles

```bash
git tag -n              # listar tags con mensaje
git log --oneline -5    # ver últimos commits antes de versionar
git push origin main    # garantizar que el código está publicado
```

---

## 3. Convención sugerida (Versionado Semántico)

- `vMAYOR.MENOR.PARCHE`
  - **MAYOR**: cambios que rompen compatibilidad.
  - **MENOR**: nuevas funcionalidades (compatible).
  - **PARCHE**: corrección de errores.

### Ejemplos

| Tipo de cambio           | Versión |
|--------------------------|---------|
| Versión actual           | `2.6.0` |
| Nueva funcionalidad      | `v2.7.0` |
| Corrección de error      | `v2.6.1` |
| Cambio incompatible      | `v3.0.0` |

---

## 4. Relación con el módulo de Actualizaciones

- El módulo `/actualizaciones` consulta GitHub y compara la **versión local** con
  la **última release/tag**.
- Al detectar una versión nueva, permite aplicar la actualización (respaldo de BD
  + `git` + migraciones) y actualiza el campo `app_version` en la BD.
- Hasta que no exista ninguna release/tag, la detección se hace **por commits**
  (comparando `HEAD` local contra `origin/main`), sin cambiar el `app_version`.

> Al publicar el primer **Release** con tag `vX.Y.Z`, el sistema pasa a detectar
> por **versión** automáticamente (tiene prioridad sobre el método por commits).