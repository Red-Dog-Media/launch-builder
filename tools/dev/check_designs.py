#!/usr/bin/env python3
"""check_designs.py: fetch the sample pages from the dev server and report what the Design hub placed on each."""
import re, subprocess, sys, html as H
BASE = 'http://127.0.0.1:8899'
PAGES = {
    '/': 'home', '/about/': 'page', '/contact/': 'conversion', '/air-conditioning/': 'service', '/air-conditioning/repair/': 'service',
    '/service-areas/': 'areas_hub', '/service-areas/rockledge-fl/': 'area', '/service-areas/rockledge-fl/installation-and-replacement/': 'area',
}
EXPECT_LIST = {
    '/air-conditioning/': ['Installation and Replacement', 'Repair', 'Maintenance'],
    '/air-conditioning/repair/': ['Installation and Replacement', 'Maintenance'],
    '/service-areas/': ['Rockledge, FL', 'Bayside, FL'],
    '/service-areas/rockledge-fl/': ['Installation and Replacement in Rockledge, FL', 'Repair in Rockledge, FL'],
    '/service-areas/rockledge-fl/installation-and-replacement/': ['Repair in Rockledge, FL'],
}
def get(p):
    r = subprocess.run(['curl', '-s', '-w', '\n%{http_code}', '--max-time', '60', BASE + p], capture_output=True, text=True)
    body, _, code = r.stdout.rpartition('\n')
    return int(code or 0), body
bad = 0
for p, role in PAGES.items():
    code, h = get(p)
    els = sorted(set(re.findall(r'<!-- \[element-(\d+)\] -->', h)))
    h1 = len(re.findall(r'<h1[\s>]', h))
    crumbs = bool(re.search(r'kadence-breadcrumbs|rank-math-breadcrumb', h))
    lists = len(re.findall(r'wp-block-kadence-query-card', h))
    unresolved = sorted(set(re.findall(r'\{\{[a-z_.]+\}\}', h)))
    text = H.unescape(re.sub(r'<[^>]+>', ' ', h))
    miss = [t for t in EXPECT_LIST.get(p, []) if t not in text]
    flags = []
    if code != 200: flags.append(f'HTTP {code}')
    if h1 != 1: flags.append(f'{h1} H1')
    if (role == 'home') == crumbs: flags.append('crumbs ' + ('on home' if crumbs else 'missing'))
    if miss: flags.append('list missing ' + ', '.join(miss))
    if unresolved: flags.append('tokens ' + ' '.join(unresolved))
    bad += bool(flags)
    print(f"{p:62} {role:10} el={','.join(els) or '-':14} h1={h1} crumbs={'y' if crumbs else 'n'} lists={lists} {'OK' if not flags else 'FAIL: ' + '; '.join(flags)}")
sys.exit(1 if bad else 0)
