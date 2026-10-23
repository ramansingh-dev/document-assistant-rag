# 5. Development log — problems found and how they were fixed

Kept so the reasons behind the code are not lost.

## Extraction

| Problem | Fix |
|---|---|
| Printed page number differs from the PDF page (front matter uses roman numerals; GPO footer reads "35 2 0 1 8 ANNUAL REPORT …") | `extract_pdf.py` reads the number from the running header/footer, including the spaced-out GPO footer |
| Running headers/footers polluted chunks | each block gets a `zone`; chunkers drop `header`/`footer` |
| Table rows came out as separate columns | word rows clustered by y position (`rows`), cells merged by gap |
| Footnote markers glued to words ("…ENERGY STAR.18") | superscript spans are rewritten as `[^18]` (iMac only) |
| "CO₂e" became "CO[^2]e" and attached footnote 2 to every carbon chunk | only *raised* small digits are footnote markers; lowered digits (subscripts) are left alone |
| The "Th" ligature is lost in the World Bank PDF ("Te conflict") | `TextCleaner` repairs Te/Tese/Tis/Tere/Tat/Tey/Tus/Tose/Tird/Terefore |
| "ﬃ"/"ﬁ" ligature characters | normalised to plain letters |
| Line-end hyphens ("invest-ment", "low-income") | join only when the joined word exists elsewhere in the document |

## Structure (World Bank report)

| Problem | Fix |
|---|---|
| South Asia bookmark pointed one page early | bookmarks are checked against headings within a small window |
| Box text bled into chapter text | box pages are recognised by their "BOX x.y (continued)" running header |
| Contents and Abbreviations pages matched "Chapter 3" questions | pages before the Executive Summary skipped; acronym lists filtered |
| A paragraph starting "Table of …" was treated as a table caption | captions must be upper case with a number (`TABLE 2.9`, `FIGURE B1.1.1`, `TABLE A.1`) |
| Table difference columns were unlabelled ("difference from previous projections") | the phrase "Percentage-point differences from January 2026 projections" is found in the table header after removing parenthetical asides |

## Visual document (iMac)

| Problem | Fix |
|---|---|
| Table cells appeared both in the table chunk and as noisy paragraphs | blocks on table rows are removed from paragraph chunking |
| Call-out sentences duplicated | description blocks are consumed by their number |
| Endnotes appeared in scrambled order | notes are rebuilt from visual rows (number + text in one row, continuation rows by x position) |
| Minus signs in the chart (en dash glyph) | normalised to "-" before digits |
| Power-consumption table lives on the endnotes page | grid-table detection also runs on endnote pages |

## Storage and retrieval

| Problem | Fix |
|---|---|
| Embeddings were stored as text; SQLite cut them at the first NUL byte, giving garbage similarity | bind embeddings with `PDO::PARAM_LOB` (ingestion and seeding) and check `length(embedding) = 4096` |
| Dense similarity was weak for questions | Qwen3-Embedding query instruction prefix on questions |
| The 6.3 % table row lost to generic short chunks | priors: tables/charts up on quantity questions, footnote chunks and tiny fragments down |
| A call-out ("76% recycled content in fiber packaging") outranked the real stand sentence | the quantity boost applies only to tables and charts |
| For long reports, big tables outranked the prose that states the headline number | the table boost is only ×1.05 outside the visual profile |
| The model answered with a neighbouring row/year | repeat the question after the context; rules about figures belonging to their item; lead with the headline forecast |
| Top-6 missed a relevant GPO paragraph | `top_k` raised to 8 |

## Evaluation honesty

* An early run "passed" while the iMac answer said *50% product use, 45% end-of-life* — the check only looked for the numbers.
  Checks now bind each figure to its label and forbid known confusions (`answer_none`).
* A context-order experiment (best passage last) helped one question and hurt another, so the plain best-first order stays.

## UI

* The first browser run failed with `CannotCallComputedDirectlyException`: the page's auto-scroll code read the computed
  `messages` property from JavaScript. It was replaced by a `MutationObserver` on the scroll container.

## Environment notes

* PHP is Herd's `php.bat` (PowerShell: add `C:\Users\admin\.config\herd\bin` to `PATH`).
* Ingestion of the 200-page report took about 19 minutes on CPU; a background run was once killed by the OS for low memory,
  leaving a document stuck at `processing` (re-run with `--force`).
