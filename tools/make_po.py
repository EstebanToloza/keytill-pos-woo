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

pot = [head('')]
po  = [head('es_ES')]
for en in sorted(cat):
    pot.append(f'\nmsgid "{esc(en)}"\nmsgstr ""\n')
    po.append(f'\nmsgid "{esc(en)}"\nmsgstr "{esc(cat[en])}"\n')
open(os.path.join(ROOT, 'languages', 'keytill.pot'), 'w', encoding='utf-8').write(''.join(pot))
open(os.path.join(ROOT, 'languages', 'keytill-es_ES.po'), 'w', encoding='utf-8').write(''.join(po))
print(f"pot + po generados: {len(cat)} mensajes")
