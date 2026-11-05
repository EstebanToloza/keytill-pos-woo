# Fuentes incluidas

Auto-hospedadas a propósito: WordPress.org no permite que un plugin cargue
recursos externos, y traerlas del CDN de Google expondría la IP de cada
visitante (GDPR). Se declaran en `readme.txt` como recursos incluidos.

| Familia | Licencia | Origen |
|---|---|---|
| Inter | SIL Open Font License 1.1 — `LICENSE-Inter.txt` | https://github.com/google/fonts/tree/main/ofl/inter |
| Roboto Mono | SIL Open Font License 1.1 — `LICENSE-RobotoMono.txt` | https://github.com/google/fonts/tree/main/ofl/robotomono |

Son los woff2 **variables**: un archivo cubre todos los pesos de la familia.
Están partidos por subset (latin, latin-ext, cyrillic, greek, vietnamese) y
`tokens.css` los declara con su `unicode-range`, así el navegador descarga
solo el subset que el idioma de la tienda necesita.

Para actualizarlas: pedir el CSS a Google con un user-agent moderno
(devuelve woff2), bajar los archivos de cada subset y regenerar los
`@font-face` de `assets/css/tokens.css` con las mismas rutas relativas.
