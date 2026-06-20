"""
Universal Question Bank Extraction Agent
==========================================
Extracts question, options, correct answer, subject/chapter tag, and any
attached diagram image from arbitrary question-bank PDFs.

Design goals (per requirements):
- Work across different PDF layouts (single or multi-column), not just one
  hardcoded format.
- Use a local LLM (via Ollama) -- NO cloud API, NO API key -- for:
    (a) parsing question blocks regex can't cleanly handle
    (b) classifying each question into a subject/chapter from a
        user-provided list
- Extract embedded diagram/image content and attach it to the relevant
  question as a saved image file + path reference (not "read" or
  interpreted, just extracted and linked).
- Fail safely: anything the pipeline isn't confident about goes to a
  review list instead of being guessed at silently.

IMPORTANT — read this before running:
This script calls a local Ollama server (http://localhost:11434) for the
LLM-dependent steps (messy-block parsing + subject classification). You
must install Ollama and pull a model yourself on your own machine:

    1. Install Ollama:        https://ollama.com/download
    2. Pull a model:          ollama pull llama3.1:8b
       (or any model you prefer -- update OLLAMA_MODEL below)
    3. Make sure it's running (ollama serve, or it auto-starts)
    4. Run this script:       python3 extract_questions_universal.py <pdf> <subjects.json>

This environment (the sandbox used to build/test this script) has no
internet access to ollama.com and cannot install or run Ollama, so the
LLM-dependent steps below are written and ready but could NOT be executed
or tested here. Stages 1-3 and 5 (layout-agnostic extraction, regex
parsing, diagram extraction, merging) ARE fully tested against a real PDF.
Test the Ollama-dependent stage on your own machine and tune the prompts
if needed for your model.

USAGE:
    python3 extract_questions_universal.py <pdf_path> <subjects_file.json> [output_dir]

subjects_file.json format:
{
  "Physics": ["Units and Measurements", "Kinematics", "Laws of Motion", ...],
  "Chemistry": ["Atomic Structure", "Chemical Bonding", ...],
  "Biology": ["Cell Structure", "Genetics", ...]
}
"""

import pdfplumber
import fitz  # PyMuPDF, for image extraction
import re
import json
import sys
import os
import urllib.request
import numpy as np


OLLAMA_URL = "http://localhost:11434/api/generate"
OLLAMA_MODEL = "llama3.1:8b"  # change to whatever model you've pulled


# ---------------------------------------------------------------------------
# STAGE 1: Layout-agnostic text extraction
# ---------------------------------------------------------------------------

def detect_column_gap(page, min_lines_threshold_ratio=0.75):
    """
    Detect whether a page uses a two-column layout by finding an x-position
    that very few text lines cross. Returns the gap x-position, or None if
    the page looks single-column (no clear gap found).
    """
    width = page.width
    lines = page.extract_text_lines()
    if not lines:
        return None
    spans = [(l["x0"], l["x1"]) for l in lines if l["text"].strip()]
    if len(spans) < 6:
        return None  # too little content to judge reliably

    candidates = np.linspace(width * 0.25, width * 0.75, 51)
    crossing_counts = []
    for g in candidates:
        crossing = sum(1 for x0, x1 in spans if x0 < g < x1)
        crossing_counts.append(crossing)

    min_idx = int(np.argmin(crossing_counts))
    min_crossing = crossing_counts[min_idx]
    median_crossing = float(np.median(crossing_counts))

    # A real column gap should have noticeably fewer lines crossing it than
    # the page's "typical" line span (most lines stay within one column,
    # only headings/wide elements legitimately span both).
    if median_crossing == 0:
        return None
    if min_crossing <= median_crossing * min_lines_threshold_ratio and min_crossing <= len(spans) * 0.5:
        return float(candidates[min_idx])
    return None


def extract_page_text_layout_aware(page):
    """
    Extract a page's text, automatically choosing single-column or
    two-column reading order based on detected layout.
    """
    gap = detect_column_gap(page)
    if gap is None:
        return page.extract_text() or ""
    width, height = page.width, page.height
    left = page.crop((0, 0, gap, height)).extract_text() or ""
    right = page.crop((gap, 0, width, height)).extract_text() or ""
    return left + "\n" + right


def extract_full_document_text(pdf_path):
    """
    Returns (column_aware_text, full_pages_text_list).
    column_aware_text: per-page layout-aware text, concatenated, used for
        splitting into question blocks.
    full_pages_text_list: plain per-page text (no column splitting), used
        for locating answer-key sections that may span columns as a
        continuous row.
    """
    column_parts = []
    full_pages = []
    with pdfplumber.open(pdf_path) as pdf:
        for page in pdf.pages:
            column_parts.append(extract_page_text_layout_aware(page))
            full_pages.append(page.extract_text() or "")
    return "\n".join(column_parts), full_pages


# ---------------------------------------------------------------------------
# STAGE 1b: Header / footer detection (font-size + position based)
# ---------------------------------------------------------------------------

def detect_headers_and_footers(pdf_path, top_margin=55, bottom_margin_ratio=0.93, min_recurrence=2):
    """
    Detect two kinds of noise text to strip later:
    - section_headers: short, non-numeric lines rendered larger than the
      surrounding body text (font-size signal).
    - running_headers_footers: lines positioned in the top or bottom page
      margin AND recurring on at least `min_recurrence` separate pages.
      Recurrence is required because position alone isn't reliable evidence
      of being a running header -- legitimate question content can also
      start near the top margin on a single page (e.g. right after an
      embedded image pushes it down). A true running header/footer repeats
      across multiple pages; one-off top-margin content does not.

    Uses layout-aware (column-cropped) line extraction so that on
    multi-column pages, header text isn't interleaved with unrelated text
    from the adjacent column on the same visual row.
    """
    section_headers = set()
    margin_candidates = {}  # text -> count of pages it appeared on as margin content

    with pdfplumber.open(pdf_path) as pdf:
        all_sizes = []
        for page in pdf.pages:
            for c in page.chars:
                all_sizes.append(round(c["size"], 1))
        if not all_sizes:
            return section_headers, set()
        body_size = max(set(all_sizes), key=all_sizes.count)

        for page in pdf.pages:
            height = page.height
            bottom_margin = height * bottom_margin_ratio
            gap = detect_column_gap(page)
            if gap is None:
                crops = [page]
            else:
                width = page.width
                crops = [page.crop((0, 0, gap, height)), page.crop((gap, 0, width, height))]

            page_margin_texts = set()
            for crop in crops:
                for line in crop.extract_text_lines():
                    txt = line["text"].strip()
                    if not txt:
                        continue
                    if line["top"] < top_margin or line["top"] > bottom_margin:
                        page_margin_texts.add(txt)
                        continue
                    if any(c.isdigit() for c in txt):
                        continue
                    sizes = [c["size"] for c in line["chars"]] if line["chars"] else []
                    if not sizes:
                        continue
                    avg_size = sum(sizes) / len(sizes)
                    if avg_size > body_size + 1.0 and 2 < len(txt) <= 60:
                        section_headers.add(txt)

            for txt in page_margin_texts:
                margin_candidates[txt] = margin_candidates.get(txt, 0) + 1

    # Only treat margin text as a true running header/footer if it recurs
    # across multiple pages. Position alone (without recurrence) is not
    # reliable evidence -- a single-page document gets no position-based
    # filtering at all here, since there's nothing to confirm recurrence
    # against and false positives (like real question text starting near
    # the top margin) are too costly.
    running_headers_footers = set()
    with pdfplumber.open(pdf_path) as pdf:
        total_pages = len(pdf.pages)
    for txt, count in margin_candidates.items():
        if count >= min_recurrence:
            running_headers_footers.add(txt)

    return section_headers, running_headers_footers


def strip_known_strings(text, *string_sets):
    for s_set in string_sets:
        for s in sorted(s_set, key=len, reverse=True):
            text = text.replace(s, " ")
    return text


def clean_sidebar_noise(text):
    """Remove single/double-character vertical sidebar artifacts."""
    lines = text.split("\n")
    cleaned = [l for l in lines if not (len(l.strip()) <= 2 and (l.strip().isalpha() or l.strip().isdigit()))]
    return "\n".join(cleaned)


# ---------------------------------------------------------------------------
# STAGE 2: Answer key detection (multiple common formats)
# ---------------------------------------------------------------------------

def parse_answer_key(full_pages_text):
    """
    Try multiple common answer-key formats found across question banks:
    1. "Answer Key" section with "N. (x)" pairs (e.g. "1. (d) 2. (a)")
    2. "Answer Key" section with "N. x" pairs without parens (e.g. "1. d")
    3. Inline answers within question text itself (e.g. "Ans: B" or
       "Answer: (c)" directly after a question/option block)
    Returns dict {question_number: answer_letter}
    """
    combined = "\n".join(full_pages_text)
    answers = {}

    idx = combined.lower().find("answer key")
    if idx != -1:
        key_section = combined[idx:]
        for pattern in [
            r"(\d{1,4})\.\s*\(([a-dA-D])\)",
            r"(\d{1,4})\.\s*([a-dA-D])\b",
        ]:
            matches = list(re.finditer(pattern, key_section))
            if len(matches) >= 3:  # require multiple matches to trust the pattern
                for m in matches:
                    qnum = int(m.group(1))
                    answers[qnum] = m.group(2).lower()
                break

    return answers


def parse_inline_answers(text):
    """
    Find inline answer markers like 'Ans: B', 'Answer: (c)', 'Ans. d'
    immediately associated with a question block. Returns dict
    {question_number: answer_letter}. Used as a fallback when no
    separate answer-key section exists.
    """
    answers = {}
    pattern = re.compile(
        r"(?m)^(\d{1,4})\.\s.*?(?:Ans(?:wer)?\.?:?\s*\(?([a-dA-D])\)?)",
        re.DOTALL
    )
    for m in pattern.finditer(text):
        qnum = int(m.group(1))
        answers[qnum] = m.group(2).lower()
    return answers


def strip_answer_key_rows(text):
    """Remove answer-key-row lines that leak into column text."""
    lines = text.split("\n")
    cleaned = []
    pattern = re.compile(r"^(\d{1,4}\.\s*\(?[a-dA-D]\)?\s*)+\d{0,4}$")
    for line in lines:
        stripped = line.strip()
        if stripped.lower() in ("answer key", "answe", "er key", "answers"):
            continue
        if pattern.match(stripped):
            continue
        cleaned.append(line)
    return "\n".join(cleaned)


# ---------------------------------------------------------------------------
# STAGE 2b: Question block splitting + regex parsing (the "clean pass")
# ---------------------------------------------------------------------------

def split_into_question_blocks(text):
    text = strip_answer_key_rows(text)
    pattern = re.compile(r"(?m)^(\d{1,4})\.\s")
    matches = list(pattern.finditer(text))
    blocks = []
    for i, m in enumerate(matches):
        qnum = int(m.group(1))
        start = m.start()
        end = matches[i + 1].start() if i + 1 < len(matches) else len(text)
        blocks.append((qnum, text[start:end].strip()))
    return blocks


def parse_question_block_regex(qnum, block_text):
    """Try a clean regex parse. Returns dict or None if it can't be done cleanly."""
    body = re.sub(r"^\d{1,4}\.\s*", "", block_text, count=1)
    opt_pattern = re.compile(r"(?:^|\s)([a-d])\.\s*")
    opt_matches = list(opt_pattern.finditer(body))

    if len(opt_matches) < 4:
        return None

    question_text = body[:opt_matches[0].start()].strip()
    year_match = re.search(r"\((\d{4}[^)]*)\)\s*$", question_text)
    year_tag = year_match.group(1) if year_match else None
    if year_match:
        question_text = question_text[:year_match.start()].strip()

    options = {}
    seen = []
    for i, om in enumerate(opt_matches):
        letter = om.group(1)
        if letter in seen:
            continue
        seen.append(letter)
        start = om.end()
        end = opt_matches[i + 1].start() if i + 1 < len(opt_matches) else len(body)
        opt_text = re.sub(r"\s+", " ", body[start:end].strip())
        options[letter] = opt_text

    if not all(k in options and options[k] for k in ["a", "b", "c", "d"]):
        return None
    if not question_text or len(question_text) < 5:
        return None

    question_text = re.sub(r"\s+", " ", question_text).strip()
    all_text = question_text + "".join(options.values())
    # Garbled-glyph sanity check (PDF math/fraction rendering artifacts)
    if re.search(r"[\uf000-\uf8ff]", all_text):
        return None

    return {
        "question_number": qnum,
        "question": question_text,
        "year": year_tag,
        "options": {k: options[k] for k in ["a", "b", "c", "d"]},
    }


# ---------------------------------------------------------------------------
# STAGE 3: Diagram / image extraction
# ---------------------------------------------------------------------------

def extract_images_with_positions(pdf_path, output_dir):
    """
    Extract embedded images from the PDF using PyMuPDF, saving each to
    output_dir, and recording its page number + vertical position so it
    can later be associated with the nearest question block.
    Returns list of dicts: {path, page_number, y_position}
    """
    os.makedirs(output_dir, exist_ok=True)
    images = []
    doc = fitz.open(pdf_path)
    for page_index in range(len(doc)):
        page = doc[page_index]
        img_list = page.get_images(full=True)
        for img_index, img in enumerate(img_list):
            xref = img[0]
            try:
                base_image = doc.extract_image(xref)
                image_bytes = base_image["image"]
                ext = base_image["ext"]
            except Exception:
                continue

            # Skip tiny images (likely icons/bullets/decorative artifacts,
            # not real diagrams). Threshold is conservative; tune as needed.
            if base_image.get("width", 0) < 40 or base_image.get("height", 0) < 40:
                continue

            filename = f"page{page_index+1}_img{img_index+1}.{ext}"
            filepath = os.path.join(output_dir, filename)
            with open(filepath, "wb") as f:
                f.write(image_bytes)

            # Find this image's vertical position on the page (for
            # associating with the nearest question block later).
            try:
                rects = page.get_image_rects(xref)
                y_pos = rects[0].y0 if rects else None
            except Exception:
                y_pos = None

            images.append({
                "path": filepath,
                "page_number": page_index + 1,
                "y_position": y_pos,
            })
    doc.close()
    return images


def associate_images_with_questions(images, question_page_map):
    """
    Associate each extracted image with the nearest question block based on
    page number and vertical position. question_page_map should map
    question_number -> (page_number, approx_y_position_on_page).
    Returns dict {question_number: [image_paths]}.
    NOTE: precise y-position mapping back from pdfplumber text blocks to
    page coordinates requires passing that data through from Stage 1;
    this function assumes question_page_map has already been built with
    that info (see build_question_page_map below).
    """
    associations = {}
    for qnum, (qpage, qy) in question_page_map.items():
        candidates = [img for img in images if img["page_number"] == qpage]
        if not candidates:
            continue
        # Choose the image closest in y-position on the same page; if
        # y-position is unknown for either side, just take the first
        # image on that page as a best-effort fallback.
        if qy is not None:
            candidates_with_y = [c for c in candidates if c["y_position"] is not None]
            if candidates_with_y:
                best = min(candidates_with_y, key=lambda c: abs(c["y_position"] - qy))
                associations.setdefault(qnum, []).append(best["path"])
                continue
        associations.setdefault(qnum, []).append(candidates[0]["path"])
    return associations


def build_question_page_map(pdf_path):
    """
    Build {question_number: (page_number, y_position)} by re-scanning pages
    for question-start markers ('N. ') and recording where each was found.
    """
    qmap = {}
    with pdfplumber.open(pdf_path) as pdf:
        for page_index, page in enumerate(pdf.pages):
            for line in page.extract_text_lines():
                txt = line["text"].strip()
                m = re.match(r"^(\d{1,4})\.\s", txt)
                if m:
                    qnum = int(m.group(1))
                    if qnum not in qmap:  # first occurrence wins
                        qmap[qnum] = (page_index + 1, line["top"])
    return qmap


# ---------------------------------------------------------------------------
# STAGE 4: Local LLM fallback (Ollama) -- messy parsing + subject classification
# ---------------------------------------------------------------------------

def call_ollama(prompt, model=OLLAMA_MODEL, timeout=120):
    """
    Call a local Ollama server. Returns the raw text response, or None on
    failure (e.g. Ollama not running, model not pulled, network issue).
    NOT TESTED in the build sandbox (no network access to run Ollama there)
    -- verify this works against your local Ollama install before relying
    on it for a full PDF run.
    """
    payload = {
        "model": model,
        "prompt": prompt,
        "stream": False,
        "options": {"temperature": 0.1},
    }
    try:
        req = urllib.request.Request(
            OLLAMA_URL,
            data=json.dumps(payload).encode("utf-8"),
            headers={"Content-Type": "application/json"},
        )
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            result = json.loads(resp.read().decode("utf-8"))
            return result.get("response", "").strip()
    except Exception as e:
        print(f"  [Ollama call failed: {e}]")
        return None


def llm_parse_messy_block(qnum, raw_text):
    """
    Ask the local LLM to reconstruct question/options from a block that
    regex couldn't parse cleanly (garbled math, broken formatting).
    Returns parsed dict or None if the LLM also fails / gives bad JSON.
    """
    prompt = f"""You are extracting a multiple-choice question from messy OCR/PDF-extracted text.
The text below may have broken/garbled mathematical symbols, scrambled spacing, or fragments out of order.
Reconstruct the question and its 4 options (a, b, c, d) as best you can.

Respond with ONLY valid JSON in this exact format, nothing else:
{{"question": "...", "options": {{"a": "...", "b": "...", "c": "...", "d": "..."}}}}

If you genuinely cannot reconstruct a coherent question with 4 options, respond with exactly: NULL

Raw text:
{raw_text}
"""
    response = call_ollama(prompt)
    if not response or response.strip() == "NULL":
        return None
    try:
        # Strip markdown code fences if the model added them
        cleaned = re.sub(r"^```json\s*|\s*```$", "", response.strip())
        parsed = json.loads(cleaned)
        if "question" in parsed and "options" in parsed:
            opts = parsed["options"]
            if all(k in opts and opts[k] for k in ["a", "b", "c", "d"]):
                return {
                    "question_number": qnum,
                    "question": parsed["question"],
                    "year": None,
                    "options": opts,
                }
    except (json.JSONDecodeError, KeyError, TypeError):
        pass
    return None


def llm_classify_subject(question_text, subjects_dict):
    """
    Ask the local LLM to classify a question into one of the provided
    subject/chapter labels. subjects_dict format:
        {"Physics": ["Kinematics", "Laws of Motion", ...], "Chemistry": [...]}
    Returns {"subject": "...", "chapter": "..."} or None if classification
    fails or the model's answer doesn't match any provided label.
    """
    chapter_list_text = "\n".join(
        f"- {subject}: {', '.join(chapters)}"
        for subject, chapters in subjects_dict.items()
    )
    prompt = f"""Classify the following exam question into exactly one subject and one chapter
from the list below. Respond with ONLY valid JSON: {{"subject": "...", "chapter": "..."}}
Use the exact subject and chapter names as given in the list -- do not invent new ones.
If genuinely uncertain, pick the closest match.

Available subjects and chapters:
{chapter_list_text}

Question:
{question_text}
"""
    response = call_ollama(prompt)
    if not response:
        return None
    try:
        cleaned = re.sub(r"^```json\s*|\s*```$", "", response.strip())
        parsed = json.loads(cleaned)
        subject = parsed.get("subject")
        chapter = parsed.get("chapter")
        if subject in subjects_dict and chapter in subjects_dict[subject]:
            return {"subject": subject, "chapter": chapter}
        # Loose match fallback: accept subject even if chapter is slightly off
        if subject in subjects_dict:
            return {"subject": subject, "chapter": chapter or "Unclassified"}
    except (json.JSONDecodeError, AttributeError, TypeError):
        pass
    return None


# ---------------------------------------------------------------------------
# Orchestration
# ---------------------------------------------------------------------------

def extract_question_bank(pdf_path, subjects_dict, output_dir, use_llm_fallback=True):
    section_headers, page_headers = detect_headers_and_footers(pdf_path)
    column_text, full_pages_text = extract_full_document_text(pdf_path)
    column_text = strip_known_strings(column_text, section_headers, page_headers)
    column_text = clean_sidebar_noise(column_text)

    answer_key = parse_answer_key(full_pages_text)
    if not answer_key:
        answer_key = parse_inline_answers(column_text)

    blocks = split_into_question_blocks(column_text)

    images_dir = os.path.join(output_dir, "images")
    images = extract_images_with_positions(pdf_path, images_dir)
    qpage_map = build_question_page_map(pdf_path)
    image_associations = associate_images_with_questions(images, qpage_map)

    clean_results = []
    review_results = []

    for qnum, block_text in blocks:
        parsed = parse_question_block_regex(qnum, block_text)
        used_llm = False

        if parsed is None and use_llm_fallback:
            parsed = llm_parse_messy_block(qnum, block_text)
            used_llm = True

        if parsed is None:
            review_results.append({
                "question_number": qnum,
                "raw_text": block_text,
                "reason": "Could not parse cleanly via regex or local LLM fallback",
            })
            continue

        answer_letter = answer_key.get(qnum)
        if answer_letter is None or answer_letter not in parsed["options"]:
            review_results.append({
                "question_number": qnum,
                "raw_text": block_text,
                "reason": "Parsed options but no valid matching answer found",
                "parsed_preview": parsed,
            })
            continue

        classification = None
        if use_llm_fallback and subjects_dict:
            classification = llm_classify_subject(parsed["question"], subjects_dict)

        entry = {
            "question_number": qnum,
            "question": parsed["question"],
            "year": parsed.get("year"),
            "options": parsed["options"],
            "correct_answer": answer_letter,
            "correct_answer_text": parsed["options"][answer_letter],
            "subject": classification["subject"] if classification else None,
            "chapter": classification["chapter"] if classification else None,
            "images": image_associations.get(qnum, []),
            "parsed_via_llm_fallback": used_llm,
        }
        clean_results.append(entry)

    clean_results.sort(key=lambda x: x["question_number"])
    review_results.sort(key=lambda x: x["question_number"])
    return clean_results, review_results


if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage: python3 extract_questions_universal.py <pdf_path> [subjects.json] [output_dir]")
        sys.exit(1)

    pdf_path = sys.argv[1]
    subjects_path = sys.argv[2] if len(sys.argv) > 2 else None
    output_dir = sys.argv[3] if len(sys.argv) > 3 else "./extraction_output"

    subjects_dict = {}
    if subjects_path and os.path.exists(subjects_path):
        with open(subjects_path, "r", encoding="utf-8") as f:
            subjects_dict = json.load(f)
    else:
        print("No subjects file provided -- skipping subject classification.")

    os.makedirs(output_dir, exist_ok=True)
    clean_results, review_results = extract_question_bank(
        pdf_path, subjects_dict, output_dir, use_llm_fallback=True
    )

    with open(os.path.join(output_dir, "questions.json"), "w", encoding="utf-8") as f:
        json.dump(clean_results, f, indent=2, ensure_ascii=False)
    with open(os.path.join(output_dir, "review.json"), "w", encoding="utf-8") as f:
        json.dump(review_results, f, indent=2, ensure_ascii=False)

    print(f"Cleanly extracted: {len(clean_results)}")
    print(f"Flagged for review: {len(review_results)}")
    print(f"Output written to: {output_dir}")
