# Restyle the imported claude.ai "Floorboard Brief" onto Broadside's Harbor tokens (agent-side, per D8).
import re, sys
src, dst = sys.argv[1], sys.argv[2]
h = open(src, encoding='utf-8').read()
# 1. Drop the claude.ai reader shell: its first <style> (white page, -apple-system) and its viewport meta.
h = re.sub(r'<style>:root\{color-scheme:light;.*?</style>', '', h, count=1, flags=re.S)
h = re.sub(r'<meta name="claude:viewport-fit-sides"[^>]*>\n?', '', h)
# 2. Suite look: system fonts, so the Google Fonts link goes.
h = re.sub(r'<link rel="stylesheet" href="https://fonts\.googleapis\.com[^>]*>\n?', '', h)
# 3. Rename page variables that shadow Harbor token names (--accent, --muted).
h = re.sub(r'--accent(?![-\w])', '--brand', h)
h = h.replace('--accent-soft', '--brand-soft')
h = re.sub(r'--muted(?![-\w])', '--subtle', h)
# 4. Replace the three palette blocks (light :root, dark media query, data-theme dark) with one alias block.
start = h.index(':root {\n  --bg:')
end = h.index('body { background: var(--bg)')
alias = (":root {\n  --bg: var(--background); --surface: var(--card); --fg: var(--foreground); --subtle: var(--muted-foreground);\n"
         "  --line: var(--border); --brand: var(--primary); --brand-soft: var(--secondary); --root: var(--destructive-text); --ok: var(--success);\n"
         "}\n")
h = h[:start] + alias + h[end:]
# Fonts: the suite's inherited face for text, a generic monospace for code (no named fonts).
h = h.replace('font-family: var(--display)', 'font-family: inherit').replace('font-family: var(--mono)', 'font-family: ui-monospace, monospace')
h = h.replace('var(--display)', 'inherit').replace('var(--body)', 'inherit').replace('var(--mono)', 'ui-monospace, monospace')
h = h.replace('font: 16px/1.6 inherit', 'font-size: 16px; line-height: 1.6')
open(dst, 'w', encoding='utf-8').write(h)
left = re.findall(r'#[0-9a-fA-F]{3,8}\b', h)
print('hex colours left:', left)
