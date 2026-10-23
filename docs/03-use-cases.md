# 3. The three use cases

Each use case is one ingestion *profile*. The pipeline is shared; the chunker and, for the structured document, the
question router differ.

| | UC1 | UC2 | UC3 |
|---|---|---|---|
| Document | GPO 2018 Annual Report | World Bank Global Economic Prospects, June 2026 | Apple iMac Product Environmental Report |
| File | `GPO-Anual-report.pdf` | `GEP-Jun-2026.pdf` | `iMac_PER_Oct2024.pdf` |
| Pages | 82 | 200 | 12 |
| Profile | `narrative` | `structured` | `visual` |
| Chunks stored | 354 | 1139 (951 searchable + 188 section parents) | 112 |
| Pass bar (from the use-case doc) | exact fact from the paragraph, nothing added | right chapter/section, no region or chapter mix-ups | the right number from a chart, table or footnote |

---

## UC1 — Plain-text business report (`NarrativeChunker`)

**Challenge:** ordinary two-column prose with numbers inside sentences ("GPO distributed 802,834 copies of 4,211 titles").

**Approach**

* `extract_pdf.py` orders blocks column by column and marks running headers/footers, which are dropped.
* `TextCleaner::prose()` joins lines, repairs drop caps and the lost "Th" ligature, and removes bare page numbers.
* Sentences are packed into chunks of about 600 characters. If the last sentence is short enough (120 characters or fewer) it is
  repeated at the start of the next chunk so a fact is never separated from its sentence. Runs of text without punctuation
  (such as the "GPO By the Numbers" list) are split at word boundaries.
* Each chunk keeps its page range; the printed page number is used when the chunk sits on a single page.
* Retrieval is hybrid with no routing; the answer must cite the page.

---

## UC2 — Long, structured report (`StructuredChunker`, `QuestionRouter`)

**Challenge:** 200 pages in chapters, regions, boxed articles and tables. "South Asia" must never be answered from
"Sub-Saharan Africa", and "Chapter 3" must mean Chapter 3.

**Section tree**

* The PDF has 18 bookmarks (Executive Summary, Ch.1–Ch.4, boxes, the six regions, Statistical Annex).
* A bookmark can point one page early (South Asia points at PDF p.95 but the heading is on p.96). Each non-chapter bookmark is
  checked against the headings within ±1/+2 pages and corrected.
* Box pages carry a running header ("BOX 1.1 … (continued)"), so pages inside a box belong to the box and the chapter text
  that follows it goes back to the chapter. Resulting paths look like
  `Ch.2 Regional Outlooks > South Asia` or `Ch.1 Global Outlook > Box 1.1 How much will AI affect global growth?`.
* Front matter before the Executive Summary (copyright, contents lists) is skipped, so it cannot match "Chapter 3" questions.
* Page numbers: PDF page and printed page differ (South Asia starts on PDF p.96, printed page 74). Answers show
  "Page 74 (PDF p.96)".

**Chunks**

* **Children** (about 700 characters, embedded) carry a breadcrumb in their search text:
  `Ch.2 Regional Outlooks > South Asia > Outlook`. Sub-headings such as "Recent developments" and "Outlook" are detected.
* **Parents** (`section` kind, up to 3000 characters, not embedded) hold the surrounding text of each section part.
* **Tables** become their own chunks. `TableFacts` spells out each row against its year header, so the model never has to
  line up columns:

  `EMDE SAR, GDP: 2023: 6.6; 2024: 6.8; 2025e: 7.0; 2026f: 6.3; 2027f: 6.9; 2028f: 7.1; percentage-point differences from january 2026 projections - 2026f: 0.1; 2027f: 0.4`

  Tables that continue on the next page without a caption are carried over.
* Small chart/axis text and acronym lists (the Abbreviations page) are filtered out; line-end hyphens are re-joined only when the
  joined word exists elsewhere in the document ("sustainabil-ity" → "sustainability", "low-income" stays).

**Question routing** (`QuestionRouter`, no LLM)

| Signal in the question | Search is restricted to |
|---|---|
| a region name or acronym (South Asia, SAR, EAP, ECA, LAC, MNA, SSA, Middle East, …) | that region's section |
| "Chapter N" / "Ch. N" | everything under that chapter |
| "Box N.N" | that box |
| "executive summary" | the Executive Summary |

If nothing matches the whole document is searched; if a filter would leave fewer than three chunks it is ignored.

---

## UC3 — Short visual document (`VisualChunker`)

**Challenge:** the facts are in a carbon waterfall chart, small tables, big-number call-outs and footnotes. Without a vision
model, plain paragraph extraction misses them or scrambles them (table rows come out with labels and values detached).

**Key finding:** the chart labels and table cells *are* in the PDF text layer. Rebuilding them from word coordinates is enough.

| Source | What is rebuilt | Example chunk |
|---|---|---|
| Label/value table (p.10) | rows with one label and one value; bullet rows become children of the row above | `Life cycle product emissions (scope 3) - Product use: 45%` |
| Grid table (p.12) | header row + rows of equal width | `Sleep - 100V: 0.56W; 115V: 0.57W; 230V: 0.59W` |
| Chart (p.2) | each value cell plus the label cells stacked under it; minus signs normalised | `445 kg - Baseline emissions; -99 kg - Emissions reductions Recycled and low-carbon materials; 346 kg - iMac emissions` |
| Call-outs (p.6) | a big number plus the sentence below it | `100% of the packaging is fiber-based, due to our work to remove plastic in packaging` |
| Footnotes | superscript markers (`[^18]`) matched against the Endnotes; the note text is attached to the chunk it qualifies, and each note is also its own chunk | `iMac uses 58 percent less energy than the requirement for ENERGY STAR.` + `Footnote 18: Energy consumption … ENERGY STAR Program Requirements …` |
| Paragraphs | line-by-line blocks merged back into paragraphs | — |

Table cells are not repeated as paragraphs, and call-out descriptions are not repeated either. Endnote parsing relies on the notes
being numbered 1…n in order, each starting on its own row in the note column.

---

## Evaluation results

`php artisan rag:eval` — the 9 questions from the use-case document, plus a Sub-Saharan Africa region check and an
out-of-document control. Last run: **11 of 11 passed**.

| Use case | Question | Answer given |
|---|---|---|
| UC1 | How many depository libraries did GPO staff visit in FY 2018? | 166 depository libraries |
| UC1 | What new digital system replaced FDsys, and when was it fully deployed? | govinfo, December 2018 |
| UC1 | How much did GPO procure in printing and information products … ? | $375.7 million |
| UC2 | What is the growth outlook for South Asia in this report? | 6.3 percent in 2026 (from the South Asia section) |
| UC2 | What does Chapter 3 say about sovereign debt in developing economies? | debt raises interest rates; non-linear relationship with debt-to-GDP; sourced only from Ch.3 |
| UC2 | According to the executive summary, what are the main risks to the global outlook? | energy disruptions, financial stress, trade policy uncertainty, geopolitical strains, weather shocks; sourced only from the Executive Summary |
| UC2 | What is the growth outlook for Sub-Saharan Africa? | 4 percent in 2026, 4.4 percent on average in 2027–28 (all sources from the SSA section) |
| UC3 | How much less energy does iMac use compared to the ENERGY STAR requirement? | 58 percent less |
| UC3 | What is the total carbon footprint of the iMac …? | 346 kg CO2e; 45% product use, 50% from production/manufacturing |
| UC3 | What material is the iMac stand made from, and what percentage is recycled? | 100% recycled aluminum |
| Control | Who won the 2022 FIFA World Cup? | "I don't have that information in GPO 2018 Annual Report." |

The checks are deliberately strict: figures must appear next to their label (45% beside "product use"), and the stand
answer must not contain the packaging's 76%. Results can vary by wording because the model is small; re-run `rag:eval`
after any change to chunking, retrieval or the prompt.
