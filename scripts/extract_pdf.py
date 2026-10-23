"""Extract page-aware structure from a PDF as JSON for the Laravel ingestion pipeline.

Usage: python extract_pdf.py <pdf> [--superscripts]

Output (stdout, UTF-8 JSON):
{
  "page_count": int,
  "toc": [{"level", "title", "page"}],            # PDF bookmarks, 1-based pages
  "pages": [{
      "page": int,                                 # 1-based PDF page
      "printed": str|null,                         # page number printed on the page
      "blocks": [{"x0","y0","x1","y1","size","bold","zone","text"}],   # reading order
                                                   # zone: header | footer | body
      "rows":   [{"y","x0","text","cells":[{"x0","x1","text"}]}] # y-clustered visual rows
  }]
}

Reading order is left column then right column (centre-split), as in the original
Python prototype, but full-width blocks (titles, tables) are kept in y order first.
`rows` rebuilds visual lines from word coordinates, which is what recovers table
rows and chart labels that block extraction scrambles.
"""
import json
import re
import sys

import pymupdf

FOOTER_MARGIN = 60
HEADER_MARGIN = 55


def clean(text):
    # Normalise ligatures / odd glyphs the PDF text layer sometimes emits.
    return (
        text.replace(" ", " ")
        .replace("ﬁ", "fi")
        .replace("ﬂ", "fl")
        .replace("ﬀ", "ff")
        .replace("’", "'")
        .strip()
    )


def block_text(block, superscripts):
    lines = []
    max_size = 0
    for line in block["lines"]:
        for span in line["spans"]:
            max_size = max(max_size, span["size"])
    sizes = []
    bold_chars = 0
    total_chars = 0
    for line in block["lines"]:
        parts = []
        line_max = max((s["size"] for s in line["spans"]), default=0)
        baseline = max((s["origin"][1] for s in line["spans"] if s["size"] == line_max), default=0)
        for span in line["spans"]:
            text = span["text"]
            if not text.strip():
                parts.append(text)
                continue
            sizes.append(span["size"])
            total_chars += len(text)
            if span["flags"] & 16:
                bold_chars += len(text)
            if (
                superscripts
                and re.fullmatch(r"\d{1,2}(,\d{1,2})*", text.strip())
                # Raised small digits are footnote markers; lowered ones are subscripts (CO2e).
                and (span["flags"] & 1 or (span["size"] < line_max * 0.8 and span["origin"][1] < baseline - 1.0))
                and parts
            ):
                text = f"[^{text.strip()}]"
            parts.append(text)
        lines.append("".join(parts).strip())
    return (
        "\n".join(line for line in lines if line),
        round(max(sizes) if sizes else 0, 1),
        bool(total_chars and bold_chars / total_chars > 0.6),
    )


def zone_of(page, y0, y1, size, text):
    """Running headers/footers are small text in the top/bottom bands."""
    if size and size >= 10:
        return "body"
    if y1 < HEADER_MARGIN and text.count("\n") < 3:
        return "header"
    if y0 > page.rect.height - FOOTER_MARGIN:
        return "footer"
    return "body"


def printed_number(blocks):
    """The page number printed in a running header/footer, if any."""
    for block in blocks:
        if block["zone"] == "body":
            continue
        for line in block["text"].splitlines():
            line = line.strip()
            match = (
                re.fullmatch(r"(\d{1,3}|[ivxlc]{1,7})", line, re.I)
                or re.match(r"(\d{1,3})\s+2\s*0\s*\d\s*\d", line)
                or re.search(r"[|]\s.*?\s(\d{1,3})$", line)
                or re.search(r"\|.*\|\s*(\d{1,3})$", line)
            )
            if match:
                return match.group(1)
    return None


def ordered_blocks(page, superscripts):
    width = page.rect.width
    middle = width / 2
    raw = page.get_text("dict")["blocks"]
    items = []
    for block in raw:
        if block["type"] != 0:
            continue
        text, size, bold = block_text(block, superscripts)
        text = clean(text)
        if not text:
            continue
        x0, y0, x1, y1 = block["bbox"]
        items.append(
            {
                "x0": round(x0, 1),
                "y0": round(y0, 1),
                "x1": round(x1, 1),
                "y1": round(y1, 1),
                "size": size,
                "bold": bold,
                "zone": zone_of(page, y0, y1, size, text),
                "text": text,
            }
        )

    full, left, right = [], [], []
    for item in items:
        spans_both = item["x0"] < middle - 20 and item["x1"] > middle + 20
        if spans_both and (item["x1"] - item["x0"]) > width * 0.6:
            full.append(item)
        elif (item["x0"] + item["x1"]) / 2 < middle:
            left.append(item)
        else:
            right.append(item)
    for group in (full, left, right):
        group.sort(key=lambda i: (i["y0"], i["x0"]))
    # Full-width blocks above the first column block read first, the rest after.
    column_top = min([i["y0"] for i in left + right], default=1e9)
    head = [i for i in full if i["y0"] <= column_top]
    tail = [i for i in full if i["y0"] > column_top]
    return head + left + right + tail


def visual_rows(page):
    """Cluster words into visual rows by y position, ordered left-to-right."""
    words = page.get_text("words")
    words.sort(key=lambda w: (round((w[1] + w[3]) / 2), w[0]))
    rows = []
    for w in words:
        x0, y0, x1, y1, text = w[:5]
        mid = (y0 + y1) / 2
        if rows and abs(rows[-1]["y"] - mid) <= 3:
            rows[-1]["words"].append((x0, x1, text))
            rows[-1]["y"] = (rows[-1]["y"] + mid) / 2
        else:
            rows.append({"y": mid, "words": [(x0, x1, text)]})

    out = []
    for row in rows:
        row["words"].sort()
        cells = []
        for x0, x1, text in row["words"]:
            # Words closer than ~6pt belong to the same cell.
            if cells and x0 - cells[-1]["x1"] < 6:
                cells[-1]["text"] += " " + text
                cells[-1]["x1"] = x1
            else:
                cells.append({"x0": x0, "x1": x1, "text": text})
        out.append(
            {
                "y": round(row["y"], 1),
                "x0": round(row["words"][0][0], 1),
                "text": clean(" ".join(w[2] for w in row["words"])),
                "cells": [
                    {"x0": round(c["x0"], 1), "x1": round(c["x1"], 1), "text": clean(c["text"])}
                    for c in cells
                ],
            }
        )
    return out


def main():
    if len(sys.argv) < 2:
        print("usage: extract_pdf.py <pdf> [--superscripts]", file=sys.stderr)
        sys.exit(2)
    path = sys.argv[1]
    superscripts = "--superscripts" in sys.argv

    doc = pymupdf.open(path)
    toc = [
        {"level": level, "title": re.sub(r"\s+", " ", title.replace("--", "")).strip(), "page": page}
        for level, title, page in doc.get_toc()
    ]
    pages = []
    for number, page in enumerate(doc, start=1):
        blocks = ordered_blocks(page, superscripts)
        pages.append(
            {
                "page": number,
                "printed": printed_number(blocks),
                "blocks": blocks,
                "rows": visual_rows(page),
            }
        )
    payload = {"page_count": len(doc), "toc": toc, "pages": pages}
    sys.stdout.reconfigure(encoding="utf-8")
    json.dump(payload, sys.stdout, ensure_ascii=False)


if __name__ == "__main__":
    main()
