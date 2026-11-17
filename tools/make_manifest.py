import hashlib, json, os

out = []
for root, dirs, files in os.walk('.'):
    for f in files:
        p = os.path.join(root, f)[2:].replace(os.sep, '/')
        if p == 'manifest.sha256.json':
            continue
        with open(p, 'rb') as fh:
            out.append({'path': p, 'sha256': hashlib.sha256(fh.read()).hexdigest()})

with open('manifest.sha256.json', 'w') as fh:
    json.dump(out, fh)

print(len(out), 'files hashed')