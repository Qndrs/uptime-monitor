from pathlib import Path
import re
import sys


ROOT = Path(__file__).resolve().parents[1]
PLUGIN_FILE = ROOT / "uptime-monitor.php"
PO_FILE = ROOT / "languages" / "uptime-monitor-nl_NL.po"

FORBIDDEN_SOURCE_PHRASES = [
    "Net bijgewerkt",
    "Verwijderen",
    "Uptime Monitor-instellingen",
    "Instellingen opslaan",
    "Configuratiepaneel",
    "Herhaalpogingen",
    "Verzoek-timeout",
    "Uitgaande clientpings",
    "Heartbeat monitor toevoegen",
    "Ingestelde heartbeat monitors",
]

REQUIRED_DUTCH_TRANSLATIONS = {
    "Updated just now.": "Net bijgewerkt.",
    "Delete": "Verwijderen",
    "Uptime Monitor Settings": "Uptime Monitor-instellingen",
    "Save Settings": "Instellingen opslaan",
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
        return bytes(value, "utf-8").decode("unicode_escape")

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
        r"(?:__|esc_html__|esc_attr__)\(\s*'([^']+)'\s*,\s*'uptime-monitor'\s*\)",
        plugin_source,
    )

    for phrase in FORBIDDEN_SOURCE_PHRASES:
        if any(phrase in msgid for msgid in gettext_calls):
            fail(f"Dutch source msgid found: {phrase}")

    if "Description: Monitor de beschikbaarheid" in plugin_source:
        fail("plugin header description is Dutch")

    translations = parse_po(PO_FILE)
    for msgid, expected in REQUIRED_DUTCH_TRANSLATIONS.items():
        actual = translations.get(msgid)
        if actual != expected:
            fail(f"missing Dutch translation for {msgid!r}: expected {expected!r}, got {actual!r}")

    print("i18n checks passed.")


if __name__ == "__main__":
    main()
