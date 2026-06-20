#!/usr/bin/env python3
"""
ExamSphere PDF Question Extractor
Based on: backend/files/extract_questions_universal.py

No API key required. Uses:
  - pdfplumber  — layout-aware text extraction (auto single/multi-column)
  - PyMuPDF     — embedded image extraction
  - regex       — question / option / answer-key parsing
  - keywords    — subject auto-detection (Physics/Chemistry/Biology/Mathematics)

Usage:
    python extract_questions.py <pdf_path> <diagrams_dir> <clean_out.json> <review_out.json>
"""

import pdfplumber
import fitz  # PyMuPDF
import re
import json
import sys
import os
import numpy as np


# ─────────────────────────────────────────────────────────────────────────────
# Subject auto-detection (keyword scoring, no LLM)
# ─────────────────────────────────────────────────────────────────────────────

SUBJECT_KEYWORDS = {
    "biology": [
        "cell", "dna", "rna", "gene", "protein", "enzyme", "organism", "mitosis",
        "meiosis", "photosynthesis", "respiration", "ecology", "evolution",
        "chromosome", "nucleus", "mitochondria", "chloroplast", "plant", "animal",
        "bacteria", "virus", "fungi", "algae", "hormone", "neural", "neuron",
        "reflex", "blood", "heart", "lung", "kidney", "bone", "muscle", "skin",
        "reproduction", "heredity", "mendel", "ribosome", "membrane", "osmosis",
        "diffusion", "taxonomy", "kingdom", "phylum", "species", "habitat",
        "allele", "dominant", "recessive", "gamete", "zygote", "embryo",
        "synapse", "cortex", "vaccine", "antibody", "antigen",
    ],
    "chemistry": [
        "mol", "mole", "atom", "electron", "proton", "neutron", "element",
        "compound", "reaction", "acid", "base", "salt", "oxidation", "reduction",
        "bond", "orbital", "periodic", "valence", "enthalpy", "entropy",
        "equilibrium", "solution", "concentration", "ph ", "buffer", "polymer",
        "organic", "alkane", "alkene", "alkyne", "benzene", "ester", "alcohol",
        "ketone", "aldehyde", "amine", "galvanic", "electrolysis", "catalyst",
        "kinetics", "hybridization", "isomer", "titration", "molarity",
        "stoichiometry", "lattice", "ionic", "covalent", "metallic",
        "hydrogen bond", "halogens", "noble gas", "transition metal",
    ],
    "physics": [
        "velocity", "acceleration", "force", "momentum", "energy", "power",
        "work", "friction", "gravity", "field", "electric", "magnetic",
        "current", "voltage", "resistance", "wave", "frequency", "amplitude",
        "optics", "lens", "mirror", "refraction", "reflection", "diffraction",
        "thermodynamics", "heat", "temperature", "pressure", "nuclear",
        "radioactive", "photon", "quantum", "capacitor", "inductor",
        "transistor", "semiconductor", "diode", "circuit", "torque", "angular",
        "centripetal", "projectile", "dimension", "unit", "newton", "coulomb",
        "ampere", "ohm", "watt", "joule", "farad", "tesla", "weber",
    ],
    "mathematics": [
        "matrix", "determinant", "integral", "derivative", "limit", "function",
        "trigonometry", "sine", "cosine", "tangent", "logarithm", "exponential",
        "arithmetic", "geometric", "progression", "series", "permutation",
        "combination", "probability", "statistics", "mean", "median", "mode",
        "variance", "standard deviation", "vector", "scalar", "complex",
        "parabola", "ellipse", "hyperbola", "circle", "polygon", "triangle",
        "rectangle", "quadratic", "cubic", "polynomial", "linear equation",
        "inequality", "set ", "relation", "bijective", "surjective",
    ],
}


def fix_encoding(text: str) -> str:
    """Repair UTF-8 mojibake: pdfplumber sometimes reads UTF-8 bytes as CP1252,
    turning × (C3 97) into Ã— and – (E2 80 93) into â€".
    Try re-encoding as CP1252 and decoding as UTF-8; fall back to original."""
    try:
        return text.encode('cp1252').decode('utf-8')
    except (UnicodeEncodeError, UnicodeDecodeError):
        return text


def detect_subject(text: str) -> str:
    t = text.lower()
    scores = {subj: sum(1 for kw in kws if kw in t) for subj, kws in SUBJECT_KEYWORDS.items()}
    best = max(scores, key=scores.get)
    return best if scores[best] > 0 else ""


def detect_latex(text: str) -> bool:
    return bool(re.search(
        r"\$|\\frac|\\sqrt|\\sum|\\int|\\alpha|\\beta|\\gamma|\\delta"
        r"|\\theta|\\lambda|\\mu|\\pi|\\sigma|\\omega|\\times|\\pm",
        text,
    ))


# ─────────────────────────────────────────────────────────────────────────────
# STAGE 1 — Layout-aware text extraction (single & two-column)
# Ported directly from extract_questions_universal.py
# ─────────────────────────────────────────────────────────────────────────────

def detect_column_gap(page, ratio=0.75):
    width = page.width
    lines = page.extract_text_lines()
    if not lines:
        return None
    spans = [(l["x0"], l["x1"]) for l in lines if l["text"].strip()]
    if len(spans) < 6:
        return None
    candidates = np.linspace(width * 0.25, width * 0.75, 51)
    counts = [sum(1 for x0, x1 in spans if x0 < g < x1) for g in candidates]
    min_idx = int(np.argmin(counts))
    median  = float(np.median(counts))
    if median == 0:
        return None
    if counts[min_idx] <= median * ratio and counts[min_idx] <= len(spans) * 0.5:
        return float(candidates[min_idx])
    return None


def page_text(page) -> str:
    gap = detect_column_gap(page)
    if gap is None:
        return page.extract_text() or ""
    w, h = page.width, page.height
    return (page.crop((0, 0, gap, h)).extract_text() or "") + "\n" + \
           (page.crop((gap, 0, w, h)).extract_text() or "")


def extract_full_text(pdf_path):
    parts, full_pages = [], []
    with pdfplumber.open(pdf_path) as pdf:
        for pg in pdf.pages:
            parts.append(page_text(pg))
            full_pages.append(pg.extract_text() or "")
    return "\n".join(parts), full_pages


# ─────────────────────────────────────────────────────────────────────────────
# STAGE 1b — Header / footer noise removal
# ─────────────────────────────────────────────────────────────────────────────

def detect_noise(pdf_path, top=55, bot_ratio=0.93, min_recur=2):
    headers, margin_counts = set(), {}
    with pdfplumber.open(pdf_path) as pdf:
        sizes = [round(c["size"], 1) for pg in pdf.pages for c in pg.chars]
        if not sizes:
            return set(), set()
        body_size = max(set(sizes), key=sizes.count)
        for pg in pdf.pages:
            h      = pg.height
            bot    = h * bot_ratio
            gap    = detect_column_gap(pg)
            crops  = [pg] if gap is None else [
                pg.crop((0, 0, gap, h)), pg.crop((gap, 0, pg.width, h))
            ]
            pg_margin = set()
            for crop in crops:
                for line in crop.extract_text_lines():
                    txt = line["text"].strip()
                    if not txt:
                        continue
                    if line["top"] < top or line["top"] > bot:
                        pg_margin.add(txt)
                        continue
                    if any(c.isdigit() for c in txt):
                        continue
                    char_sizes = [c["size"] for c in (line.get("chars") or [])]
                    if char_sizes and sum(char_sizes) / len(char_sizes) > body_size + 1.0 and 2 < len(txt) <= 60:
                        headers.add(txt)
            for txt in pg_margin:
                margin_counts[txt] = margin_counts.get(txt, 0) + 1
    running = {txt for txt, cnt in margin_counts.items() if cnt >= min_recur}
    return headers, running


def strip_noise(text, *sets):
    for s in sets:
        for item in sorted(s, key=len, reverse=True):
            text = text.replace(item, " ")
    return text


def clean_sidebar(text):
    return "\n".join(
        l for l in text.split("\n")
        if not (len(l.strip()) <= 2 and (l.strip().isalpha() or l.strip().isdigit()))
    )


# ─────────────────────────────────────────────────────────────────────────────
# STAGE 2 — Answer key parsing
# ─────────────────────────────────────────────────────────────────────────────

def parse_answer_key(full_pages):
    combined = "\n".join(full_pages)
    for marker in ("answer key", "answers", "answer"):
        idx = combined.lower().find(marker)
        if idx != -1:
            section = combined[idx:]
            for pattern in [
                r"(\d{1,4})\.\s*\(([a-dA-D])\)",
                r"(\d{1,4})\.\s*([a-dA-D])\b",
            ]:
                matches = list(re.finditer(pattern, section))
                if len(matches) >= 3:
                    return {int(m.group(1)): m.group(2).lower() for m in matches}
    return {}


def parse_inline_answers(text):
    answers = {}
    for m in re.finditer(
        r"(?m)^(\d{1,4})\.\s.*?(?:Ans(?:wer)?\.?:?\s*\(?([a-dA-D])\)?)",
        text, re.DOTALL,
    ):
        answers[int(m.group(1))] = m.group(2).lower()
    return answers


def strip_answer_rows(text):
    pat = re.compile(r"^(\d{1,4}\.\s*\(?[a-dA-D]\)?\s*)+\d{0,4}$")
    return "\n".join(
        l for l in text.split("\n")
        if l.strip().lower() not in ("answer key", "answe", "er key", "answers")
        and not pat.match(l.strip())
    )


# ─────────────────────────────────────────────────────────────────────────────
# STAGE 2b — Question block splitting + regex parsing
# ─────────────────────────────────────────────────────────────────────────────

def split_blocks(text):
    text = strip_answer_rows(text)
    matches = list(re.compile(r"(?m)^(\d{1,4})\.\s").finditer(text))
    return [
        (int(m.group(1)), text[m.start(): (matches[i+1].start() if i+1 < len(matches) else len(text))].strip())
        for i, m in enumerate(matches)
    ]


def parse_block(qnum, block):
    body     = re.sub(r"^\d{1,4}\.\s*", "", block, count=1)
    opt_re   = re.compile(r"(?:^|\s)([a-d])\.\s*")
    opt_hits = list(opt_re.finditer(body))
    if len(opt_hits) < 4:
        return None
    q_text = body[:opt_hits[0].start()].strip()
    year_m = re.search(r"\((\d{4}[^)]*)\)\s*$", q_text)
    year   = year_m.group(1) if year_m else None
    if year_m:
        q_text = q_text[:year_m.start()].strip()
    opts, seen = {}, []
    for i, om in enumerate(opt_hits):
        letter = om.group(1)
        if letter in seen:
            continue
        seen.append(letter)
        end = opt_hits[i+1].start() if i+1 < len(opt_hits) else len(body)
        opts[letter] = re.sub(r"\s+", " ", body[om.end():end].strip())
    if not all(k in opts and opts[k] for k in "abcd"):
        return None
    q_text = re.sub(r"\s+", " ", q_text).strip()
    if not q_text or len(q_text) < 5:
        return None
    if re.search(r"[-]", q_text + "".join(opts.values())):
        return None
    return {"question_number": qnum, "question": q_text, "year": year, "options": opts}


# ─────────────────────────────────────────────────────────────────────────────
# STAGE 3 — Diagram / image extraction
# ─────────────────────────────────────────────────────────────────────────────

def extract_images(pdf_path, diagrams_dir):
    os.makedirs(diagrams_dir, exist_ok=True)
    images = []
    doc    = fitz.open(pdf_path)
    for pno in range(len(doc)):
        page = doc[pno]
        for idx, img in enumerate(page.get_images(full=True)):
            xref = img[0]
            try:
                bi = doc.extract_image(xref)
            except Exception:
                continue
            if bi.get("width", 0) < 40 or bi.get("height", 0) < 40:
                continue
            fname = f"page{pno+1}_img{idx+1}.{bi['ext']}"
            fpath = os.path.join(diagrams_dir, fname)
            with open(fpath, "wb") as fh:
                fh.write(bi["image"])
            try:
                rects = page.get_image_rects(xref)
                y_pos = rects[0].y0 if rects else None
            except Exception:
                y_pos = None
            images.append({"filename": fname, "page": pno + 1, "y": y_pos})
    doc.close()
    return images


def build_qpage_map(pdf_path):
    qmap = {}
    with pdfplumber.open(pdf_path) as pdf:
        for pno, pg in enumerate(pdf.pages):
            for line in pg.extract_text_lines():
                m = re.match(r"^(\d{1,4})\.\s", line["text"].strip())
                if m:
                    qnum = int(m.group(1))
                    if qnum not in qmap:
                        qmap[qnum] = (pno + 1, line["top"])
    return qmap


def associate_images(images, qpage_map):
    assoc = {}
    for qnum, (qpage, qy) in qpage_map.items():
        same = [img for img in images if img["page"] == qpage]
        if not same:
            continue
        with_y = [img for img in same if img["y"] is not None]
        if with_y and qy is not None:
            best = min(with_y, key=lambda img: abs(img["y"] - qy))
        else:
            best = same[0]
        assoc.setdefault(qnum, []).append(best["filename"])
    return assoc


# ─────────────────────────────────────────────────────────────────────────────
# Pipeline orchestration
# ─────────────────────────────────────────────────────────────────────────────

def run(pdf_path, diagrams_dir, clean_out, review_out):
    print(f"Opening: {pdf_path}", flush=True)

    sec_headers, running = detect_noise(pdf_path)
    col_text, full_pages = extract_full_text(pdf_path)
    col_text = strip_noise(col_text, sec_headers, running)
    col_text = clean_sidebar(col_text)

    answer_key = parse_answer_key(full_pages)
    print(f"Answer key: {len(answer_key)} entries", flush=True)
    if not answer_key:
        answer_key = parse_inline_answers(col_text)
        print(f"Inline answers: {len(answer_key)} entries", flush=True)

    blocks = split_blocks(col_text)
    print(f"Blocks found: {len(blocks)}", flush=True)

    images    = extract_images(pdf_path, diagrams_dir)
    print(f"Images extracted: {len(images)}", flush=True)
    qpage_map = build_qpage_map(pdf_path)
    img_assoc = associate_images(images, qpage_map)

    clean, review = [], []

    for qnum, block_text in blocks:
        parsed = parse_block(qnum, block_text)
        if parsed is None:
            review.append({"question_number": qnum, "raw_text": block_text,
                           "reason": "Could not parse question/options via regex"})
            continue

        ans = answer_key.get(qnum)
        if not ans or ans not in parsed["options"]:
            review.append({"question_number": qnum, "raw_text": block_text,
                           "reason": "No answer key match found",
                           "parsed_preview": parsed})
            continue

        q_fixed   = fix_encoding(parsed["question"])
        opts_fixed = {k: fix_encoding(parsed["options"][k]) for k in "abcd"}
        all_text  = q_fixed + " ".join(opts_fixed.values())
        imgs      = img_assoc.get(qnum, [])

        clean.append({
            "question_number": qnum,
            "question":        q_fixed,
            "year":            parsed.get("year"),
            "options":         opts_fixed,
            "correct_answer":  ans.upper(),
            "subject_auto":    detect_subject(all_text),
            "difficulty":      "medium",
            "diagram":         imgs[0] if imgs else None,
            "has_latex":       detect_latex(all_text),
        })

    clean.sort(key=lambda x: x["question_number"])
    review.sort(key=lambda x: x["question_number"])

    with open(clean_out,  "w", encoding="utf-8") as f:
        json.dump(clean,  f, indent=2, ensure_ascii=False)
    with open(review_out, "w", encoding="utf-8") as f:
        json.dump(review, f, indent=2, ensure_ascii=False)

    print(f"Done — clean: {len(clean)}, review: {len(review)}", flush=True)


if __name__ == "__main__":
    if len(sys.argv) != 5:
        print("Usage: python extract_questions.py <pdf_path> <diagrams_dir> <clean_out.json> <review_out.json>")
        sys.exit(1)
    run(sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4])
