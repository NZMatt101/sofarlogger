#!/usr/bin/env python3
"""Build the formatted user manual (web/guide.html) from docs/user-guide.md.

    python tools/build-guide.py

The Markdown file is the one to edit; run this afterwards and commit both. Pictures live in web/guide/img/, so the
same files serve GitHub (docs/user-guide.md) and the Pi (guide.html). Only the Markdown this guide uses is
supported: headings, paragraphs, lists, tables, pictures, quotes, bold, italics, code and links.
"""
import html
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, "docs", "user-guide.md")
OUT = os.path.join(ROOT, "web", "guide.html")
IMG_FROM, IMG_TO = "../web/guide/img/", "guide/img/"


def slug(text):
    """GitHub-style heading anchor, so links written for GitHub work here too."""
    s = re.sub(r"[^\w\- ]", "", text.lower().strip())
    return s.replace(" ", "-")


def inline(text):
    """Escape, then bold, italics, code and links."""
    codes = []
    text = re.sub(r"`([^`]+)`", lambda m: codes.append(m.group(1)) or f"\x00{len(codes) - 1}\x00", text)
    text = html.escape(text, quote=False)
    text = re.sub(r"\*\*(.+?)\*\*", r"<strong>\1</strong>", text)
    text = re.sub(r"(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])", r"<em>\1</em>", text)

    def link(m):
        label, url = m.group(1), m.group(2)
        url = url.replace(IMG_FROM, IMG_TO)
        if url.endswith(".md") or ".md#" in url:          # other docs: point at the repository copy
            url = "https://github.com/NZMatt101/sofarlogger/blob/main/docs/" + url
        ext = ' target="_blank" rel="noopener"' if url.startswith("http") else ""
        return f'<a href="{html.escape(url)}"{ext}>{label}</a>'
    text = re.sub(r"\[([^\]]+)\]\(([^)]+)\)", link, text)
    return re.sub(r"\x00(\d+)\x00", lambda m: f"<code>{html.escape(codes[int(m.group(1))])}</code>", text)


def convert(md):
    lines = md.splitlines()
    out, toc, title = [], [], "User guide"
    i = 0

    def para(buf):
        if buf:
            out.append(f"<p>{inline(' '.join(s.strip() for s in buf))}</p>")
        buf.clear()

    buf = []
    while i < len(lines):
        line = lines[i]
        s = line.strip()
        m = re.match(r"^(#{1,4}) (.+)$", line)
        if m:
            para(buf)
            level, text = len(m.group(1)), m.group(2).strip()
            if level == 1:
                title = text
            else:
                anchor = slug(text)
                if level == 2 and text != "Contents":
                    toc.append((anchor, text))
                cls = ' class="contents-head"' if text == "Contents" else ""
                out.append(f'<h{level} id="{anchor}"{cls}>{inline(text)}</h{level}>')
            i += 1
        elif s == "---":
            para(buf)
            i += 1
        elif not s:
            para(buf)
            i += 1
        elif s.startswith("|"):
            para(buf)
            rows = []
            while i < len(lines) and lines[i].strip().startswith("|"):
                rows.append([c.strip() for c in lines[i].strip().strip("|").split("|")])
                i += 1
            head, body = rows[0], rows[2:]
            cells = lambda r, t: "".join(f"<{t}>{inline(c)}</{t}>" for c in r)
            out.append('<div class="table-wrap"><table><thead><tr>' + cells(head, "th") + "</tr></thead><tbody>"
                       + "".join("<tr>" + cells(r, "td") + "</tr>" for r in body) + "</tbody></table></div>")
        elif re.match(r"^!\[[^\]]*\]\([^)]+\)$", s):
            para(buf)
            alt, src = re.match(r"^!\[([^\]]*)\]\(([^)]+)\)$", s).groups()
            src = src.replace(IMG_FROM, IMG_TO)
            out.append(f'<figure><a href="{html.escape(src)}" target="_blank"><img src="{html.escape(src)}" '
                       f'alt="{html.escape(alt)}" loading="lazy"></a><figcaption>{html.escape(alt)}</figcaption></figure>')
            i += 1
        elif s.startswith("<img "):
            para(buf)
            tag = s.replace(IMG_FROM, IMG_TO)
            alt = re.search(r'alt="([^"]*)"', tag)
            out.append(f'<figure class="narrow">{tag}<figcaption>{alt.group(1) if alt else ""}</figcaption></figure>')
            i += 1
        elif s.startswith(">"):
            para(buf)
            quote = []
            while i < len(lines) and lines[i].strip().startswith(">"):
                quote.append(lines[i].strip()[1:].strip())
                i += 1
            text = " ".join(quote)
            kind = "warn" if text.lower().startswith("**safety") else "tip"
            out.append(f'<aside class="note {kind}">{inline(text)}</aside>')
        elif re.match(r"^(\d+\.|-) ", s):
            para(buf)
            ordered = bool(re.match(r"^\d+\.", s))
            items = []
            while i < len(lines):
                l = lines[i]
                if re.match(r"^(\d+\.|-) ", l.strip()) and not l.startswith("   "):
                    items.append(re.sub(r"^(\d+\.|-) ", "", l.strip()))
                elif l.startswith("  ") and l.strip() and items:   # continuation of the item above
                    items[-1] += " " + l.strip()
                else:
                    break
                i += 1
            tag = "ol" if ordered else "ul"
            cls = ' class="contents"' if out and 'class="contents-head"' in out[-1] else ""
            out.append(f"<{tag}{cls}>" + "".join(f"<li>{inline(t)}</li>" for t in items) + f"</{tag}>")
        else:
            buf.append(s)
            i += 1
    para(buf)
    return title, toc, "\n".join(out)


PAGE = """<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{title}</title>
<link rel="stylesheet" href="style.css?v=3">
<style>
  /* Manual layout: contents down the side on wide screens, one column on phones; prints cleanly. */
  .manual {{ display: grid; grid-template-columns: 230px minmax(0, 780px); gap: 32px; justify-content: center; }}
  .toc {{ position: sticky; top: 16px; align-self: start; max-height: calc(100vh - 32px); overflow: auto;
          font-size: 14px; padding: 14px 16px; }}
  .toc h2 {{ font-size: 13px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); margin: 0 0 8px; }}
  .toc ol {{ margin: 0; padding-left: 20px; }}
  .toc li {{ margin: 5px 0; }}
  .toc a {{ color: var(--text); text-decoration: none; }}
  .toc a:hover, .toc a.here {{ color: var(--link, #2b6cb0); }}
  .toc a.here {{ font-weight: 600; }}
  article {{ line-height: 1.6; font-size: 16px; padding: 8px 28px 28px; }}
  article h2 {{ font-size: 24px; margin: 40px 0 12px; padding-top: 16px; border-top: 1px solid var(--line); }}
  article h2:first-of-type {{ border-top: 0; }}
  article h3 {{ font-size: 19px; margin: 28px 0 8px; }}
  article h4 {{ font-size: 16px; margin: 22px 0 6px; padding: 6px 10px; border-left: 4px solid #e8a317;
                background: color-mix(in srgb, #e8a317 10%, transparent); border-radius: 0 6px 6px 0; }}
  article p {{ margin: 10px 0; }}
  article ol, article ul {{ padding-left: 24px; }}
  article li {{ margin: 6px 0; }}
  article ol > li::marker {{ font-weight: 700; color: #e8a317; }}
  .lead {{ font-size: 18px; color: var(--muted); margin-top: 0; }}
  figure {{ margin: 18px 0; }}
  figure img {{ max-width: 100%; height: auto; border: 1px solid var(--line); border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, .08); display: block; }}
  figure.narrow img {{ max-width: 300px; }}
  figcaption {{ color: var(--muted); font-size: 13px; margin-top: 6px; }}
  .table-wrap {{ overflow-x: auto; margin: 14px 0; }}
  article table {{ border-collapse: collapse; width: 100%; font-size: 15px; }}
  article th, article td {{ border: 1px solid var(--line); padding: 8px 10px; text-align: left; vertical-align: top; }}
  article th {{ background: var(--hover, #f0f3f7); }}
  article code {{ background: var(--hover, #f0f3f7); padding: 1px 5px; border-radius: 4px; font-size: 90%; }}
  .note {{ margin: 16px 0; padding: 12px 16px; border-radius: 8px; border-left: 5px solid #3b7dd8;
           background: color-mix(in srgb, #3b7dd8 9%, transparent); }}
  .note.warn {{ border-left-color: #c0392b; background: color-mix(in srgb, #c0392b 9%, transparent); }}
  .contents-head, ol.contents {{ display: none; }}   /* the side panel replaces it on wide screens */
  .top-link {{ display: inline-block; margin-top: 24px; font-size: 14px; }}
  @media (max-width: 980px) {{
    .manual {{ grid-template-columns: minmax(0, 1fr); }}
    .toc {{ display: none; }}
    .contents-head, ol.contents {{ display: block; }}
    article {{ padding: 4px 18px 20px; font-size: 15.5px; }}
  }}
  @media print {{
    header nav, .toc, .top-link, #connBar {{ display: none !important; }}
    .manual {{ display: block; }}
    .contents-head, ol.contents {{ display: block; }}
    article {{ box-shadow: none; border: 0; padding: 0; }}
    article h2 {{ break-before: page; border-top: 0; }}
    article h2.contents-head {{ break-before: auto; }}
    figure, table, .note {{ break-inside: avoid; }}
    a {{ color: inherit; text-decoration: none; }}
  }}
</style>
</head>
<body>
<header>
  <h1>📘 {title}</h1>
  <nav class="top"><a href="index.html">Overview</a> · <a href="savings.html">Savings</a> · <a href="green.html">🌿 Going Green</a> · <a href="admin/admin.php">Admin</a> · <a href="#" onclick="print(); return false">Print</a></nav>
</header>
<main class="manual">
  <nav class="card toc" aria-label="Contents">
    <h2>Contents</h2>
    <ol>
{toc}
    </ol>
  </nav>
  <article class="card">
{body}
    <a class="top-link" href="#top">↑ Back to the top</a>
  </article>
</main>
<script>
// Highlight the section being read in the side panel.
const links = [...document.querySelectorAll('.toc a')];
const heads = links.map(a => document.getElementById(a.getAttribute('href').slice(1)));
const mark = () => {{
  let cur = 0;
  heads.forEach((h, n) => {{ if (h && h.getBoundingClientRect().top < 120) cur = n; }});
  links.forEach((a, n) => a.classList.toggle('here', n === cur));
}};
addEventListener('scroll', mark, {{ passive: true }});
mark();
</script>
</body>
</html>
"""


def main():
    md = open(SRC, encoding="utf-8").read()
    title, toc, body = convert(md)
    # The opening paragraphs read as an introduction.
    body = body.replace("<p>", '<p class="lead">', 1)
    toc_html = "\n".join(f'      <li><a href="#{a}">{inline(re.sub(r"^\d+\. ", "", t))}</a></li>' for a, t in toc)
    page = PAGE.format(title=html.escape(title), toc=toc_html, body=body)
    page = page.replace("<body>", '<body id="top">', 1)
    with open(OUT, "w", encoding="utf-8", newline="\n") as f:
        f.write(page)
    print(f"wrote {os.path.relpath(OUT, ROOT)}: {len(toc)} sections")


if __name__ == "__main__":
    main()
