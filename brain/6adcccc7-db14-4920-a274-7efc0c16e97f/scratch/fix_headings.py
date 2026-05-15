import os
import re

directory = r'c:\travile\SafarakEalayna\resources\js\views'
pattern = re.compile(r'text-4xl font-extrabold')
replacement = 'text-2xl sm:text-3xl md:text-4xl font-extrabold'

for root, dirs, files in os.walk(directory):
    for file in files:
        if file.endswith('.vue'):
            path = os.path.join(root, file)
            with open(path, 'r', encoding='utf-8') as f:
                content = f.read()
            
            new_content = pattern.sub(replacement, content)
            
            if new_content != content:
                with open(path, 'w', encoding='utf-8') as f:
                    f.write(new_content)
                print(f'Updated: {path}')
