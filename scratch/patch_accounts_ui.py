from pathlib import Path

p = Path('resources/js/views/finance/AccountsIndex.vue')
text = p.read_text(encoding='utf-8')
start = text.index('      <!-- OLD_DASHBOARD_START -->')
end = text.index('      <!-- Main Accounts Ledger -->')

new_middle = Path('scratch/accounts_ui_middle.html').read_text(encoding='utf-8')
text = text[:start] + new_middle + text[end:]
p.write_text(text, encoding='utf-8')
print('patched middle')
