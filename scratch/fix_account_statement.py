#!/usr/bin/env python3
"""Repair corrupted AccountStatement.vue template."""
import re
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TARGET = ROOT / "resources/js/views/finance/AccountStatement.vue"

curr = TARGET.read_text(encoding="utf-8")
clean = subprocess.check_output(
    ["git", "show", "89c2a07:resources/js/views/finance/AccountStatement.vue"],
    cwd=ROOT,
    text=True,
    encoding="utf-8",
)

# Extract one print layout block
print_match = re.search(
    r"(      <!-- Dedicated Print Layout -->.*?      </div>\n      </div>)",
    curr,
    re.DOTALL,
)
if not print_match:
    raise SystemExit("Print layout block not found")
print_block = print_match.group(1)

# Clean print header (from git, before main content div)
clean_template = clean.split("<script setup>", 1)[0]
header_end = clean_template.index('\n    <div class="mx-auto max-w-7xl')
print_header = clean_template[:header_end]

# Main UI section from current file (statement-page improvements)
stmt_idx = curr.index('class="statement-page')
div_start = curr.rfind("<div", 0, stmt_idx)
script_idx = curr.index("<script setup>")
before_script = curr[:script_idx]

# Last complete teleport block in the good section
tele_end = before_script.rfind("</teleport>")
if tele_end == -1:
    raise SystemExit("No teleport found")

main_section = before_script[div_start : tele_end + len("</teleport>")]

# Remove any embedded corruption from main section
main_section = re.sub(
    r"\n      <!-- Dedicated Print Layout -->.*?      </div>\n      </div>\n</template>\n?",
    "\n",
    main_section,
    flags=re.DOTALL,
)
main_section = re.sub(r"^</template>\n?", "", main_section, flags=re.MULTILINE)

# Current script + style
script_and_style = curr[script_idx:]

# Assemble
fixed = (
    print_header
    + "\n\n"
    + main_section
    + "\n    </div>\n\n"
    + print_block
    + "\n  </div>\n</template>\n\n"
    + script_and_style
)

# Validate
if fixed.count("</template>") != fixed.count("<template"):
    # inner templates balance roughly - check root
    root_closes = len(re.findall(r"^</template>", fixed.split("<script")[0], re.MULTILINE))
    if root_closes != 1:
        raise SystemExit(f"Expected 1 root </template>, found {root_closes}")

if "Dedicated Print Layout" not in fixed:
    raise SystemExit("Print layout missing")

if fixed.count("Dedicated Print Layout") != 1:
    raise SystemExit(
        f"Expected 1 print layout, found {fixed.count('Dedicated Print Layout')}"
    )

TARGET.write_text(fixed, encoding="utf-8")
print(f"Fixed {TARGET} ({len(fixed.splitlines())} lines)")
