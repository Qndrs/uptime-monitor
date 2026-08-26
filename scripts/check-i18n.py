from collections import Counter
import gettext
import json
from pathlib import Path
import re
import sys


ROOT = Path(__file__).resolve().parents[1]
PLUGIN_FILE = ROOT / "qndrs-availability-heartbeat-monitor.php"
PO_FILE = ROOT / "languages" / "qndrs-availability-heartbeat-monitor-nl_NL.po"
MO_FILE = ROOT / "languages" / "qndrs-availability-heartbeat-monitor-nl_NL.mo"

FORBIDDEN_SOURCE_PHRASES = [
    "Alleen-lezen API-token gegenereerd",
    "Alleen-lezen API-token ingetrokken",
    "Net bijgewerkt",
    "Verwijderen",
    "Qndrs Monitor-instellingen",
    "Instellingen opslaan",
    "Configuratiepaneel",
    "Herhaalpogingen",
    "Verzoek-timeout",
    "Uitgaande clientpings",
    "Heartbeat monitor toevoegen",
    "Ingestelde heartbeat monitors",
]

REQUIRED_DUTCH_TRANSLATIONS = {
    "Authorization: Bearer your-token": "Authorization: Bearer jouw-token",
    "Read-only API token generated. Copy it now; it will not be shown again.": "Alleen-lezen API-token gegenereerd. Kopieer het nu; het wordt niet opnieuw getoond.",
    "Read-only API token revoked.": "Alleen-lezen API-token ingetrokken.",
    "Updated just now.": "Zojuist geüpdatet.",
    "Delete": "Verwijderen",
    "Qndrs Monitor Settings": "Qndrs Monitor instellingen",
    "Save Settings": "Instellingen opslaan",
}

PLUGIN_HEADER_FIELDS = {
    "Plugin Name": r"^\s*\*\s+Plugin Name:\s*(.+?)\s*$",
    "Plugin URI": r"^\s*\*\s+Plugin URI:\s*(.+?)\s*$",
    "Description": r"^\s*\*\s+Description:\s*(.+?)\s*$",
    "Author": r"^\s*\*\s+Author:\s*(.+?)\s*$",
    "Author URI": r"^\s*\*\s+Author URI:\s*(.+?)\s*$",
}


def fail(message: str) -> None:
    print(f"i18n check failed: {message}", file=sys.stderr)
    raise SystemExit(1)


def parse_po(path: Path) -> dict[str, str]:
    entries: dict[str, str] = {}
    msgid: str | None = None
    msgstr: str | None = None
    state: str | None = None

    def decode_po_string(value: str) -> str:
        return json.loads(f'"{value}"')

    for line in path.read_text(encoding="utf-8").splitlines() + [""]:
        if line.startswith("msgid "):
            if msgid:
                entries[msgid] = msgstr or ""
            msgid = decode_po_string(line[7:-1])
            msgstr = ""
            state = "msgid"
        elif line.startswith("msgstr "):
            msgstr = decode_po_string(line[8:-1])
            state = "msgstr"
        elif line.startswith('"') and line.endswith('"'):
            if state == "msgid" and msgid is not None:
                msgid += decode_po_string(line[1:-1])
            elif state == "msgstr" and msgstr is not None:
                msgstr += decode_po_string(line[1:-1])
        elif not line.strip():
            if msgid:
                entries[msgid] = msgstr or ""
            msgid = None
            msgstr = None
            state = None

    return entries


def main() -> None:
    plugin_source = PLUGIN_FILE.read_text(encoding="utf-8")
    gettext_calls = re.findall(
        r"(?:__|esc_html__|esc_attr__)\(\s*'([^']+)'\s*,\s*'qndrs-availability-heartbeat-monitor'\s*\)",
        plugin_source,
    )

    for phrase in FORBIDDEN_SOURCE_PHRASES:
        if any(phrase in msgid for msgid in gettext_calls):
            fail(f"Dutch source msgid found: {phrase}")

    if "Description: Monitor de beschikbaarheid" in plugin_source:
        fail("plugin header description is Dutch")

    translations = parse_po(PO_FILE)

    source_msgids = set(gettext_calls)
    for field, pattern in PLUGIN_HEADER_FIELDS.items():
        match = re.search(pattern, plugin_source, re.MULTILINE)
        if not match:
            fail(f"plugin header field is missing: {field}")
        source_msgids.add(match.group(1))

    missing = sorted(source_msgids - translations.keys())
    obsolete = sorted(translations.keys() - source_msgids)
    untranslated = sorted(msgid for msgid in source_msgids if not translations.get(msgid))
    if missing:
        fail(f"PO is missing {len(missing)} source strings: {missing}")
    if obsolete:
        fail(f"PO contains {len(obsolete)} obsolete strings: {obsolete}")
    if untranslated:
        fail(f"PO contains {len(untranslated)} untranslated strings: {untranslated}")

    placeholder_pattern = re.compile(r"%(?:\d+\$)?[a-zA-Z]")
    for msgid in sorted(source_msgids):
        source_placeholders = Counter(placeholder_pattern.findall(msgid))
        translation_placeholders = Counter(placeholder_pattern.findall(translations[msgid]))
        if source_placeholders != translation_placeholders:
            fail(
                f"placeholder mismatch for {msgid!r}: "
                f"source={dict(source_placeholders)}, translation={dict(translation_placeholders)}"
            )

    for msgid, expected in REQUIRED_DUTCH_TRANSLATIONS.items():
        actual = translations.get(msgid)
        if actual != expected:
            fail(f"missing Dutch translation for {msgid!r}: expected {expected!r}, got {actual!r}")

    try:
        with MO_FILE.open("rb") as mo_handle:
            mo_translations = gettext.GNUTranslations(mo_handle)
    except (OSError, EOFError) as error:
        fail(f"could not load compiled MO catalog: {error}")
    for msgid, expected in translations.items():
        actual = mo_translations.gettext(msgid)
        if actual != expected:
            fail(f"compiled MO mismatch for {msgid!r}: expected {expected!r}, got {actual!r}")

    print(
        f"i18n checks passed: {len(source_msgids)} source strings have complete Dutch PO and MO translations."
    )


if __name__ == "__main__":
    main()
