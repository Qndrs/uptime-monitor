from __future__ import annotations

import argparse
from dataclasses import dataclass
from datetime import datetime
import json
from pathlib import Path
import re
import struct
from urllib.request import Request, urlopen


ROOT = Path(__file__).resolve().parents[1]
PLUGIN_FILE = ROOT / "qndrs-availability-heartbeat-monitor.php"
LANGUAGE_DIR = ROOT / "languages"
DIST_DIR = ROOT / "dist"
TEXT_DOMAIN = "qndrs-availability-heartbeat-monitor"
PO_FILE = LANGUAGE_DIR / f"{TEXT_DOMAIN}-nl_NL.po"
MO_FILE = LANGUAGE_DIR / f"{TEXT_DOMAIN}-nl_NL.mo"
PHP_FILE = LANGUAGE_DIR / f"{TEXT_DOMAIN}-nl_NL.l10n.php"

BASE_URL = (
    "https://translate.wordpress.org/projects/wp-plugins/"
    "qndrs-availability-heartbeat-monitor/dev/nl/default/export-translations/"
)
CURRENT_URL = f"{BASE_URL}?format=po"
WAITING_URL = f"{BASE_URL}?filters%5Bstatus%5D=waiting&format=po"
STABLE_URL = (
    "https://translate.wordpress.org/projects/wp-plugins/"
    "qndrs-availability-heartbeat-monitor/stable/nl/default/export-translations/?format=po"
)

SOURCE_REPLACEMENTS = {
    "Alleen-lezen API-token gegenereerd. Kopieer het nu; het wordt niet opnieuw getoond.": (
        "Read-only API token generated. Copy it now; it will not be shown again.",
        "Alleen-lezen API-token gegenereerd. Kopieer het nu; het wordt niet opnieuw getoond.",
    ),
    "Alleen-lezen API-token ingetrokken.": (
        "Read-only API token revoked.",
        "Alleen-lezen API-token ingetrokken.",
    ),
}

HUMAN_REVIEWED_TRANSLATIONS = {
    "Authorization: Bearer your-token": "Authorization: Bearer jouw-token",
    "Configuration imported successfully.": "Configuratie succesvol geïmporteerd.",
    "Copy": "Kopiëren",
    "Copy token": "Token kopiëren",
    "Could not copy to clipboard.": "Kopiëren naar het klembord is mislukt.",
    "Paste a previously exported JSON configuration here.": (
        "Plak hier een eerder geëxporteerde JSON-configuratie."
    ),
}

@dataclass
class PoEntry:
    comments: list[str]
    msgid: str
    msgstr: str


def decode_po_line(line: str) -> str:
    start = line.find('"')
    if start < 0:
        return ""
    return json.loads(line[start:])


def parse_field(lines: list[str], directive: str) -> str | None:
    for index, line in enumerate(lines):
        if not line.startswith(f"{directive} "):
            continue
        value = decode_po_line(line)
        for continuation in lines[index + 1 :]:
            if not continuation.startswith('"'):
                break
            value += decode_po_line(continuation)
        return value
    return None


def parse_po(text: str) -> list[PoEntry]:
    entries: list[PoEntry] = []
    for block in re.split(r"(?:\r?\n){2,}", text.strip()):
        lines = block.splitlines()
        msgid = parse_field(lines, "msgid")
        if not msgid:
            continue
        msgstr = parse_field(lines, "msgstr")
        entries.append(
            PoEntry(
                comments=[line for line in lines if line.startswith("#")],
                msgid=msgid,
                msgstr=msgstr or "",
            )
        )
    return entries


def read_source(path: Path | None, url: str) -> str:
    if path is not None:
        return path.read_text(encoding="utf-8")
    request = Request(url, headers={"User-Agent": "Qndrs translation sync/1.0"})
    with urlopen(request, timeout=30) as response:
        return response.read().decode("utf-8")


def plugin_version() -> str:
    source = PLUGIN_FILE.read_text(encoding="utf-8")
    match = re.search(r"^\s*\*\s+Version:\s*([0-9]+\.[0-9]+\.[0-9]+)\s*$", source, re.MULTILINE)
    if not match:
        raise RuntimeError("Could not determine the plugin version.")
    return match.group(1)


def po_quote(value: str) -> str:
    return json.dumps(value, ensure_ascii=False)


def build_header(version: str, revision_date: str) -> dict[str, str]:
    return {
        "Project-Id-Version": f"Qndrs Availability and Heartbeat Monitor {version}",
        "Report-Msgid-Bugs-To": "",
        "PO-Revision-Date": revision_date,
        "Last-Translator": "Robert Kuunders",
        "Language-Team": "Nederlands",
        "Language": "nl_NL",
        "Plural-Forms": "nplurals=2; plural=n != 1;",
        "MIME-Version": "1.0",
        "Content-Type": "text/plain; charset=UTF-8",
        "Content-Transfer-Encoding": "8bit",
        "X-Generator": "scripts/update-translations.py",
        "X-Domain": TEXT_DOMAIN,
    }


def header_string(headers: dict[str, str]) -> str:
    return "".join(f"{key}: {value}\n" for key, value in headers.items())


def render_po(entries: list[PoEntry], headers: dict[str, str]) -> str:
    lines = ["msgid \"\"", "msgstr \"\""]
    lines.extend(po_quote(f"{key}: {value}\n") for key, value in headers.items())
    lines.append("")
    for entry in entries:
        lines.extend(entry.comments)
        lines.append(f"msgid {po_quote(entry.msgid)}")
        lines.append(f"msgstr {po_quote(entry.msgstr)}")
        lines.append("")
    return "\n".join(lines)


def php_quote(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def render_php(entries: list[PoEntry], headers: dict[str, str]) -> str:
    lines = ["<?php", "return array ("]
    for key, value in headers.items():
        lines.append(f"  {php_quote(key.lower())} => {php_quote(value)},")
    lines.extend(["  'messages' => ", "  array ("])
    for entry in sorted(entries, key=lambda item: item.msgid):
        lines.append(f"    {php_quote(entry.msgid)} => {php_quote(entry.msgstr)},")
    lines.extend(["  ),", ");", ""])
    return "\n".join(lines)


def compile_mo(entries: list[PoEntry], headers: dict[str, str]) -> bytes:
    catalog = {entry.msgid: entry.msgstr for entry in entries}
    catalog[""] = header_string(headers)
    msgids = sorted(catalog)
    originals = [msgid.encode("utf-8") for msgid in msgids]
    translations = [catalog[msgid].encode("utf-8") for msgid in msgids]
    originals_blob = b"\0".join(originals) + b"\0"
    translations_blob = b"\0".join(translations) + b"\0"
    count = len(msgids)
    originals_table_offset = 28
    translations_table_offset = originals_table_offset + count * 8
    originals_offset = translations_table_offset + count * 8
    translations_offset = originals_offset + len(originals_blob)

    originals_table: list[int] = []
    cursor = originals_offset
    for value in originals:
        originals_table.extend([len(value), cursor])
        cursor += len(value) + 1

    translations_table: list[int] = []
    cursor = translations_offset
    for value in translations:
        translations_table.extend([len(value), cursor])
        cursor += len(value) + 1

    header = struct.pack(
        "<7I",
        0x950412DE,
        0,
        count,
        originals_table_offset,
        translations_table_offset,
        0,
        0,
    )
    return b"".join(
        [
            header,
            struct.pack(f"<{len(originals_table)}I", *originals_table),
            struct.pack(f"<{len(translations_table)}I", *translations_table),
            originals_blob,
            translations_blob,
        ]
    )


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Regenerate the local Dutch catalog from WordPress.org Development translations."
    )
    parser.add_argument("--current-po", type=Path, help="Optional local export of current translations.")
    parser.add_argument("--waiting-po", type=Path, help="Optional local export of waiting translations.")
    parser.add_argument("--stable-po", type=Path, help="Optional local export of Stable translations.")
    parser.add_argument(
        "--revision-date",
        default=datetime.now().astimezone().strftime("%Y-%m-%d %H:%M%z"),
        help="PO revision date, including timezone.",
    )
    args = parser.parse_args()

    current_entries = parse_po(read_source(args.current_po, CURRENT_URL))
    waiting_entries = {
        entry.msgid: entry.msgstr
        for entry in parse_po(read_source(args.waiting_po, WAITING_URL))
        if entry.msgstr
    }
    development_translations = {
        entry.msgid: entry.msgstr for entry in current_entries if entry.msgstr
    }
    development_translations.update(waiting_entries)
    development_translations.update(HUMAN_REVIEWED_TRANSLATIONS)
    stable_entries = parse_po(read_source(args.stable_po, STABLE_URL))

    merged: list[PoEntry] = []
    seen: set[str] = set()
    for entry in current_entries:
        msgid = entry.msgid
        msgstr = development_translations.get(msgid, "")
        if msgid in SOURCE_REPLACEMENTS:
            msgid, msgstr = SOURCE_REPLACEMENTS[msgid]
        if msgid in seen:
            raise RuntimeError(f"Duplicate source string after merge: {msgid!r}")
        seen.add(msgid)
        merged.append(PoEntry(entry.comments, msgid, msgstr))

    untranslated = [entry.msgid for entry in merged if not entry.msgstr]
    if untranslated:
        raise RuntimeError(f"Catalog still has {len(untranslated)} untranslated strings: {untranslated}")

    legacy_stable_translations = {
        old_msgid: translation for old_msgid, (_, translation) in SOURCE_REPLACEMENTS.items()
    }
    stable_merged = []
    for entry in stable_entries:
        msgstr = development_translations.get(
            entry.msgid,
            legacy_stable_translations.get(entry.msgid, entry.msgstr),
        )
        stable_merged.append(PoEntry(entry.comments, entry.msgid, msgstr))
    stable_untranslated = [entry.msgid for entry in stable_merged if not entry.msgstr]
    if stable_untranslated:
        raise RuntimeError(
            f"Stable import catalog still has {len(stable_untranslated)} untranslated strings: "
            f"{stable_untranslated}"
        )

    version = plugin_version()
    headers = build_header(version, args.revision_date)
    LANGUAGE_DIR.mkdir(parents=True, exist_ok=True)
    PO_FILE.write_text(render_po(merged, headers), encoding="utf-8", newline="\n")
    MO_FILE.write_bytes(compile_mo(merged, headers))
    PHP_FILE.write_text(render_php(merged, headers), encoding="utf-8", newline="\n")
    DIST_DIR.mkdir(parents=True, exist_ok=True)
    stable_import_file = DIST_DIR / f"{TEXT_DOMAIN}-{version}-nl_NL-stable-import.po"
    stable_import_file.write_text(render_po(stable_merged, headers), encoding="utf-8", newline="\n")
    print(f"Generated complete Dutch PO, MO and PHP catalogs with {len(merged)} source strings.")
    print(f"Generated {stable_import_file} with {len(stable_merged)} Stable import strings.")


if __name__ == "__main__":
    main()
