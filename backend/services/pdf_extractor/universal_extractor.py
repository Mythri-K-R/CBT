#!/usr/bin/env python3
"""
Universal PDF Question Extractor — Examsphere
Powered by Google Gemini Vision API.

Handles ALL PDF types:
  - Text-based PDFs (typed question banks)
  - Scanned / image-only PDFs (Gemini OCRs them)
  - PDFs with complex math, formulas, chemical equations
  - PDFs with circuit diagrams, graphs, figures
  - Multi-column layouts

Auto-detects subject (Physics / Chemistry / Biology / Mathematics)
Outputs math in LaTeX notation ($...$ and $$...$$)
Saves diagram images as PNG crops from the page render
Falls back to regex extraction if no Gemini API key is provided.
"""

import fitz          # PyMuPDF
from PIL import Image
import io
import json
import sys
import os
import re
import time
import base64
import requests

# ─────────────────────────────────────────────────────────────────────────────
# Gemini API
# ─────────────────────────────────────────────────────────────────────────────

GEMINI_MODEL   = "gemini-2.0-flash"
GEMINI_API_URL = (
    f"https://generativelanguage.googleapis.com/v1beta/models"
    f"/{GEMINI_MODEL}:generateContent"
)

EXTRACT_PROMPT = r"""
You are an expert MCQ question extractor for Indian competitive exam papers (NEET, JEE Main, KCET).

Carefully read this PDF page image and extract EVERY multiple-choice question (MCQ) visible.

Return a JSON object with key "questions" containing an array. Each element must have:

  "question_number"  : integer (from the printed number in the paper)
  "question_text"    : complete question text.
                       - Use LaTeX for ALL math / chemistry / physics notation:
                         inline → $...$ (e.g. $v = u + at$, $H_2SO_4$, $\Delta G$)
                         display → $$...$$ (for standalone equations)
                       - For subscripts/superscripts use LaTeX: $H_2O$, $x^2$
                       - If a figure or diagram is part of the question, insert the
                         placeholder text [DIAGRAM] exactly where it appears
  "option_a"         : full text of option A (with LaTeX if needed)
  "option_b"         : full text of option B
  "option_c"         : full text of option C
  "option_d"         : full text of option D
  "correct_answer"   : "A", "B", "C", or "D" if the answer key is visible on this
                       page or at the bottom/side of the question; null otherwise
  "subject"          : classify as exactly ONE of:
                       "Physics" | "Chemistry" | "Biology" | "Mathematics"
                       Use the question content and terminology to decide.
  "difficulty"       : "easy" | "medium" | "hard" (based on conceptual depth)
  "has_diagram"      : true if the question includes or references a figure, graph,
                       circuit diagram, or geometric shape; false otherwise
  "diagram_bbox"     : if has_diagram is true, give the bounding box of the diagram
                       image on this page as [x0, y0, x1, y1] where each value is a
                       fraction of the page dimensions (0.0 to 1.0, origin top-left).
                       Set to null if has_diagram is false.

Rules:
  - Extract ONLY standard 4-option MCQs. Skip integer-type, assertion-reason,
    matrix-match, multi-statement, or passage-based questions.
  - For scanned / handwritten pages, OCR carefully before extracting.
  - Preserve superscripts, subscripts, Greek letters, arrows (→, ⇌), degree symbols.
  - For chemical structures described in text (e.g. "the compound shown below"),
    write [DIAGRAM] and set has_diagram=true.
  - If an answer strip / answer key is printed on this page, extract correct answers.
  - If no MCQs are present on this page, return {"questions": []}.
"""


def _img_to_jpeg_bytes(img_bytes: bytes, quality: int = 88) -> bytes:
    """Re-encode raw page pixels as JPEG for smaller API payload."""
    img = Image.open(io.BytesIO(img_bytes))
    if img.mode not in ("RGB", "L"):
        img = img.convert("RGB")
    buf = io.BytesIO()
    img.save(buf, format="JPEG", quality=quality, optimize=True)
    return buf.getvalue()


def _render_page(page) -> bytes:
    """Render a PDF page at ~150 DPI as JPEG bytes."""
    mat = fitz.Matrix(2.0, 2.0)       # 2× the default 72 DPI ≈ 144 DPI
    pix = page.get_pixmap(matrix=mat, alpha=False)
    raw = pix.tobytes("png")
    return _img_to_jpeg_bytes(raw)


def _crop_diagram(page_jpeg: bytes, bbox: list, out_path: str) -> bool:
    """Crop a bounding-box region (0–1 fractions) from a page image and save."""
    try:
        img = Image.open(io.BytesIO(page_jpeg))
        w, h = img.size
        x0 = max(0, int(bbox[0] * w) - 4)
        y0 = max(0, int(bbox[1] * h) - 4)
        x1 = min(w, int(bbox[2] * w) + 4)
        y1 = min(h, int(bbox[3] * h) + 4)
        if x1 <= x0 or y1 <= y0 or (x1 - x0) < 10 or (y1 - y0) < 10:
            return False
        img.crop((x0, y0, x1, y1)).save(out_path, "PNG")
        return True
    except Exception as exc:
        print(f"    [warn] diagram crop failed: {exc}", flush=True)
        return False


def _call_gemini(api_key: str, jpeg_bytes: bytes, retries: int = 3) -> list:
    """Send a page image to Gemini Vision and return the list of questions."""
    b64 = base64.b64encode(jpeg_bytes).decode("utf-8")
    payload = {
        "contents": [{
            "parts": [
                {"inline_data": {"mime_type": "image/jpeg", "data": b64}},
                {"text": EXTRACT_PROMPT},
            ]
        }],
        "generationConfig": {
            "responseMimeType": "application/json",
            "temperature": 0.1,
            "maxOutputTokens": 8192,
        },
    }
    url = f"{GEMINI_API_URL}?key={api_key}"

    for attempt in range(retries):
        try:
            resp = requests.post(url, json=payload, timeout=90)

            if resp.status_code == 429:
                wait = 20 * (attempt + 1)
                print(f"    [rate-limit] waiting {wait}s (attempt {attempt+1}/{retries})…", flush=True)
                time.sleep(wait)
                continue

            if resp.status_code != 200:
                msg = resp.json().get("error", {}).get("message", "unknown")
                raise RuntimeError(f"Gemini HTTP {resp.status_code}: {msg}")

            text = resp.json()["candidates"][0]["content"]["parts"][0]["text"]

            # Parse JSON — Gemini sometimes wraps in ```json … ```
            text = re.sub(r"^```(?:json)?\s*", "", text.strip())
            text = re.sub(r"\s*```$", "", text)

            data = json.loads(text)
            return data.get("questions", [])

        except (requests.Timeout, ConnectionError) as exc:
            if attempt == retries - 1:
                raise RuntimeError(f"Gemini timeout after {retries} attempts: {exc}")
            print(f"    [timeout] retrying… ({exc})", flush=True)
            time.sleep(5)

        except json.JSONDecodeError as exc:
            print(f"    [json-err] could not parse Gemini response: {exc}", flush=True)
            return []

    return []


# ─────────────────────────────────────────────────────────────────────────────
# Gemini-based extraction
# ─────────────────────────────────────────────────────────────────────────────

VALID_SUBJECTS = {"Physics", "Chemistry", "Biology", "Mathematics"}

# Keyword patterns used only when Gemini returns an unrecognised subject
_SUBJECT_KW = {
    "Physics":      r"\b(velocity|force|momentum|magnetic|electric|gravity|acceleration|kinetic|joule|watt|friction|optics|current|voltage|resistance|capacitor|inductor|wave|frequency|amplitude|photon|electron|proton|neutron|nucleus|radiation|entropy|pressure|density|refractive|lens|mirror|charge|coulomb|ampere|ohm|flux|torque|work|energy|power|thermodynamics|temperature|heat)\b",
    "Chemistry":    r"\b(mol|mole|aqueous|iupac|oxidation|molecule|equilibrium|bond|enthalpy|entropy|electrode|electrolyte|catalyst|polymer|ester|aldehyde|ketone|alkane|alkene|alkyne|benzene|hybridis|valence|pH|buffer|titration|electrolysis|galvanic|crystal|lattice|isomer|chirality|nucleophile|electrophile|acid|base|salt|reaction|element|compound|periodic|atomic|covalent|ionic|hydrogen|carbon|oxygen|nitrogen|sulfur|chlorine|sodium|potassium)\b",
    "Biology":      r"\b(mitochondria|cell|gene|dna|rna|protein|species|organ|tissue|chromosome|enzyme|hormone|photosynthesis|respiration|osmosis|plasmid|ribosome|membrane|mitosis|meiosis|taxonomy|kingdom|phylum|ecology|population|ecosystem|evolution|mutation|allele|dominant|recessive|genotype|phenotype|bacteria|virus|fungi|plant|animal|human|blood|heart|lung|liver|kidney|digestion|reproduction|nervous|immune|lymph|chloroplast)\b",
    "Mathematics":  r"\b(integral|derivative|matrix|determinant|vector|polynomial|tangent|sine|cosine|logarithm|theorem|geometry|algebra|probability|statistics|limit|sequence|series|complex|conic|parabola|ellipse|hyperbola|permutation|combination|binomial|differential|equation|inequality|function|domain|range|triangle|circle|square|cube|coordinate|arithmetic|quadratic|linear|mean|median|mode|variance)\b",
}

def _classify_subject(text: str) -> str:
    tl = text.lower()
    scores = {s: len(re.findall(pat, tl)) for s, pat in _SUBJECT_KW.items()}
    best = max(scores, key=scores.get)
    return best if scores[best] > 0 else "Physics"


def extract_with_gemini(pdf_path: str, diagrams_dir: str, api_key: str):
    doc = fitz.open(pdf_path)
    total = len(doc)
    print(f"Gemini Vision extraction — {total} page(s)", flush=True)

    all_q:    list[dict] = []
    review_q: list[dict] = []
    seen_nums: set       = set()

    for pno in range(total):
        page = doc.load_page(pno)
        print(f"  page {pno+1}/{total} …", flush=True)

        jpeg = _render_page(page)

        try:
            raw_qs = _call_gemini(api_key, jpeg)
        except Exception as exc:
            print(f"  [error] {exc}", flush=True)
            raw_qs = []

        for q in raw_qs:
            qnum   = int(q.get("question_number") or 0)
            qtxt   = (q.get("question_text") or "").strip()
            opt_a  = (q.get("option_a")  or "").strip()
            opt_b  = (q.get("option_b")  or "").strip()
            opt_c  = (q.get("option_c")  or "").strip()
            opt_d  = (q.get("option_d")  or "").strip()

            if not qtxt or not all([opt_a, opt_b, opt_c, opt_d]):
                review_q.append({"question_number": qnum, "raw_text": qtxt,
                                  "reason": "Incomplete question or missing options"})
                continue

            # De-duplicate: if a question number was seen on a previous page, skip
            if qnum and qnum in seen_nums:
                continue
            if qnum:
                seen_nums.add(qnum)

            # Correct answer validation
            ans = (q.get("correct_answer") or "").strip().upper()
            if ans not in ("A", "B", "C", "D"):
                ans = None

            # Subject
            subj = (q.get("subject") or "").strip()
            if subj not in VALID_SUBJECTS:
                subj = _classify_subject(qtxt + " " + opt_a + " " + opt_b)

            # Difficulty
            diff = (q.get("difficulty") or "medium").strip().lower()
            if diff not in ("easy", "medium", "hard"):
                diff = "medium"

            # Diagram
            diag_file = None
            if q.get("has_diagram") and isinstance(q.get("diagram_bbox"), list) and len(q["diagram_bbox"]) == 4:
                fname    = f"diagram_p{pno}_q{qnum}.png"
                out_path = os.path.join(diagrams_dir, fname)
                if _crop_diagram(jpeg, q["diagram_bbox"], out_path):
                    diag_file = fname

            all_q.append({
                "question_number": qnum,
                "question":        qtxt,
                "options":         {"a": opt_a, "b": opt_b, "c": opt_c, "d": opt_d},
                "correct_answer":  ans,
                "subject_auto":    subj.lower(),
                "difficulty":      diff,
                "diagram":         diag_file,
                "has_latex":       bool(re.search(r"\$", qtxt + opt_a + opt_b + opt_c + opt_d)),
            })

        # Respect Gemini free-tier limit: 15 RPM → sleep 2 s between pages
        if pno < total - 1:
            time.sleep(2)

    return all_q, review_q


# ─────────────────────────────────────────────────────────────────────────────
# Regex fallback (text-based PDFs, no API key)
# ─────────────────────────────────────────────────────────────────────────────

def extract_with_regex(pdf_path: str, diagrams_dir: str):
    print("Regex fallback extraction (no Gemini key)…", flush=True)
    doc = fitz.open(pdf_path)
    clean_q:  list[dict] = []
    review_q: list[dict] = []

    for pno in range(len(doc)):
        page = doc.load_page(pno)

        # Save embedded images for this page
        for idx, img in enumerate(page.get_images(full=True)):
            xref = img[0]
            bi   = doc.extract_image(xref)
            fname = f"diagram_p{pno}_{idx}.{bi['ext']}"
            with open(os.path.join(diagrams_dir, fname), "wb") as fh:
                fh.write(bi["image"])

        blocks = page.get_text("blocks")
        blocks.sort(key=lambda b: (round(b[1], -1), b[0]))
        full_text = "\n".join(b[4] for b in blocks if b[6] == 0)

        q_matches = list(re.finditer(r"(?m)^(\d{1,4})[.)]\s", full_text))
        for i, m in enumerate(q_matches):
            qnum  = int(m.group(1))
            end   = q_matches[i + 1].start() if i + 1 < len(q_matches) else len(full_text)
            block = full_text[m.start():end].strip()
            body  = re.sub(r"^\d{1,4}[.)]\s*", "", block, count=1)

            # Match options: (A)/(a)/A./(a). patterns
            opt_re  = re.compile(r"(?:^|\n)\s*[\(\[]?([a-dA-D])[\)\].][ \t]+", re.MULTILINE)
            opts    = list(opt_re.finditer(body))

            if len(opts) < 4:
                review_q.append({"question_number": qnum, "raw_text": block,
                                  "reason": "Could not identify 4 options"})
                continue

            question_text = body[:opts[0].start()].strip()
            options: dict = {}
            for j, om in enumerate(opts[:4]):
                letter = om.group(1).upper()
                s = om.end()
                e = opts[j + 1].start() if j + 1 < len(opts) else len(body)
                options[letter] = body[s:e].strip()

            clean_q.append({
                "question_number": qnum,
                "question":        question_text,
                "options":         {k.lower(): v for k, v in options.items()},
                "correct_answer":  None,
                "subject_auto":    _classify_subject(question_text).lower(),
                "difficulty":      "medium",
                "diagram":         None,
                "has_latex":       False,
            })

    return clean_q, review_q


# ─────────────────────────────────────────────────────────────────────────────
# Entry point
# ─────────────────────────────────────────────────────────────────────────────

if __name__ == "__main__":
    if len(sys.argv) < 5:
        print(
            "Usage: python universal_extractor.py "
            "<pdf_path> <diagrams_dir> <clean_json> <review_json> [gemini_api_key]"
        )
        sys.exit(1)

    pdf_path     = sys.argv[1]
    diagrams_dir = sys.argv[2]
    clean_out    = sys.argv[3]
    review_out   = sys.argv[4]
    api_key      = (
        sys.argv[5]
        if len(sys.argv) > 5 and sys.argv[5].strip()
        else os.environ.get("GEMINI_API_KEY", "")
    )

    os.makedirs(diagrams_dir, exist_ok=True)

    if api_key:
        clean, review = extract_with_gemini(pdf_path, diagrams_dir, api_key)
    else:
        print("[warn] GEMINI_API_KEY not set — using regex fallback (text PDFs only).", flush=True)
        clean, review = extract_with_regex(pdf_path, diagrams_dir)

    with open(clean_out, "w", encoding="utf-8") as fh:
        json.dump(clean, fh, indent=2, ensure_ascii=False)

    with open(review_out, "w", encoding="utf-8") as fh:
        json.dump(review, fh, indent=2, ensure_ascii=False)

    print(f"Done — clean: {len(clean)}, needs-review: {len(review)}", flush=True)
