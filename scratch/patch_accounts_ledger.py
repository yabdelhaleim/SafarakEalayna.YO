from pathlib import Path

p = Path('resources/js/views/finance/AccountsIndex.vue')
text = p.read_text(encoding='utf-8')
start = text.index('      <!-- Main Accounts Ledger -->')
end = text.index('            <tbody class="divide-y divide-white/5">')
new_block = Path('scratch/accounts_ui_ledger.html').read_text(encoding='utf-8')
text = text[:start] + new_block + text[end:]
p.write_text(text, encoding='utf-8')
print('patched ledger')
