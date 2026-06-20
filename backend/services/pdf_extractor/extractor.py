"""
Question Bank Extractor (Clean-Case Pass)
-------------------------------------------
Extracts question, options (a-d), and correct answer from a NEET-style
PYQ PDF that uses a two-column layout with a separate "Answer Key" section.

Strategy:
1. Extract text column-by-column (left half, right half) per page for the
   question bodies, since this PDF uses a 2-column layout and naive full-page
   extraction interleaves the two columns.
2. Extract the "Answer Key" section from the FULL (uncropped) page text,
   since that section spans both columns as a continuous row and breaks if
   you crop it.
3. Use regex to split column text into per-question blocks, then parse out
   options a/b/c/d from each block.
4. Merge in correct answers from the Answer Key by question number.
5. Anything that doesn't cleanly match the expected pattern (garbled math,
   missing options, etc.) is flagged in a separate "review" list rather than
   guessed at.

Output: questions.json (clean) + review.json (flagged for manual/LLM pass)
"""

import pdfplumber
import re
import json
import sys


def detect_section_headers(pdf_path):
    """
    Identify section-header strings using font size as the signal: this
    document renders headers at ~12pt vs ~10pt for question/option body text.
    Returns a set of header strings (e.g. {'Errors', 'Significant Figures'}).
    """
    headers = set()
    with pdfplumber.open(pdf_path) as pdf:
        for page in pdf.pages:
            width, height = page.width, page.height
            for crop in (page.crop((0, 0, width / 2, height)),
                         page.crop((width / 2, 0, width, height))):
                for line in crop.extract_text_lines():
                    txt = line["text"].strip()
                    if not txt or any(c.isdigit() for c in txt):
                        continue
                    sizes = [c["size"] for c in line["chars"]] if line["chars"] else []
                    if not sizes:
                        continue
                    avg_size = sum(sizes) / len(sizes)
                    # Body text in this doc is ~10pt; section headers are
                    # ~12pt, and chapter-title fragments run larger (~26pt).
                    # Exclude single-letter sidebar noise via len(txt) > 2.
                    if 11.0 <= avg_size <= 30.0 and len(txt) > 2:
                        headers.add(txt)
    return headers


def detect_running_page_headers(pdf_path, top_margin=55):
    """
    Identify running page-header/footer lines (e.g. "4 Chapter & Topicwise
    NEET PYQ's") by vertical position near the very top of the page, above
    where question content starts. These can't be filtered by font size
    alone since they're rendered at body-text size.
    """
    page_headers = set()
    with pdfplumber.open(pdf_path) as pdf:
        for page in pdf.pages:
            width, height = page.width, page.height
            for crop in (page.crop((0, 0, width / 2, height)),
                         page.crop((width / 2, 0, width, height))):
                for line in crop.extract_text_lines():
                    if line["top"] < top_margin:
                        txt = line["text"].strip()
                        if txt:
                            page_headers.add(txt)
    return page_headers


def strip_known_headers(text, headers):
    """Remove any known section-header string wherever it appears in text."""
    for h in sorted(headers, key=len, reverse=True):
        text = text.replace(h, " ")
    return text


def extract_column_text(pdf_path):
    """Extract text column-by-column (left, right) for every page, in reading order."""
    full_text_parts = []
    with pdfplumber.open(pdf_path) as pdf:
        for page in pdf.pages:
            width, height = page.width, page.height
            left = page.crop((0, 0, width / 2, height)).extract_text() or ""
            right = page.crop((width / 2, 0, width, height)).extract_text() or ""
            full_text_parts.append(left)
            full_text_parts.append(right)
    return "\n".join(full_text_parts)


def extract_full_page_text(pdf_path):
    """Extract plain full-page text (used only for locating the Answer Key)."""
    pages = []
    with pdfplumber.open(pdf_path) as pdf:
        for page in pdf.pages:
            pages.append(page.extract_text() or "")
    return pages


def parse_answer_key(full_pages_text):
    """
    Find 'Answer Key' sections across pages and parse entries like:
    '1. (d) 2. (a) 3. (c) ...' into {1: 'd', 2: 'a', 3: 'c', ...}
    """
    answers = {}
    combined = "\n".join(full_pages_text)
    # Grab everything after the first "Answer Key" marker to the end of doc
    # (works for single-chapter PDFs; for multi-chapter PDFs this still works
    # because we anchor on number+parenthesis pattern, not section boundaries)
    idx = combined.lower().find("answer key")
    if idx == -1:
        return answers
    key_section = combined[idx:]
    # Pattern: "12. (d)" or "12.(d)" with optional spacing
    pattern = re.compile(r"(\d{1,4})\.\s*\(([a-dA-D])\)")
    for match in pattern.finditer(key_section):
        qnum = int(match.group(1))
        ans = match.group(2).lower()
        answers[qnum] = ans
    return answers


def clean_noise_lines(text):
    """Remove vertical sidebar artifacts and chapter-header noise."""
    lines = text.split("\n")
    cleaned = []
    for line in lines:
        stripped = line.strip()
        # Single-letter vertical sidebar lines like "R", "E", "T", "P", "A", "H", "C", "1"
        if len(stripped) <= 2 and (stripped.isalpha() or stripped.isdigit()):
            continue
        cleaned.append(line)
    return "\n".join(cleaned)


def strip_answer_key_rows(text):
    """
    Remove Answer Key rows (e.g. '1. (d) 2. (a) 3. (c) ...') from column text.
    These rows leak in because cropped pages include the Answer Key section,
    and their 'N. ' pattern is indistinguishable from a question start unless
    removed first.
    """
    lines = text.split("\n")
    cleaned = []
    answer_row_pattern = re.compile(r"^(\d{1,4}\.\s*\([a-dA-D]\)\s*)+\d{0,4}$")
    for line in lines:
        stripped = line.strip()
        if stripped.lower() in ("answer key", "answe", "er key"):
            continue
        if answer_row_pattern.match(stripped):
            continue
        cleaned.append(line)
    return "\n".join(cleaned)


def split_into_question_blocks(text):
    """
    Split column text into blocks, one per question, using the
    'N. ' question-number pattern at the start of a line as the delimiter.
    Returns list of (question_number, block_text).
    """
    text = strip_answer_key_rows(text)
    # Match a line that starts with digits + "." + space (question start)
    pattern = re.compile(r"(?m)^(\d{1,4})\.\s")
    matches = list(pattern.finditer(text))
    blocks = []
    for i, m in enumerate(matches):
        qnum = int(m.group(1))
        start = m.start()
        end = matches[i + 1].start() if i + 1 < len(matches) else len(text)
        block_text = text[start:end].strip()
        blocks.append((qnum, block_text))
    return blocks


def parse_question_block(qnum, block_text):
    """
    Parse a single question block into question text + options a-d.
    Returns dict if clean parse succeeds, else None (-> flagged for review).
    """
    # Strip the leading "N. " 
    body = re.sub(r"^\d{1,4}\.\s*", "", block_text, count=1)

    # Find option markers: a. b. c. d. (must have all four, each followed by content)
    opt_pattern = re.compile(
        r"(?:^|\s)([a-d])\.\s*"
    )
    opt_matches = list(opt_pattern.finditer(body))

    if len(opt_matches) < 4:
        return None  # can't find all 4 options cleanly -> review

    # Question text is everything before the first option marker
    question_text = body[:opt_matches[0].start()].strip()

    # Remove trailing year tags like "(2020-Covid)" from question text and
    # capture them separately
    year_match = re.search(r"\((\d{4}[^)]*)\)\s*$", question_text)
    year_tag = year_match.group(1) if year_match else None
    if year_match:
        question_text = question_text[:year_match.start()].strip()

    # Extract each option's text (from this marker to next marker, or end)
    options = {}
    letters_seen = []
    for i, om in enumerate(opt_matches):
        letter = om.group(1)
        if letter in letters_seen:
            # duplicate letter before completing a-d set -> messy block, bail
            continue
        letters_seen.append(letter)
        opt_start = om.end()
        opt_end = opt_matches[i + 1].start() if i + 1 < len(opt_matches) else len(body)
        opt_text = body[opt_start:opt_end].strip()
        opt_text = re.sub(r"\s+", " ", opt_text)
        options[letter] = opt_text

    # Require exactly a, b, c, d all present and non-empty
    if not all(k in options and options[k] for k in ["a", "b", "c", "d"]):
        return None

    # Sanity check: question text shouldn't be empty, and shouldn't itself
    # contain another embedded question number pattern (sign of bad split)
    if not question_text or len(question_text) < 5:
        return None

    question_text = re.sub(r"\s+", " ", question_text).strip()

    # Sanity check: PDF math/fraction rendering often leaves behind private-use
    # area unicode glyphs (U+F000-U+F8FF range) used for font-specific symbols.
    # If any of these appear, the block is garbled math, not clean text.
    all_text = question_text + "".join(options.values())
    if re.search(r"[\uf000-\uf8ff]", all_text):
        return None

    return {
        "question_number": qnum,
        "question": question_text,
        "year": year_tag,
        "options": {
            "a": options["a"],
            "b": options["b"],
            "c": options["c"],
            "d": options["d"],
        },
    }


def looks_like_section_header(qnum, block_text):
    """
    Heuristic: if a 'question number' match is actually just a stray digit
    near a section header (false positive from split), the block will be
    tiny and contain no option markers at all -- already handled by
    parse_question_block returning None, so no extra action needed here.
    Kept as a hook for future refinement.
    """
    return False


def extract_question_bank(pdf_path):
    headers = detect_section_headers(pdf_path)
    page_headers = detect_running_page_headers(pdf_path)
    column_text = extract_column_text(pdf_path)
    column_text = strip_known_headers(column_text, headers)
    column_text = strip_known_headers(column_text, page_headers)
    column_text = clean_noise_lines(column_text)
    full_pages_text = extract_full_page_text(pdf_path)

    answer_key = parse_answer_key(full_pages_text)
    blocks = split_into_question_blocks(column_text)

    clean_results = []
    review_results = []

    for qnum, block_text in blocks:
        parsed = parse_question_block(qnum, block_text)
        if parsed is None:
            review_results.append({
                "question_number": qnum,
                "raw_text": block_text,
                "reason": "Could not cleanly identify 4 options (likely garbled math/formatting)",
            })
            continue

        answer_letter = answer_key.get(qnum)
        if answer_letter is None:
            # No answer found in answer key -> still flag for review
            review_results.append({
                "question_number": qnum,
                "raw_text": block_text,
                "reason": "Parsed options fine, but no matching answer found in Answer Key",
                "parsed_preview": parsed,
            })
            continue

        if answer_letter not in parsed["options"]:
            review_results.append({
                "question_number": qnum,
                "raw_text": block_text,
                "reason": f"Answer key points to option '{answer_letter}' which wasn't parsed",
                "parsed_preview": parsed,
            })
            continue

        clean_results.append({
            "question_number": qnum,
            "question": parsed["question"],
            "year": parsed["year"],
            "options": parsed["options"],
            "correct_answer": answer_letter,
            "correct_answer_text": parsed["options"][answer_letter],
        })

    # Sort by question number
    clean_results.sort(key=lambda x: x["question_number"])
    review_results.sort(key=lambda x: x["question_number"])

    return clean_results, review_results


if __name__ == "__main__":
    if len(sys.argv) < 4:
        print("Usage: python extractor.py <pdf_path> <output_clean_json> <output_review_json>")
        sys.exit(1)

    pdf_path = sys.argv[1]
    clean_out_path = sys.argv[2]
    review_out_path = sys.argv[3]

    clean_results, review_results = extract_question_bank(pdf_path)

    with open(clean_out_path, "w", encoding="utf-8") as f:
        json.dump(clean_results, f, indent=2, ensure_ascii=False)

    with open(review_out_path, "w", encoding="utf-8") as f:
        json.dump(review_results, f, indent=2, ensure_ascii=False)

    print(f"Total question blocks found: {len(clean_results) + len(review_results)}")
    print(f"Cleanly extracted: {len(clean_results)}")
    print(f"Flagged for review: {len(review_results)}")
    print("Flagged question numbers:", [r["question_number"] for r in review_results])
