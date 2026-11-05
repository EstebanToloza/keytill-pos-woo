#!/usr/bin/env python3
"""Genera languages/keytill.pot y keytill-es_ES.po desde tools/catalog.json."""
import json, os, datetime
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
cat = json.load(open(os.path.join(ROOT, 'tools', 'catalog.json'), encoding='utf-8'))

def esc(s):
    return s.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n')

now = datetime.datetime.now().strftime('%Y-%m-%d %H:%M%z')
head = lambda lang: f'''msgid ""
msgstr ""
"Project-Id-Version: Keytill POS\\n"
"Report-Msgid-Bugs-To: https://github.com/EstebanToloza/keytill-pos-woo\\n"
"POT-Creation-Date: {now}\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"Language: {lang}\\n"
"Plural-Forms: nplurals=2; plural=(n != 1);\\n"
'''

# Pares singular->plural de las llamadas _n(). En formato PO un plural es UNA
# entrada con msgid + msgid_plural + msgstr[0] + msgstr[1]; como dos entradas
# sueltas, gettext no resuelve la forma plural.
PLURALS = os.path.join(ROOT, 'tools', 'plurals.json')
plurals = json.load(open(PLURALS, encoding='utf-8')) if os.path.exists(PLURALS) else {}
consumed = {k for pair in plurals.items() for k in pair if k in cat}

pot = [head('')]
po  = [head('es_ES')]
for sing, plur in sorted(plurals.items()):
    if sing not in cat or plur not in cat:
        continue
    pot.append(f'\nmsgid "{esc(sing)}"\nmsgid_plural "{esc(plur)}"\n'
               'msgstr[0] ""\nmsgstr[1] ""\n')
    po.append(f'\nmsgid "{esc(sing)}"\nmsgid_plural "{esc(plur)}"\n'
              f'msgstr[0] "{esc(cat[sing])}"\nmsgstr[1] "{esc(cat[plur])}"\n')
for en in sorted(cat):
    if en in consumed:
        continue
    pot.append(f'\nmsgid "{esc(en)}"\nmsgstr ""\n')
    po.append(f'\nmsgid "{esc(en)}"\nmsgstr "{esc(cat[en])}"\n')
open(os.path.join(ROOT, 'languages', 'keytill.pot'), 'w', encoding='utf-8').write(''.join(pot))
open(os.path.join(ROOT, 'languages', 'keytill-es_ES.po'), 'w', encoding='utf-8').write(''.join(po))
print(f"pot + po generados: {len(cat)} mensajes")
